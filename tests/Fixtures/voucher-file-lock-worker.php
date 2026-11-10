<?php

use App\Http\Middleware\LockVoucherFiles;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2).'/vendor/autoload.php';

foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($argv[1]);
$app->make(Kernel::class)->bootstrap();
echo "READY\n";
flush();
$response = $app->make(LockVoucherFiles::class)->handle(Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']), function (): Response {
    echo "ENTERED\n";

    return new Response;
});
echo $response->getStatusCode()."\n";
