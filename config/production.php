<?php

return [

    /*
    | The department whose teams hold production data (PRD §4: Woven,
    | Warehouse, Screen Print, Quality Control, Packing Logistic, Logistic,
    | Hot Cut, Hang Tag, Flexo Printing), and the team that keeps the PPIC
    | board (Admin PPIC, in Commercial dan Support).
    */
    'department_code' => env('PRODUCTION_DEPARTMENT_CODE', 'PROD'),

    'ppic_unit_code' => env('PPIC_UNIT_CODE', 'COMSUP-ADMIN-PPIC'),

    /*
    | Which team loads which kind of source data, by team code. Warehouse,
    | Packing Logistic, and Logistic keep separate responsibilities (PRD
    | Lampiran B). Quality Control loads its inspections (Step 16).
    */
    'responsibilities' => [
        'PROD-WOVEN' => ['production'],
        'PROD-SCREEN-PRINT' => ['production'],
        'PROD-HOT-CUT' => ['production'],
        'PROD-HANG-TAG' => ['production'],
        'PROD-FLEXO' => ['production'],
        'PROD-WAREHOUSE' => ['warehouse'],
        'PROD-QC' => ['inspection'],
        'PROD-PACKING-LOGISTIC' => ['packing'],
        'PROD-LOGISTIC' => ['delivery'],
        'COMSUP-ADMIN-PPIC' => ['work_orders'],
    ],

    /*
    | Shift calendar versions (DEC-015, OPEN). Empty until Production, HR,
    | and PPIC approve the shifts, the overnight cut-off, and the operational
    | date of a shift that runs past midnight. Without a calendar no
    | shift-based data (production actuals, packing) can be imported.
    |
    | Shape of one version:
    |   ['version' => 'dec-015-v1', 'effective_from' => 'YYYY-MM-DD',
    |    'label' => '...', 'overnight_operational_date' => 'start'|'end',
    |    'shifts' => [['code' => 'S1', 'start' => 'HH:MM', 'end' => 'HH:MM'], ...]]
    */
    'shift_calendars' => [],

];
