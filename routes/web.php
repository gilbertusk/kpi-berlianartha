<?php

use App\Modules\ActualEvidence\Http\ActualImportErrorsController;
use App\Modules\ActualEvidence\Http\EvidenceDownloadController;
use App\Modules\Notification\Http\OpenNotificationController;
use App\Modules\Reporting\Http\ExportDownloadController;
use App\Modules\Scoring\Http\ScorecardExportController;
use App\Modules\Target\Http\TargetImportTemplateController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'mfa'])->group(function () {
    // Step 11 — role dashboards, notifications, team page, and daily team review.
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('notifications', 'pages::notifications.index')->name('notifications.index');
    Route::get('notifications/{notification}/open', OpenNotificationController::class)->name('notifications.open');
    Route::livewire('team', 'pages::team.index')->name('team.index');
    Route::livewire('team-review', 'pages::team-review.index')->name('team-review.index');
    Route::livewire('team-review/actions/{action}', 'pages::team-review.action')->name('team-review.action');

    // Navigation placeholders only: no data and no actions. Module features
    // and their role/organization-scope policies arrive in later steps.

    // Step 06 — KPI dictionary and versioned scorecard templates.
    Route::prefix('kpi')->name('kpi.')->group(function () {
        Route::livewire('/', 'pages::kpi.index')->name('index');
        Route::livewire('definitions', 'pages::kpi.definitions')->name('definitions');
        Route::livewire('definitions/{definition}', 'pages::kpi.definition-show')->name('definitions.show');
        Route::livewire('templates', 'pages::kpi.templates')->name('templates');
        Route::livewire('templates/{template}', 'pages::kpi.template-show')->name('templates.show');
    });

    // Step 07 — assessment periods, assignment snapshots, and targets.
    Route::prefix('periods')->name('periods.')->group(function () {
        Route::livewire('/', 'pages::periods.index')->name('index');
        Route::livewire('{period}', 'pages::periods.show')->name('show');
        Route::livewire('{period}/targets', 'pages::periods.targets')->name('targets');
        Route::livewire('{period}/targets/import', 'pages::periods.import')->name('targets.import');
        Route::get('{period}/targets/template.csv', TargetImportTemplateController::class)->name('targets.template');
    });

    // Step 08 — actuals, evidence, and staged actual import.
    Route::livewire('actuals', 'pages::actuals.index')->name('actuals.index');
    Route::prefix('periods/{period}/actuals')->name('actuals.')->group(function () {
        Route::livewire('/', 'pages::actuals.entry')->name('entry');
        Route::livewire('import', 'pages::actuals.import')->name('import');
        Route::get('import/{batch}/errors.csv', ActualImportErrorsController::class)->name('import.errors');
    });
    Route::get('evidence/{evidence}/{viewer}', EvidenceDownloadController::class)->middleware('signed')->name('evidence.download');

    // Step 14 — receivables snapshots, aging, and tax invoice queue (read model, no ledger).
    Route::livewire('accounting', 'pages::accounting.index')->name('accounting.index');

    // Step 15 — production, warehouse, and logistics batches, and the PPIC board (read model, no MES/WMS/ERP).
    Route::livewire('production', 'pages::production.index')->name('production.index');
    Route::livewire('ppic', 'pages::ppic.index')->name('ppic.index');

    // Step 16 — Quality Control: NCR and CAPA (inspections arrive as Step 15 batches).
    Route::livewire('quality', 'pages::quality.index')->name('quality.index');
    Route::livewire('quality/{ncr}', 'pages::quality.show')->name('quality.show');

    // Step 17 — integration runs, reconciliation, and the exception register.
    Route::livewire('integrations', 'pages::integrations.index')->name('integrations.index');

    // Step 13 — operational records kept as KPI source (Design revisions, costing).
    Route::livewire('operations', 'pages::operations.index')->name('operations.index');

    // Step 12 — report catalog, preview, and asynchronous exports.
    Route::livewire('reports', 'pages::reports.index')->name('reports.index');
    Route::get('reports/exports/{export}/{viewer}', ExportDownloadController::class)->middleware('signed')->name('reports.exports.download');

    // Step 10 — approval inbox, period workflow, calibration, bypass, and corrections.
    Route::livewire('approvals', 'pages::approvals.index')->name('approvals.index');
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::livewire('corrections', 'pages::approvals.corrections')->name('corrections');
        Route::livewire('bypasses', 'pages::approvals.bypasses')->name('bypasses');
        Route::livewire('periods/{period}', 'pages::approvals.period')->name('period');
        Route::livewire('reviews/{review}', 'pages::approvals.review')->name('review');
    });

    // Step 09 — scorecards, reconciliation, and calculation snapshots.
    Route::livewire('scorecards', 'pages::scorecards.index')->name('scorecards.index');
    Route::prefix('periods/{period}/scorecards')->name('scorecards.')->group(function () {
        Route::livewire('/', 'pages::scorecards.period')->name('period');
        Route::get('export.csv', ScorecardExportController::class)->name('export');
        Route::livewire('{assignment}', 'pages::scorecards.show')->name('show');
    });

    // Step 05 — identity, organization, and access administration. Every page
    // authorizes on mount and every Livewire action authorizes again.
    Route::prefix('administration')->name('administration.')->group(function () {
        Route::livewire('/', 'pages::administration.index')->name('index');
        Route::livewire('organization', 'pages::administration.organization')->name('organization');
        Route::livewire('employees', 'pages::administration.employees')->name('employees');
        Route::livewire('employees/{employee}', 'pages::administration.employee-show')->name('employees.show');
        Route::livewire('accounts', 'pages::administration.accounts')->name('accounts');
        Route::livewire('roles', 'pages::administration.roles')->name('roles');
        Route::livewire('delegations', 'pages::administration.delegations')->name('delegations');
        Route::livewire('access-review', 'pages::administration.access-review')->name('access-review');
    });
});

require __DIR__.'/settings.php';
