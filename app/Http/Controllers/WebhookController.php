<?php

namespace App\Http\Controllers;

use App\Pixelforge\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __invoke(Request $request, Settlement $settlement): Response
    {
        $raw = $request->getContent();
        $secret = config('services.pixelforge.secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $matched = preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $request->header('Pixelforge-Signature', ''), $parts);
        abort_unless($matched && abs(now()->timestamp - (int) $parts[1]) <= 300 && hash_equals(hash_hmac('sha256', $parts[1].'.'.$raw, $secret), $parts[2]), 400);
        $event = json_decode($raw, true);
        abort_unless(is_array($event) && is_string($event['id'] ?? null) && strlen($event['id']) <= 255 && $event['id'] !== '' && is_string($event['type'] ?? null) && strlen($event['type']) <= 255, 422);
        DB::transaction(function () use ($event, $settlement, $raw) {
            $new = DB::table('webhook_receipts')->insertOrIgnore(['event_id' => $event['id'], 'type' => $event['type'], 'payload' => $raw, 'outcome' => 'received', 'received_at' => now()]);
            if (! $new) {
                return;
            }
            $object = data_get($event, 'data.object');
            if ($event['type'] === 'ping') {
                $outcome = 'ping';
            } elseif (in_array($event['type'], ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
                abort_unless(is_array($object) && 'generation.'.($object['status'] ?? '') === $event['type'] && is_string($object['id'] ?? null), 422);
                $outcome = $settlement->apply($object);
            } else {
                $outcome = 'unrecognized';
            }
            DB::table('webhook_receipts')->where('event_id', $event['id'])->update(['outcome' => $outcome]);
        });

        return response()->noContent();
    }
}
