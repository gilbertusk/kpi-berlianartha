<?php

declare(strict_types=1);

use Tests\Support\TestDatabaseGuard;

it('allows the in-memory SQLite database and *_test databases', function (string $driver, string $database): void {
    TestDatabaseGuard::assertSafe($driver, $database);

    expect(true)->toBeTrue();
})->with([
    ['sqlite', ':memory:'],
    ['pgsql', 'kpi_test'],
    ['pgsql', 'kpi_berlianartha_test'],
]);

it('refuses to run tests against a non-test database', function (string $driver, ?string $database): void {
    TestDatabaseGuard::assertSafe($driver, $database);
})->with([
    ['pgsql', 'kpi_berlianartha'],
    ['pgsql', 'kpi_test_backup'],
    ['pgsql', null],
    ['sqlite', 'database/database.sqlite'],
])->throws(RuntimeException::class);
