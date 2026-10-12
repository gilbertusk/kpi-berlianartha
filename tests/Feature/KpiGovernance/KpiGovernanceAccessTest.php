<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Database\Factories\KpiDefinitionFactory;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->kpi = new KpiFixtures;

    $this->users = [
        'management' => AccessFixtures::userWithRole(Role::Management, 'BAL'),
        'department_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD'),
        'team_leader' => AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN'),
        'employee' => AccessFixtures::userWithRole(Role::Employee),
        'hr_performance' => $this->kpi->author,
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
    ];

    $this->wovenKpi = $this->kpi->publishedDefinition('ACC-WOVEN-OUTPUT', 'PROD-WOVEN');
    $this->arKpi = $this->kpi->publishedDefinition('ACC-AR-DSO', 'ACC-AR');
    $this->wovenTemplate = $this->kpi->draftTemplate('ACC-T-WOVEN', [$this->wovenKpi->id => '100'], 'PROD-WOVEN');
});

test('KPI pages allow exactly the roles with KPI governance capabilities', function (string $roleKey, int $status): void {
    $user = $this->users[$roleKey];

    foreach (['kpi.index', 'kpi.definitions', 'kpi.templates'] as $route) {
        $this->actingAs($user)->get(route($route))->assertStatus($status);
    }
})->with([
    ['management', 200],
    ['department_head', 200],
    ['team_leader', 200],
    ['hr_performance', 200],
    ['auditor', 200],
    ['employee', 403],
    ['system_admin', 403],
]);

test('KPI detail pages follow the owning unit scope', function (string $roleKey, string $target, int $status): void {
    $definition = $target === 'woven' ? $this->wovenKpi->definition : $this->arKpi->definition;

    $this->actingAs($this->users[$roleKey])
        ->get(route('kpi.definitions.show', $definition))
        ->assertStatus($status);
})->with([
    ['team_leader', 'woven', 200],
    ['team_leader', 'ar', 403],
    ['department_head', 'woven', 200],
    ['department_head', 'ar', 403],
    ['auditor', 'ar', 200],
    ['system_admin', 'woven', 403],
]);

test('the dictionary list never shows KPIs outside the viewer scope', function (): void {
    $this->actingAs($this->users['team_leader'])
        ->get(route('kpi.definitions'))
        ->assertOk()
        ->assertSee('ACC-WOVEN-OUTPUT')
        ->assertDontSee('ACC-AR-DSO');
});

test('definition usage never reveals templates outside the viewer scope', function (): void {
    $this->kpi->draftTemplate('ACC-HIDDEN-T', [$this->wovenKpi->id => '100'], 'ACC-AR');

    $this->actingAs($this->users['team_leader'])
        ->get(route('kpi.definitions.show', $this->wovenKpi->definition))
        ->assertOk()
        ->assertSee('ACC-T-WOVEN')
        ->assertDontSee('ACC-HIDDEN-T');
});

test('read-only roles cannot create, edit, publish, or retire through forged actions', function (string $roleKey): void {
    $user = $this->users[$roleKey];

    Livewire::actingAs($user)->test('pages::kpi.definitions')
        ->set('newCode', 'FORGED-1')
        ->set('newName', 'Forged')
        ->set('newUnit', AccessFixtures::unit('PROD-WOVEN')->public_id)
        ->call('create')
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::kpi.definition-show', ['definition' => $this->wovenKpi->definition])
        ->call('retire')
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::kpi.definition-show', ['definition' => $this->wovenKpi->definition])
        ->set('newVersionReason', 'forged')
        ->call('startNewVersion')
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::kpi.template-show', ['template' => $this->wovenTemplate->template])
        ->set('itemWeight', '10')
        ->call('saveHeader')
        ->assertForbidden();

    expect(KpiDefinition::query()->where('code', 'FORGED-1')->exists())->toBeFalse()
        ->and($this->wovenKpi->fresh()?->status)->toBe(LifecycleStatus::Published);
})->with(['auditor', 'management', 'team_leader', 'department_head']);

