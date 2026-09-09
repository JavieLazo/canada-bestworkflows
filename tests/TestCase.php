<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Storage::fake() wipes its root on SETUP and has no teardown of its own, so the
     * last run's fake uploads always survive in storage/framework/testing/disks.
     * Clear them once per test class.
     *
     * The path is built from __DIR__ rather than storage_path() because the container
     * is already flushed by the time PHPUnit calls this.
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        (new Filesystem)->deleteDirectory(dirname(__DIR__).'/storage/framework/testing/disks');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
