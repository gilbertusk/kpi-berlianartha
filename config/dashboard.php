<?php

return [

    /*
    | A KPI is shown as "red" when its score in the latest calculation is
    | below this value. The threshold is a business decision that belongs
    | with the rating rules (DEC-004); until it is approved it stays null
    | and the dashboards say so instead of using a built-in value.
    | Decimal string, e.g. '60'.
    */
    'red_kpi_score_below' => env('DASHBOARD_RED_KPI_SCORE_BELOW'),

];
