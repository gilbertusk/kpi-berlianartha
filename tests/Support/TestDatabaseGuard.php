<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Prevents RefreshDatabase from wiping a development or shared database.
 *
 * Tests may only run against in-memory SQLite or a database whose name ends
 * with "_test" (for example the kpi_test database created by Docker Compose).
 */
final class TestDatabaseGuard
{
    public static function assertSafe(string $driver, ?string $database): void
    {
        $isSafe = $driver === 'sqlite'
            ? $database === ':memory:'
            : $database !== null && str_ends_with($database, '_test');

        if (! $isSafe) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database [%s] on driver [%s]. Use in-memory SQLite or a database named *_test.',
                $database ?? 'null',
                $driver,
            ));
        }
    }
}
