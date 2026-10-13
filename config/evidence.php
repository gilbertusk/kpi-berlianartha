<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evidence storage
    |--------------------------------------------------------------------------
    |
    | Private disk for evidence binaries (ADR-0004). The bucket is never
    | public: files are streamed by the application after an authorization
    | check, through a short-lived signed link bound to the requesting user.
    |
    */

    'disk' => env('EVIDENCE_DISK', 's3'),

    'link_ttl_seconds' => (int) env('EVIDENCE_LINK_TTL', 300),

    /*
    | Technical limits, not the business evidence policy (DEC-006, DEC-010).
    */
    'max_bytes' => (int) env('EVIDENCE_MAX_BYTES', 5 * 1024 * 1024),

    'max_files_per_actual' => (int) env('EVIDENCE_MAX_FILES', 5),

    /*
    |--------------------------------------------------------------------------
    | Malware scanner
    |--------------------------------------------------------------------------
    |
    | "none": no scanner; every file stays quarantined and cannot be opened.
    | "local-unscanned": local/testing only; files are usable and labelled as
    | unscanned. No antivirus adapter exists yet.
    |
    */

    'scanner' => env('EVIDENCE_SCANNER', 'none'),

];
