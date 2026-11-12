<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestEnvironmentSafetyTest extends TestCase
{
    public function test_feature_tests_ignore_the_office_configuration_cache(): void
    {
        $this->assertTrue($this->app->environment('testing'), 'Feature tests must not boot the office environment.');
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertFalse($this->app->configurationIsCached());
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_unsafe_test_bootstrap_is_rejected_before_refreshing_a_database(string $mode): void
    {
        $database = storage_path('sentinel.sqlite');
        $connection = new PDO('sqlite:'.$database);
        $connection->exec('CREATE TABLE sentinel (value TEXT)');
        $connection->exec("INSERT INTO sentinel VALUES ('preserve')");
        $connection = null;
        $originalHash = hash_file('sha256', $database);
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/test-environment-probe.php'), storage_path(), $mode]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("REJECTED\n", $process->getOutput());
        $this->assertSame($originalHash, hash_file('sha256', $database), 'Unsafe test bootstrap must not migrate the sentinel database.');
    }

    /** @return array<string, array{string}> */
    public static function unsafeEnvironments(): array
    {
        return [
            'file database' => ['database'],
            'database URL override' => ['url'],
            'production environment' => ['environment'],
            'cached configuration' => ['cache'],
            'other default connection' => ['connection'],
        ];
    }

    public function test_the_composer_test_command_preserves_the_office_configuration_cache(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('config:clear', implode("\n", $composer['scripts']['test']));
    }
}
