<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reference structure effective date
    |--------------------------------------------------------------------------
    |
    | Date from which the PRD §4 structure (3 groups/departments, 21 teams) is
    | effective when seeded. The default is the PRD v0.1 date and is only a
    | placeholder: HR Performance must confirm the real date. Production seeding
    | refuses to run unless the variable is set explicitly.
    |
    */

    'reference_effective_from' => env('ORGANIZATION_REFERENCE_EFFECTIVE_FROM'),

    'reference_effective_from_default' => '2026-10-12',

];
