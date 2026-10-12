<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application and refuses non-test databases before any
     * RefreshDatabase migration can run.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');

        TestDatabaseGuard::assertSafe(
            (string) $app['config']->get("database.connections.{$connection}.driver"),
            $app['config']->get("database.connections.{$connection}.database"),
        );

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests must not depend on a prior `npm run build`.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
