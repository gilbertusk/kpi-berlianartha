<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Quality\Application\NcrService;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\ProductionHarness as Harness;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->qc = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-QC');
    $this->woven = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->flexo = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-FLEXO');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
    Harness::load($this->qc, BatchKind::Inspection, ['INS-1,in_process,2026-10-14 08:00,WO-100,P-1,L-7,,pcs,500,480,20,DF-WEAVE'], 'PROD-QC', '2026-10-14');
});

test('Quality Control raises an NCR from the dashboard and works it to closure on its page', function (): void {
    $dashboard = Livewire::actingAs($this->qc)->test('pages::quality.index')
        ->assertSee('Aturan mutu yang belum disetujui')
        ->assertSee('Catat NCR baru')
        ->set('form.source_type', 'inspection')
        ->set('form.source_reference', 'INS-1')
        ->set('form.responsible_unit', 'PROD-WOVEN')
        ->set('form.description', 'Benang putus')
        ->set('form.pic_user_id', (string) $this->qc->id)
        ->set('form.due_date', '2026-10-16')
        ->set('form.severity', 'major')
        ->call('raise')
        ->assertHasErrors('action');   // no severity scale is approved

    $dashboard->set('form.severity', '')->call('raise')->assertHasNoErrors();
    $ncr = QualityNcr::query()->sole();
    $dashboard->assertRedirect(route('quality.show', ['ncr' => $ncr->public_id]));

    Livewire::actingAs($this->qc)->test('pages::quality.show', ['ncr' => $ncr->public_id])
        ->assertSee('NCR-2026-0001')
        ->assertSee('Inspeksi in_process pada batch 2026-10-14 v1')
        ->set('step.next_pic_user_id', (string) $this->woven->id)
        ->set('step.next_due_date', '2026-10-20')
        ->call('advance')
        ->assertHasNoErrors();

    expect($ncr->refresh()->stage)->toBe(NcrStage::Containment);

    // The responsible team works the investigation stages from the same page.
    $woven = Livewire::actingAs($this->woven)->test('pages::quality.show', ['ncr' => $ncr->public_id])->assertSee('Selesaikan tahap Containment');

    foreach (['Lot ditahan', 'Tensioner aus', 'Ganti tensioner'] as $i => $content) {
        $woven->set('step.content', $content)->set('step.next_pic_user_id', (string) ($i === 2 ? $this->qc->id : $this->woven->id))->set('step.next_due_date', '2026-10-21')->call('advance')->assertHasNoErrors();
    }

    $woven->assertDontSee('Verifikasi dan tutup NCR');

    Livewire::actingAs($this->qc)->test('pages::quality.show', ['ncr' => $ncr->public_id])
        ->set('step.content', 'Lot berikut sesuai')
        ->call('advance')
        ->assertHasErrors('action')                 // no evidence
        ->set('step.evidence_reference', 'Foto keluhan C-1')
        ->set('restricted', true)
        ->call('advance')
        ->assertHasNoErrors()
        ->assertSee('Buka kembali NCR');

    expect($ncr->refresh()->stage)->toBe(NcrStage::Closed);

    Livewire::actingAs($this->management)->test('pages::quality.show', ['ncr' => $ncr->public_id])
        ->assertSee('Bukti terbatas')
        ->assertDontSee('Foto keluhan C-1')
        ->assertDontSee('Koreksi data NCR');

    Livewire::actingAs($this->woven)->test('pages::quality.show', ['ncr' => $ncr->public_id])->assertSee('Foto keluhan C-1');
});

test('the dashboard is scoped and other teams cannot open an NCR', function (): void {
    $ncr = app(NcrService::class)->raise(Harness::actor($this->qc), $this->qc, [
        'responsible_unit' => 'PROD-WOVEN', 'source_type' => 'inspection', 'source_reference' => 'INS-1', 'description' => 'Benang putus',
        'owner_user_id' => $this->qc->id, 'pic_user_id' => $this->qc->id, 'due_date' => '2026-10-16',
    ]);

    $this->actingAs($this->sales)->get(route('quality.index'))->assertForbidden();
    $this->actingAs($this->flexo)->get(route('quality.index'))->assertForbidden();   // no NCR in scope, no stage to work on
    $this->actingAs($this->flexo)->get(route('quality.show', ['ncr' => $ncr->public_id]))->assertForbidden();
    $this->actingAs($this->woven)->get(route('quality.index'))->assertOk()->assertSee('NCR-2026-0001')->assertDontSee('Catat NCR baru');
    $this->actingAs($this->management)->get(route('quality.show', ['ncr' => $ncr->public_id]))->assertOk()->assertDontSee('Selesaikan tahap');

    $this->travelTo(CarbonImmutable::parse('2026-10-19 03:00:00', 'UTC'));

    Livewire::actingAs($this->woven)->test('pages::quality.index')
        ->assertSee('Lewat 3 hari')
        ->set('flag', 'overdue')
        ->assertSee('NCR-2026-0001')
        ->set('flag', 'repeat')
        ->assertDontSee('NCR-2026-0001');
});
