<?php

declare(strict_types=1);

use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnitVersion;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Database\Seeders\OrganizationReferenceSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Tests\Support\AccessFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
});

test('the PRD structure has one company, three groups/departments, and 21 teams with an effective date', function (): void {
    AccessFixtures::seedStructure();

    $snapshot = app(OrganizationDirectory::class)->snapshot(AccessFixtures::TODAY);
    $company = $snapshot->ofType(UnitType::Company);
    $departments = $snapshot->ofType(UnitType::Department);
    $teams = $snapshot->ofType(UnitType::Team);

    expect($company)->toHaveCount(1)
        ->and($company[0]->name)->toBe('PT Berlian Artha Label')
        ->and($departments)->toHaveCount(3)
        ->and($teams)->toHaveCount(21)
        ->and(OrganizationUnit::query()->where('effective_from', AccessFixtures::STRUCTURE_FROM)->count())->toBe(25);

    $teamsPerDepartment = collect($teams)->groupBy(fn ($team) => $snapshot->unit((int) $team->parentId)?->name)->map->count()->all();

    expect($teamsPerDepartment)->toEqual([
        'Commercial dan Support' => 9,
        'Accounting' => 3,
        'Produksi' => 9,
    ]);

    foreach ($departments as $department) {
        expect($department->parentId)->toBe($company[0]->id);
    }
});

test('every PRD team name is present and Design A and Design B stay separate teams', function (): void {
    AccessFixtures::seedStructure();

    $names = collect(app(OrganizationDirectory::class)->snapshot(AccessFixtures::TODAY)->ofType(UnitType::Team))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe(collect([
        'Sales', 'Customer Service', 'R&D Costing', 'Design A', 'Design B', 'Admin PPIC', 'Purchasing', 'HR', 'GA',
        'Admin Accounting', 'Account Receivable', 'Tax Invoice',
        'Woven', 'Warehouse', 'Screen Print', 'Quality Control', 'Packing Logistic', 'Logistic', 'Hot Cut', 'Hang Tag', 'Flexo Printing',
    ])->sort()->values()->all());
});

test('the structure does not exist before its effective date', function (): void {
    AccessFixtures::seedStructure();

    expect(app(OrganizationDirectory::class)->snapshot('2025-12-31')->all())->toBe([]);
});

test('reference seeding is idempotent and audited once per unit', function (): void {
    AccessFixtures::seedStructure();
    AccessFixtures::seedStructure();

    expect(OrganizationUnit::query()->count())->toBe(25)
        ->and(OrganizationUnitVersion::query()->count())->toBe(25)
        ->and(AuditLog::query()->where('action', 'organization.unit_created')->count())->toBe(25);
});

test('production seeding requires an HR-confirmed effective date', function (): void {
    Config::set('organization.reference_effective_from', null);
    App::detectEnvironment(fn (): string => 'production');

    expect(fn () => OrganizationReferenceSeeder::effectiveFrom())
        ->toThrow(RuntimeException::class, 'ORGANIZATION_REFERENCE_EFFECTIVE_FROM');
});
