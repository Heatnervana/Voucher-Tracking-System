<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private ?string $testStoragePath = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || ! in_array($app['config']->get('database.connections.sqlite.url'), [null, ''], true)) {
            throw new RuntimeException('Tests require an uncached testing environment with SQLite :memory: and no database URL.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->testStoragePath = base_path('storage/framework/testing/phpunit-'.Str::uuid());
        (new Filesystem)->makeDirectory($this->testStoragePath.'/framework', 0755, true);
        $this->app->useStoragePath($this->testStoragePath);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->testStoragePath !== null) {
                (new Filesystem)->deleteDirectory($this->testStoragePath);
            }
        }
    }
}
