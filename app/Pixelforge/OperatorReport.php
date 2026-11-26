<?php

namespace App\Pixelforge;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class OperatorReport
{
    private const ZERO = ['provider_cost' => '0.000000000000', 'customer_charge' => '0.000000000000', 'profit' => '0.000000000000'];

    public function __construct(private Provider $provider) {}

    public function forRange(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $daily = [];
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $day = $date->toDateString();
            $daily[$day] = ['date' => $day] + self::ZERO;
        }

        // The provider assigns usage to its UTC completion day, including across midnight.
        $rows = DB::table('final_bills as b')->join('generations as g', 'g.id', '=', 'b.generation_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.completed_at', '>=', $start)->where('g.completed_at', '<', $end->addDay())
            ->selectRaw("(g.completed_at at time zone 'UTC')::date as day, u.id, u.name, sum(b.provider_cost) as provider_cost, sum(b.customer_charge) as customer_charge, sum(b.profit) as profit")
            ->groupBy('day', 'u.id', 'u.name')->orderBy('u.name')->orderBy('u.id')->get();
        $users = [];
        $total = self::ZERO;
        foreach ($rows as $row) {
            $users[$row->id] ??= ['id' => $row->id, 'name' => $row->name] + self::ZERO;
            foreach (array_keys(self::ZERO) as $field) {
                $daily[$row->day][$field] = (string) BigDecimal::of($daily[$row->day][$field])->plus($row->$field);
                $users[$row->id][$field] = (string) BigDecimal::of($users[$row->id][$field])->plus($row->$field);
                $total[$field] = (string) BigDecimal::of($total[$field])->plus($row->$field);
            }
        }

        $external = null;
        try {
            $usage = $this->provider->usage($start->toDateString(), $end->toDateString());
            $external = $this->dailyCosts($usage, $daily);
        } catch (HttpClientException|UnexpectedValueException $e) {
            report($e);
        }
        $providerTotal = BigDecimal::zero();
        foreach ($daily as $day => &$entry) {
            $entry['reported'] = $external[$day] ?? null;
            $entry['difference'] = $external === null ? null : (string) BigDecimal::of($entry['provider_cost'])->minus($external[$day]);
            if ($external !== null) {
                $providerTotal = $providerTotal->plus($external[$day]);
            }
        }
        unset($entry);
        $total['reported'] = $external === null ? null : (string) $providerTotal;
        $total['difference'] = $external === null ? null : (string) BigDecimal::of($total['provider_cost'])->minus($providerTotal);

        return ['daily' => array_values($daily), 'users' => array_values($users), 'total' => $total,
            'checkedAt' => now('UTC')->toIso8601String(), 'reconciled' => $external !== null];
    }

    private function dailyCosts(mixed $usage, array $days): array
    {
        $data = $usage['data'] ?? null;
        if (! is_array($data) || count($data) !== count($days)) {
            throw new UnexpectedValueException('Incomplete Pixelforge usage range.');
        }
        $costs = [];
        foreach ($data as $row) {
            $day = $row['date'] ?? null;
            $cost = $row['cost_usd'] ?? null;
            if (! is_string($day) || ! isset($days[$day]) || isset($costs[$day]) || ! is_string($cost) || ! preg_match('/^\d+(?:\.\d+)?$/D', $cost)) {
                throw new UnexpectedValueException('Invalid Pixelforge daily usage.');
            }
            $costs[$day] = $cost;
        }

        return $costs;
    }
}
