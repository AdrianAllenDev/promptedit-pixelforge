<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class Provider
{
    private function client(): PendingRequest
    {
        return Http::baseUrl('https://pixelforge.promptedit.dev/v1')->acceptJson()->connectTimeout(3)->timeout(8);
    }

    public function pricing(bool $fresh = false): array
    {
        $read = fn () => $this->client()->get('pricing')->throw()->json();
        if ($fresh) {
            $pricing = $read();
            Cache::put('pixelforge.pricing', $pricing, 30);

            return $pricing;
        }

        return Cache::remember('pixelforge.pricing', 30, $read);
    }

    private function authenticated(): PendingRequest
    {
        return $this->client()->withToken((string) config('services.pixelforge.key'));
    }

    public function submit(string $key, array $payload): Response
    {
        return $this->authenticated()->withHeaders(['Idempotency-Key' => $key])->post('generations', $payload);
    }

    public function usage(string $start, string $end): mixed
    {
        return $this->authenticated()->get('usage', ['start_date' => $start, 'end_date' => $end])->throw()->json();
    }

    public function retrieve(string $id): Response
    {
        return $this->authenticated()->get('generations/'.rawurlencode($id));
    }
}
