<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Wallet
{
    public const PURCHASE_AMOUNTS = ['1.00', '5.00', '10.00'];

    public const MAX_HOLD_HOURS = 24;

    public static function expiredBeforeOrAt(): Carbon
    {
        return now()->subHours(self::MAX_HOLD_HOURS);
    }

    public function holdWasReleased(Generation $generation): bool
    {
        $hold = DB::table('wallet_holds')->where('generation_id', $generation->id)->first(['released_at']);

        return $hold && ($hold->released_at !== null || $generation->created_at->lte(self::expiredBeforeOrAt()));
    }

    public function balance(int $userId): array
    {
        // One snapshot keeps available, outstanding holds and debt consistent during settlement.
        $row = DB::selectOne('
            select (select coalesce(sum(amount), 0) from wallet_topups where user_id = ?) as purchased,
                   coalesce(sum(h.amount) filter (where b.generation_id is null and h.released_at is null and g.created_at > ?), 0) as held,
                   coalesce(sum(b.customer_charge), 0) as spent
              from wallet_holds h
              join generations g on g.id = h.generation_id
              left join final_bills b on b.generation_id = g.id
             where g.user_id = ?', [$userId, self::expiredBeforeOrAt(), $userId]);
        $net = BigDecimal::of($row->purchased)->minus($row->spent)->minus($row->held);

        return [
            'available' => (string) BigDecimal::max($net, 0)->toScale(12),
            'due' => (string) BigDecimal::max($net->negated(), 0)->toScale(12),
            'held' => (string) BigDecimal::of($row->held)->toScale(12),
            'spent' => (string) BigDecimal::of($row->spent)->toScale(12),
        ];
    }

    public function topUp(int $userId, string $key, string $amount): void
    {
        DB::transaction(function () use ($userId, $key, $amount) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
            $purchase = DB::table('wallet_topups')->where(['user_id' => $userId, 'purchase_key' => $key]);
            if (($existing = $purchase->value('amount')) !== null) {
                abort_unless(BigDecimal::of($existing)->isEqualTo($amount), 409, 'Purchase identifier already used for a different amount.');

                return;
            }
            DB::table('wallet_topups')->insert(['user_id' => $userId, 'purchase_key' => $key, 'amount' => $amount, 'created_at' => now()]);
        });
    }

    public function reserve(Generation $generation, string $amount): void
    {
        // Submission already holds the user lock. A rejected reservation rolls back the generation and quote.
        $balance = $this->balance($generation->user_id);
        if (BigDecimal::of($balance['due'])->isPositive() || BigDecimal::of($balance['available'])->isLessThan($amount)) {
            throw new HttpException(402, 'Insufficient available credit. Add credits to cover the estimate and any amount due.');
        }
        DB::table('wallet_holds')->insert(['generation_id' => $generation->id, 'amount' => $amount]);
    }

    public function lockForSettlement(Generation $generation): void
    {
        if (DB::table('wallet_holds')->where('generation_id', $generation->id)->exists()) {
            // The final bill closes only this hold. Serialize its posting with new reservations.
            DB::table('users')->where('id', $generation->user_id)->lockForUpdate()->firstOrFail();
        }
    }
}
