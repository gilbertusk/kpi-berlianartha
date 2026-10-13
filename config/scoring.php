<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved scorecard policies (DEC-004)
    |--------------------------------------------------------------------------
    |
    | Scorecard-level rules: late data, the weight of N/A KPIs, rounding of
    | contributions and of the final score, and rating bands. Read by
    | App\Modules\Scoring\Infrastructure\ConfiguredScorecardPolicies.
    |
    | DEC-004 is OPEN, so this list is EMPTY and official scoring is BLOCKED.
    | Add an entry only with a business sign-off reference, never as a
    | placeholder. Entries are append-only: a signed version never changes;
    | new rules are a new version with a later effective_from. See
    | docs/architecture/SCORING.md §4 for the fields.
    |
    */

    'policies' => [],

];