test('department-scoped HR manages KPIs and templates only inside its department', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');

    Livewire::actingAs($hrAccounting)->test('pages::kpi.definitions')
        ->set('newCode', 'OUT-OF-SCOPE')
        ->set('newName', 'Di luar scope')
        ->set('newUnit', AccessFixtures::unit('PROD-WOVEN')->public_id)
        ->call('create')
        ->assertForbidden();

    Livewire::actingAs($hrAccounting)->test('pages::kpi.definitions')
        ->set('newCode', 'ACC-COLLECTION')
        ->set('newName', 'Collection rate')
        ->set('newUnit', AccessFixtures::unit('ACC-AR')->public_id)
        ->call('create')
        ->assertHasNoErrors();

    expect(KpiDefinition::query()->where('code', 'ACC-COLLECTION')->exists())->toBeTrue();

    $this->actingAs($hrAccounting)->get(route('kpi.templates.show', $this->wovenTemplate->template))->assertForbidden();
});

test('HR edits a draft and submits it; the submitter cannot publish but a second HR can', function (): void {
    $draft = $this->kpi->draftDefinition('UI-FLOW', 'PROD-WOVEN', ['target_rule' => '']);
    $definition = $draft->definition;

    Livewire::actingAs($this->kpi->author)->test('pages::kpi.definition-show', ['definition' => $definition])
        ->set('form.target_rule', 'Target bulanan dari PPIC')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasNoErrors();

    expect($draft->fresh()?->status)->toBe(LifecycleStatus::Review);

    Livewire::actingAs($this->kpi->author)->test('pages::kpi.definition-show', ['definition' => $definition])
        ->call('publish')
        ->assertHasErrors('action');

    Livewire::actingAs($this->kpi->reviewer)->test('pages::kpi.definition-show', ['definition' => $definition])
        ->call('publish')
        ->assertHasNoErrors();

    expect($draft->fresh()?->status)->toBe(LifecycleStatus::Published)
        ->and(AuditLog::query()->where('action', 'kpi_definition.published')->where('entity_id', $definition->public_id)->value('actor_role'))->toBe('hr_performance');
});

test('the template builder shows weight validation and publishes through review', function (): void {
    $template = $this->wovenTemplate->template;

    Livewire::actingAs($this->kpi->author)->test('pages::kpi.template-show', ['template' => $template])
        ->assertSee('Total bobot 100%')
        ->call('submit')
        ->assertHasNoErrors();

    Livewire::actingAs($this->kpi->reviewer)->test('pages::kpi.template-show', ['template' => $template])
        ->call('publish')
        ->assertHasNoErrors();

    expect($this->wovenTemplate->fresh()?->status)->toBe(LifecycleStatus::Published);
});

test('the version comparison page shows changed fields and the reason', function (): void {
    $definition = $this->wovenKpi->definition;

    Livewire::actingAs($this->kpi->author)->test('pages::kpi.definition-show', ['definition' => $definition])
        ->set('newVersionReason', 'Revisi pemilik data')
        ->call('startNewVersion')
        ->assertHasNoErrors()
        ->set('form.data_owner', 'Admin Produksi')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->set('compareFrom', '1')
        ->set('compareTo', '2')
        ->assertSee('Revisi pemilik data')
        ->assertSee('Pemilik data uji')
        ->assertSee('Admin Produksi');
});

test('candidates and authored KPIs are visibly different in the dictionary', function (): void {
    KpiDefinition::factory()->create(['code' => 'CAND-TEST-01', 'origin' => KpiDefinition::ORIGIN_PRD_CANDIDATE, 'owner_unit_id' => AccessFixtures::unit('PROD-WOVEN')->id]);
    KpiDefinitionVersion::query()->create([
        'kpi_definition_id' => KpiDefinition::query()->where('code', 'CAND-TEST-01')->value('id'),
        'version_number' => 1,
        'status' => LifecycleStatus::Draft,
        'name' => 'Kandidat uji',
    ]);

    $this->actingAs($this->kpi->author)->get(route('kpi.definitions', ['asal' => 'prd_candidate']))
        ->assertOk()
        ->assertSee('CAND-TEST-01')
        ->assertSee('Kandidat PRD')
        ->assertDontSee('ACC-WOVEN-OUTPUT');

    $this->actingAs($this->kpi->author)->get(route('kpi.definitions.show', KpiDefinition::query()->where('code', 'CAND-TEST-01')->firstOrFail()))
        ->assertOk()
        ->assertSee('Kandidat dari lampiran PRD');
});

test('factory content is publishable and documents only fictitious parameters', function (): void {
    expect(KpiDefinitionFactory::completeContent()['signoff_reference'])->toStartWith('TEST-');
});
