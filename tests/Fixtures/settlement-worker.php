<?php

use App\Pixelforge\Recovery;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli' || getenv('DB_DATABASE') !== 'pixelforge_test' || getenv('APP_ENV') !== 'testing') {
    exit(1);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$object = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::statement("SET application_name = 'pixelforge_race_test'");
if ($argv[1] === 'poll') {
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/*' => Http::response($object)]);
    app(Recovery::class)->run($object['metadata']['local_id']);
} else {
    config(['services.pixelforge.secret' => 'worker-test']);
    $raw = json_encode(['id' => 'evt_race', 'type' => 'generation.succeeded', 'data' => ['object' => $object]]);
    $ts = time();
    $req = Request::create('/webhooks/pixelforge', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => "t=$ts,v1=".hash_hmac('sha256', $ts.'.'.$raw, 'worker-test')], $raw);
    exit($kernel->handle($req)->getStatusCode() === 204 ? 0 : 1);
}
