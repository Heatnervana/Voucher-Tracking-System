<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    private ?string $testStoragePath = null;

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
