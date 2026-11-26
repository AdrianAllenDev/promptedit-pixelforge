<?php

namespace App\Pixelforge;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class Quotes
{
    public function __construct(private Provider $provider, private Pricing $pricing) {}

    public function offer(array $input, int $userId, bool $fresh = false): array
    {
        try {
            $pricing = $this->provider->pricing($fresh);
            $bounds = $this->pricing->quote($input, $pricing);
        } catch (\Throwable $e) {
            report($e);
            throw new HttpResponseException(response()->json(['message' => 'Pricing is unavailable. Please try again.'], 503));
        }
        $walletEnabled = (bool) config('services.pixelforge.wallets_enabled');
        $record = ['wallet_enabled' => $walletEnabled, 'input' => $input, 'user_id' => $userId, 'quoted_at' => now()->toIso8601String(), 'pricing' => $pricing, ...$bounds];

        return [...$bounds, 'wallet_enabled' => $walletEnabled, 'quote_token' => Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR))];
    }

    public function accept(string $token, array $input, int $userId): array
    {
        try {
            $quoted = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['quote_token' => 'Request a new estimate.']);
        }
        if ($quoted['user_id'] !== $userId || $quoted['input'] !== $input) {
            throw ValidationException::withMessages(['quote_token' => 'Request an estimate for these inputs.']);
        }
        $current = $this->offer($input, $userId, true);
        $new = json_decode(Crypt::decryptString($current['quote_token']), true);
        if (($quoted['wallet_enabled'] ?? false) !== $new['wallet_enabled']) {
            throw new HttpResponseException(response()->json(['message' => 'Wallet availability changed. Review the updated estimate before submitting.', 'quote' => $current], 409));
        }
        if ($new['pricing'] != $quoted['pricing']) {
            throw new HttpResponseException(response()->json(['message' => 'Pricing changed. Review this estimate and generate again.', 'quote' => $current], 409));
        }

        return $quoted;
    }
}
