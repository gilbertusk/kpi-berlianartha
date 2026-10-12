<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'mfa'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Navigation placeholders only: no data and no actions. Module features
    // and their role/organization-scope policies arrive in later steps.
    Route::view('approvals', 'modules.placeholder', ['title' => 'Approval'])->name('approvals.index');
    Route::view('reports', 'modules.placeholder', ['title' => 'Reports'])->name('reports.index');

    // Step 06 — KPI dictionary and versioned scorecard templates.
    Route::prefix('kpi')->name('kpi.')->group(function () {
        Route::livewire('/', 'pages::kpi.index')->name('index');
        Route::livewire('definitions', 'pages::kpi.definitions')->name('definitions');
        Route::livewire('definitions/{definition}', 'pages::kpi.definition-show')->name('definitions.show');
        Route::livewire('templates', 'pages::kpi.templates')->name('templates');
        Route::livewire('templates/{template}', 'pages::kpi.template-show')->name('templates.show');
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
