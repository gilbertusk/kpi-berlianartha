<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Navigation placeholders only: no data and no actions. Module features
    // and role/organization-scope authorization arrive from Step 05 onward.
    Route::view('kpi', 'modules.placeholder', ['title' => 'KPI'])->name('kpi.index');
    Route::view('approvals', 'modules.placeholder', ['title' => 'Approval'])->name('approvals.index');
    Route::view('reports', 'modules.placeholder', ['title' => 'Reports'])->name('reports.index');
    Route::view('administration', 'modules.placeholder', ['title' => 'Administration'])->name('administration.index');
});

require __DIR__.'/settings.php';
