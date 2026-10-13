<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Main navigation
    |--------------------------------------------------------------------------
    |
    | Each item may declare an "ability" checked with Gate/can() before it is
    | rendered. Hiding an item is not authorization: every route and action
    | still checks its policy on the server (ADR-0009).
    |
    */

    'main' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'ability' => null],
        ['label' => 'KPI', 'route' => 'kpi.index', 'icon' => 'chart-bar', 'ability' => 'kpi.view'],
        ['label' => 'Periode & Target', 'route' => 'periods.index', 'icon' => 'calendar-days', 'ability' => 'periods.view'],
        ['label' => 'Aktual', 'route' => 'actuals.index', 'icon' => 'pencil-square', 'ability' => 'actuals.view'],
        ['label' => 'Scorecard', 'route' => 'scorecards.index', 'icon' => 'presentation-chart-line', 'ability' => 'scorecards.view'],
        ['label' => 'Approval', 'route' => 'approvals.index', 'icon' => 'check-badge', 'ability' => null],
        ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'document-chart-bar', 'ability' => null],
        ['label' => 'Administration', 'route' => 'administration.index', 'icon' => 'cog-6-tooth', 'ability' => 'administration.view'],
    ],

];
