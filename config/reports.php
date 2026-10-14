<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report exports
    |--------------------------------------------------------------------------
    |
    | Export files live on a private disk and are streamed by the application
    | through a short-lived signed link bound to the account that asked.
    |
    */

    'disk' => env('REPORT_EXPORT_DISK', 's3'),

    'link_ttl_seconds' => (int) env('REPORT_EXPORT_LINK_TTL', 300),

    /*
    | How long a generated file is kept before it is deleted. This is a
    | technical housekeeping limit, NOT the data retention policy, which is
    | undecided (DEC-010). The source records are unaffected.
    */
    'retention_hours' => (int) env('REPORT_EXPORT_RETENTION_HOURS', 72),

    /*
    | Technical limits. A PDF is laid out in memory, so large reports are
    | exported as Excel.
    */
    'max_rows' => (int) env('REPORT_EXPORT_MAX_ROWS', 100000),

    'pdf_max_rows' => (int) env('REPORT_EXPORT_PDF_MAX_ROWS', 500),

    'max_in_flight_per_user' => (int) env('REPORT_EXPORT_MAX_IN_FLIGHT', 3),

    /*
    | An export still "running" after this long belongs to a worker that died;
    | the hourly housekeeping fails it so its owner can retry. The job itself
    | times out after 75 seconds.
    */
    'stale_after_minutes' => 10,

    'preview_rows' => 50,

];
