<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

class Recovery
{
    private const LIMIT = 8;

    public function __construct(private Provider $provider, private Settlement $settlement, private Wallet $wallet) {}

    public function run(string $id, bool $operatorRecheck = false): void
    {
        $claim = $this->claim($id, $operatorRecheck);
        if (! $claim) {
            return;
        }
        [$g, $previouslyUncertain] = $claim;
        try {
            $response = $g->provider_id ? $this->provider->retrieve($g->provider_id) : $this->provider->submit($g->id, $g->payload);
        } catch (ConnectionException) {
            $response = null;
        }
        DB::transaction(function () use ($g, $response, $previouslyUncertain, $operatorRecheck) {
            $current = Generation::whereKey($g->id)->lockForUpdate()->firstOrFail();
            if ($current->attempts !== $g->attempts || DB::table('final_bills')->where('generation_id', $g->id)->exists()) {
                return;
            }
            if ($response?->successful()) {
                $object = $response->json();
                $outcome = $this->settlement->apply(is_array($object) ? $object : [], $g->id);
                if (in_array($outcome, ['settled', 'updated', 'already settled'], true)) {
                    return;
                }
                $current->refresh();
                $code = 'incomplete_provider_response';
            } else {
                if ($current->provider_id !== $g->provider_id || (! $current->recover_at && ! $operatorRecheck)) {
                    return;
                }
                $error = $response?->json('error.code');
                $code = is_string($error) ? $error : 'unknown_outcome';
            }
            if (! $current->recover_at && ! $operatorRecheck) {
                return;
            }
            if ($g->provider_id) {
                $current->poll_failures++;
            }
            $this->recordFailure($current, $code, (int) $response?->header('Retry-After'), $previouslyUncertain, $operatorRecheck);
        });
    }

    /** @return array{Generation, bool}|null */
    private function claim(string $id, bool $operatorRecheck): ?array
    {
        return DB::transaction(function () use ($id, $operatorRecheck) {
            $g = Generation::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($operatorRecheck) {
                if ($g->recover_at || $g->review_retry_after?->isFuture() || DB::table('final_bills')->where('generation_id', $id)->exists()) {
                    return null;
                }
            } elseif (! $g->recover_at || $g->recover_at->isFuture()) {
                return null;
            }

            if (! $g->provider_id && $this->wallet->holdWasReleased($g)) {
                $g->update(['recover_at' => null, 'message' => 'Awaiting confirmation from Pixelforge.']);

                return null;
            }
            // Before the first provider ID, every claimed call was a submission. This also counts
            // writes by the previous revision after migration and during the rolling deployment.
            if (! $g->provider_id) {
                $g->submit_attempts = $g->attempts;
            }
            $budget = $g->provider_id ? $g->poll_failures : $g->submit_attempts;
            if (! $operatorRecheck && ($budget >= self::LIMIT || $g->created_at->copy()->addHour()->lte(now()))) {
                $g->update(['recover_at' => null, 'message' => 'Recovery paused. This generation needs operator review.']);

                return null;
            }

            $uncertain = $g->submission_uncertain;
            $g->attempts++;
            if (! $g->provider_id) {
                $g->submit_attempts++;
                $g->submission_uncertain = true;
            }
            // A manual recheck leases a paused row without re-enrolling it in scheduled recovery.
            if ($operatorRecheck) {
                $g->review_retry_after = now()->addMinute();
            } else {
                $g->recover_at = now()->addMinute();
            }
            $g->save();

            return [$g, $uncertain];
        });
    }

    private function recordFailure(Generation $current, string $code, int $retryAfter, bool $previouslyUncertain, bool $operatorRecheck): void
    {
        $temporary = in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true);
        $definitive = in_array($code, ['validation_failed', 'content_policy_violation', 'daily_limit_exceeded'], true);
        if (! $current->provider_id && ! $previouslyUncertain && ($definitive || ($temporary && $current->submit_attempts >= self::LIMIT))) {
            $message = match ($code) {
                'daily_limit_exceeded' => 'The provider’s daily quota is full. Try again tomorrow (UTC).',
                'content_policy_violation' => 'The provider blocked this prompt. Edit it and try again.',
                'rate_limit_exceeded','concurrency_limit_exceeded','overloaded' => 'The provider remained busy. No image was created. Please try again later.',
                default => 'The provider rejected the inputs. Check the prompt, size and reference URLs.',
            };
            $this->settlement->reject($current, $message);

            return;
        }
        $integrationIssue = ($previouslyUncertain && $definitive) || in_array($code, ['invalid_api_key', 'idempotency_key_reused', 'not_found'], true);
        $failures = $current->provider_id ? $current->poll_failures : $current->submit_attempts;
        $delay = max($retryAfter, min(300, 2 ** min($failures, 9)));
        $next = now()->addSeconds($delay);
        $deadline = $current->created_at->copy()->addHour();
        $pause = $integrationIssue || $operatorRecheck || $failures >= self::LIMIT || $deadline->lte(now());
        $current->submission_uncertain = ! $current->provider_id && ($previouslyUncertain || (! $temporary && ! $definitive));
        $current->review_retry_after = $next;
        $current->recover_at = $pause ? null : $next->min($deadline);
        $current->message = match (true) {
            $integrationIssue => 'Provider integration needs operator review.',
            $pause => 'Recovery paused. This generation needs operator review.',
            $code === 'incomplete_provider_response' => 'Waiting for complete provider billing details.',
            default => 'Waiting to confirm with the provider. Retrying automatically.',
        };
        $current->save();
    }
}
