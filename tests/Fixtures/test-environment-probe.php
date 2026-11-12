<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = realpath($argv[1]);
$mode = $argv[2];
$project = realpath(dirname(__DIR__, 2));
$boundary = realpath($project.'/storage/framework/testing').DIRECTORY_SEPARATOR.'phpunit-';
if ($directory === false || ! str_starts_with($directory, $boundary) || ! is_file($directory.'/sentinel.sqlite') || ! in_array($mode, ['database', 'url', 'environment', 'cache', 'connection'], true)) {
    throw new RuntimeException('A disposable sentinel database and known probe mode are required.');
}

$environment = [
    'APP_ENV' => $mode === 'environment' ? 'production' : 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
    'APP_CONFIG_CACHE' => substr($directory, strlen($project) + 1).'/probe-config.php',
    'LARAVEL_STORAGE_PATH' => $directory,
    'DB_CONNECTION' => $mode === 'connection' ? 'mysql' : 'sqlite',
    'DB_DATABASE' => $mode === 'database' ? $directory.'/sentinel.sqlite' : ':memory:',
    'DB_URL' => $mode === 'url' ? 'sqlite:///'.$directory.'/sentinel.sqlite' : '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'null',
];
foreach ($environment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

if ($mode === 'cache') {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath($directory);
    $app->make(Kernel::class)->bootstrap();
    file_put_contents($directory.'/probe-config.php', '<?php return '.var_export($app['config']->all(), true).';');
}

$probe = new class('probe') extends TestCase
{
    use RefreshDatabase;

    public function probe(): void
    {
        $this->setUp();
    }

    protected function beforeRefreshingDatabase(): void
    {
        echo "REFRESH_REACHED\n";
        exit(0);
    }
};

try {
    $probe->probe();
    echo "BOOTED\n";
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Tests require an uncached testing environment with SQLite :memory: and no database URL.') {
        throw $exception;
    }
    echo "REJECTED\n";
}
