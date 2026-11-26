<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Settlement
{
    public function __construct(private Pricing $pricing) {}

    public function apply(array $object, ?string $expected = null): string
    {
        return DB::transaction(function () use ($object, $expected) {
            $providerId = $object['id'] ?? null;
            $local = data_get($object, 'metadata.local_id');
            $status = $object['status'] ?? null;
            if (! is_string($providerId) || ! str_starts_with($providerId, 'gen_') || ! in_array($status, ['queued', 'processing', 'succeeded', 'failed'], true)) {
                return 'unrecognized';
            }
            $query = Generation::query();
            if ($expected || Str::isUuid($local)) {
                $query->whereKey($expected ?? $local);
            } else {
                $query->where('provider_id', $providerId);
            }
            $g = $query->lockForUpdate()->first();
            if (! $g || ($local !== null && $local !== $g->id) || ($g->provider_id && $g->provider_id !== $providerId) || (! $g->provider_id && $local !== $g->id)) {
                return 'unmatched';
            }
            if (in_array($g->status, ['failed', 'succeeded'], true) && $g->status !== $status) {
                return 'ignored';
            }
            if ($g->status === 'processing' && $status === 'queued') {
                return 'ignored';
            }
            if (DB::table('final_bills')->where('generation_id', $g->id)->exists()) {
                return 'already settled';
            }
            $g->provider_id = $providerId;
            $g->status = $status;
            $g->message = null;
            if (in_array($status, ['queued', 'processing'], true)) {
                $g->recover_at = now()->addSeconds(30);
                $g->save();

                return 'updated';
            }
            $usage = $object['usage'] ?? null;
            $rates = $object['rates'] ?? null;
            $completed = $object['completed_at'] ?? null;
            try {
                if (! is_string($completed) || ! strtotime($completed)) {
                    throw new InvalidArgumentException;
                }
                $bill = $status === 'succeeded'
                    ? $this->pricing->bill($usage ?? [], $rates ?? [])
                    : ['provider_cost' => '0', 'customer_charge' => '0', 'profit' => '0'];
            } catch (InvalidArgumentException|MathException|\TypeError) {
                $g->message = 'Waiting for complete provider billing details.';
                $g->recover_at = now()->addMinute();
                $g->save();

                return 'needs recovery';
            }
            $g->completed_at = Carbon::parse($completed)->utc();
            $g->image_url = $status === 'succeeded' ? ($object['image_url'] ?? null) : null;
            $g->image_expires_at = $object['image_url_expires_at'] ?? null;
            $g->message = $status === 'failed' ? ($object['error']['message'] ?? 'Generation failed.') : null;
            $g->recover_at = null;
            $g->save();
            DB::table('final_bills')->insert([...$bill, 'generation_id' => $g->id, 'usage' => json_encode($usage), 'rates' => json_encode($rates), 'created_at' => now()]);

            return 'settled';
        });
    }

    public function reject(Generation $g, string $message): void
    {
        // Only the caller holding the generation row lock can reject an unaccepted request.
        $g->update(['status' => 'failed', 'message' => $message, 'recover_at' => null, 'completed_at' => now()]);
        DB::table('final_bills')->insert(['generation_id' => $g->id, 'provider_cost' => 0, 'customer_charge' => 0, 'profit' => 0, 'created_at' => now()]);
    }
}
