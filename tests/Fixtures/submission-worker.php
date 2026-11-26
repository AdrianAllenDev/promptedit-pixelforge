<?php

use App\Models\User;
use App\Pixelforge\Quotes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli' || getenv('DB_DATABASE') !== 'pixelforge_test' || getenv('APP_ENV') !== 'testing') {
    exit(1);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->instance('request', Request::create('/'));
$kernel->bootstrap();
DB::statement("SET application_name = 'pixelforge_race_test'");
config(['services.pixelforge.wallets_enabled' => true]);
Http::preventStrayRequests();
$pricing = json_decode(file_get_contents(__DIR__.'/pricing.json'), true);
$submits = 0;
Http::fake(function ($request) use ($pricing, &$submits) {
    if (str_ends_with($request->url(), '/pricing')) {
        return Http::response($pricing);
    }
    $submits++;

    return Http::response(['id' => 'gen_'.$request['metadata']['local_id'], 'metadata' => $request['metadata'], 'status' => 'queued']);
});
$user = User::findOrFail((int) $argv[1]);
Auth::setUser($user);
$input = ['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.org/a', 'https://example.org/b']];
$quote = app(Quotes::class)->offer($input, $user->id);
$request = Request::create('/generations', 'POST', $input + ['quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()], [], [], ['HTTP_ACCEPT' => 'application/json']);
echo json_encode(['status' => $kernel->handle($request)->getStatusCode(), 'provider_submits' => $submits]);
