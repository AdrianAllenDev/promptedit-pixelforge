<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

class Recovery
{
    public function __construct(private Provider $provider, private Settlement $settlement) {}

    public function run(string $id): void
    {
        $claim = DB::transaction(function () use ($id) {
            $g = Generation::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $g->recover_at || $g->recover_at->isFuture()) {
                return null;
            }
            if ($g->attempts >= 8) {
                $g->update(['recover_at' => null, 'message' => 'Recovery paused. This generation needs operator review.']);

                return null;
            }
            // Persist the lease before the network call so another worker can recover a crashed submitter.
            $uncertain = $g->submission_uncertain;
            $g->update(['attempts' => $g->attempts + 1, 'recover_at' => now()->addMinute(), 'submission_uncertain' => ! $g->provider_id]);

            return [$g, $uncertain];
        });
        if (! $claim) {
            return;
        }
        [$g, $previouslyUncertain] = $claim;
        try {
            $response = $g->provider_id ? $this->provider->retrieve($g->provider_id) : $this->provider->submit($g->id, $g->payload);
            if ($response->successful()) {
                $this->settlement->apply($response->json() ?? [], $g->id);

                return;
            }
            $code = $response->json('error.code', 'provider_error');
            $retryAfter = max(0, (int) $response->header('Retry-After'));
        } catch (ConnectionException) {
            $code = 'unknown_outcome';
            $retryAfter = 0;
        }
        DB::transaction(function () use ($g, $code, $retryAfter, $previouslyUncertain) {
            $current = Generation::whereKey($g->id)->lockForUpdate()->firstOrFail();
            if (! $current->recover_at || $current->provider_id !== $g->provider_id || $current->attempts !== $g->attempts) {
                return;
            }
            $temporary = in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true);
            $definitive = in_array($code, ['validation_failed', 'content_policy_violation', 'daily_limit_exceeded'], true);
            if (! $current->provider_id && ! $previouslyUncertain && ($definitive || ($temporary && $current->attempts >= 8))) {
                $message = match ($code) {
                    'daily_limit_exceeded' => 'The provider’s daily quota is full. Try again tomorrow (UTC).',
                    'content_policy_violation' => 'The provider blocked this prompt. Edit it and try again.',
                    'rate_limit_exceeded','concurrency_limit_exceeded','overloaded' => 'The provider remained busy. No image was created. Please try again later.',
                    default => 'The provider rejected the inputs. Check the prompt, size and reference URLs.',
                };
                $this->settlement->reject($current, $message);

                return;
            }
            $pause = ($previouslyUncertain && $definitive) || in_array($code, ['invalid_api_key', 'idempotency_key_reused', 'not_found'], true);
            $current->update([
                'submission_uncertain' => $previouslyUncertain || (! $current->provider_id && ! $temporary && ! $definitive),
                'message' => $pause ? 'Provider integration needs operator review.' : 'Waiting to confirm with the provider. Retrying automatically.',
                'recover_at' => $pause ? null : now()->addSeconds(max($retryAfter, min(300, 2 ** $current->attempts))),
            ]);
        });
    }
}
