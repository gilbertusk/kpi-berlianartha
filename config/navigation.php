<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Main Navigation
    |--------------------------------------------------------------------------
    |
    | "ability" is the Gate ability that will decide visibility once role and
    | organization-scope authorization exists (Step 05). null means visible to
    | every authenticated user. Hiding a link is never authorization: every
    | route and Livewire action must enforce its own policy (ADR-0009).
    |
    */

    'main' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'ability' => null],
        ['label' => 'KPI', 'route' => 'kpi.index', 'icon' => 'chart-bar', 'ability' => null],
        ['label' => 'Approval', 'route' => 'approvals.index', 'icon' => 'check-badge', 'ability' => null],
        ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'document-chart-bar', 'ability' => null],
        ['label' => 'Administration', 'route' => 'administration.index', 'icon' => 'cog-6-tooth', 'ability' => null],
    ],

];
