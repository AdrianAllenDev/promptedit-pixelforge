<?php

// Test-only router for PHP's local web server. All Pixelforge traffic is simulated.
use App\Pixelforge\Pricing;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'pixelforge_test') {
    exit(1);
}
$public = realpath(__DIR__.'/../../public');
$asset = realpath($public.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
Http::preventStrayRequests();
Http::fake(function (ClientRequest $request) use ($pricing) {
    if ($request->url() === 'https://pixelforge.promptedit.dev/v1/pricing') {
        return Http::response($pricing);
    }
    if (str_starts_with($request->url(), 'https://pixelforge.promptedit.dev/v1/usage?')) {
        $days = [];
        foreach (CarbonPeriod::create($request['start_date'], $request['end_date']) as $day) {
            $days[] = ['date' => $day->toDateString(), 'cost_usd' => '0.000000000'];
        }

        return Http::response(['data' => $days]);
    }
    if ($request->method() === 'POST' && str_ends_with($request->url(), '/generations')) {
        if (str_contains($request['prompt'], '[review]')) {
            return Http::response(['error' => ['code' => 'invalid_api_key']], 401);
        }
        $failed = str_contains($request['prompt'], '[fail]');

        return Http::response([
            'id' => 'gen_'.$request['metadata']['local_id'], 'metadata' => $request['metadata'],
            'status' => $failed ? 'failed' : 'succeeded', 'completed_at' => now()->toISOString(),
            'rates' => $pricing['rates'], 'usage' => array_combine(Pricing::CATEGORIES, [7, 0, 1100, 201]),
            'image_url_expires_at' => now()->subMinute()->toISOString(),
            'error' => $failed ? ['message' => 'Generation failed. You were not charged.'] : null,
        ], 202);
    }
    throw new RuntimeException('Unexpected browser-test provider request.');
});
$app->handleRequest(Request::capture());
