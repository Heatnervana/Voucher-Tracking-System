<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FreshSetupGuardTest extends TestCase
{
    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath !== null) {
            (new Filesystem)->deleteDirectory($this->fixturePath);
        }

        parent::tearDown();
    }

    public function test_setup_checks_the_checkout_before_installing_dependencies(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('@php bootstrap/guard-setup.php', $composer['scripts']['setup'][0]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_guard_allows_a_fresh_checkout_without_changing_it(bool $emptyDatabaseExists): void
    {
        $directory = $this->createFixture();
        if ($emptyDatabaseExists) {
            touch($directory.'/database/database.sqlite');
        }

        $process = $this->runGuard($directory);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($directory.'/.env');
        $this->assertFileDoesNotExist($directory.'/bootstrap/cache/config.php');
        $this->assertSame($emptyDatabaseExists, file_exists($directory.'/database/database.sqlite'));
        if ($emptyDatabaseExists) {
            $this->assertSame(0, filesize($directory.'/database/database.sqlite'));
        }
    }

    #[TestWith(['.env', ''])]
    #[TestWith(['bootstrap/cache/config.php', '<?php throw new RuntimeException("Do not load cached settings.");'])]
    #[TestWith(['database/database.sqlite', 'Existing database must remain untouched.'])]
    public function test_guard_refuses_existing_installation_files_without_changing_them(string $path, string $contents): void
    {
        $directory = $this->createFixture();
        file_put_contents($directory.'/'.$path, $contents);

        $process = $this->runGuard($directory);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Setup requires a fresh checkout', $process->getErrorOutput());
        $this->assertSame($contents, file_get_contents($directory.'/'.$path));
    }

    #[TestWith(['APP_KEY'])]
    #[TestWith(['APP_ENV'])]
    #[TestWith(['DB_CONNECTION'])]
    #[TestWith(['DB_DATABASE'])]
    #[TestWith(['DB_URL'])]
    #[TestWith(['APP_CONFIG_CACHE'])]
    #[TestWith(['APP_PACKAGES_CACHE'])]
    #[TestWith(['APP_SERVICES_CACHE'])]
    #[TestWith(['APP_EVENTS_CACHE'])]
    #[TestWith(['APP_ROUTES_CACHE'])]
    #[TestWith(['LARAVEL_STORAGE_PATH'])]
    public function test_guard_refuses_inherited_settings_without_disclosing_their_values(string $name): void
    {
        $directory = $this->createFixture();
        $value = 'synthetic-value-that-must-not-appear';

        $process = $this->runGuard($directory, [$name => $value]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($name, $process->getErrorOutput());
        $this->assertStringNotContainsString($value, $process->getOutput().$process->getErrorOutput());
        $this->assertFileDoesNotExist($directory.'/.env');
        $this->assertFileDoesNotExist($directory.'/database/database.sqlite');
    }

    private function createFixture(): string
    {
        $guard = dirname(__DIR__, 2).'/bootstrap/guard-setup.php';
        $this->assertFileExists($guard, 'Fresh setup must provide its standalone safety guard.');
        $this->fixturePath = dirname(__DIR__, 2).'/storage/framework/testing/setup-'.bin2hex(random_bytes(8));
        mkdir($this->fixturePath.'/bootstrap/cache', 0755, true);
        mkdir($this->fixturePath.'/database');
        copy($guard, $this->fixturePath.'/bootstrap/guard-setup.php');

        return $this->fixturePath;
    }

    /** @param array<string, string> $environment */
    private function runGuard(string $directory, array $environment = []): Process
    {
        $environment += array_fill_keys([
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE', 'LARAVEL_STORAGE_PATH',
            'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_EVENTS_CACHE', 'APP_ROUTES_CACHE',
        ], false);
        $process = new Process([PHP_BINARY, $directory.'/bootstrap/guard-setup.php'], $directory, $environment);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }
}
