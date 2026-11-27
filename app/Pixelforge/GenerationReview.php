<?php

namespace App\Pixelforge;

use App\Models\Admin;
use App\Models\Generation;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerationReview
{
    private function unresolved(): Builder
    {
        return DB::table('generations as g')
            ->leftJoin('wallet_holds as h', 'h.generation_id', '=', 'g.id')
            ->leftJoin('final_bills as b', 'b.generation_id', '=', 'g.id')
            ->whereNull('b.generation_id')
            ->where(function ($query) {
                $query->whereNull('g.recover_at')
                    ->orWhere(fn ($q) => $q->where('g.submission_uncertain', true)->whereNotNull('g.message'))
                    ->orWhereIn('g.status', ['succeeded', 'failed'])
                    ->orWhereNotNull('h.released_at')
                    ->orWhere('g.created_at', '<=', Wallet::expiredBeforeOrAt());
            });
    }

    public function cases(): Paginator
    {
        return $this->unresolved()
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('admins as a', 'a.id', '=', 'h.released_by')
            ->select('g.id', 'g.provider_id', 'g.created_at', 'g.status', 'g.message', 'g.attempts', 'g.recover_at',
                'u.email as customer', 'h.amount', 'h.released_at', 'h.release_reason', 'a.email as reviewer')
            ->orderBy('g.created_at')->orderBy('g.id')->simplePaginate(25)
            ->through(function ($case) {
                $submitted = Carbon::parse($case->created_at)->utc();
                $case->created_at = $submitted->toDateTimeString();
                $case->deadline = $submitted->copy()->addHours(Wallet::MAX_HOLD_HOURS)->toDateTimeString();
                $case->hold_state = match (true) {
                    $case->amount === null => 'none',
                    $case->released_at !== null => 'released',
                    $submitted->lte(Wallet::expiredBeforeOrAt()) => 'expired',
                    default => 'held',
                };

                return $case;
            });
    }

    public function release(string $id, Admin $admin, string $reason): void
    {
        DB::transaction(function () use ($id, $admin, $reason) {
            // Match settlement's generation-then-user lock order. Recheck eligibility after acquiring the locks.
            $generation = Generation::whereKey($id)->lockForUpdate()->firstOrFail();
            DB::table('users')->where('id', $generation->user_id)->lockForUpdate()->firstOrFail();
            $hold = DB::table('wallet_holds')->where('generation_id', $id);
            if ($hold->value('released_at') !== null) {
                return;
            }
            if (! $this->unresolved()->where('g.id', $id)->whereNotNull('h.amount')->exists()) {
                throw ValidationException::withMessages(['reason' => 'This generation has no unresolved hold to release.']);
            }
            if ($generation->created_at->lte(Wallet::expiredBeforeOrAt())) {
                return;
            }
            $hold->update(['released_at' => now(), 'released_by' => $admin->id, 'release_reason' => $reason]);
            if (! $generation->provider_id) {
                $generation->update(['recover_at' => null, 'message' => 'Awaiting confirmation from Pixelforge.']);
            }
        });
    }
}
