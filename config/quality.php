<?php

return [

    /*
    | The team that owns the NCR and CAPA workflow (PRD §10.3: Quality
    | Control). It raises NCRs, verifies, closes, and reopens them.
    */
    'qc_unit_code' => env('QC_UNIT_CODE', 'PROD-QC'),

    /*
    | Taxonomies. Empty until the business approves them: an NCR then has no
    | severity, and defect categories and root-cause methods are accepted as
    | codes from the source (DEC-006, DEC-016). Once a list is set, only its
    | codes are accepted.
    */
    'severities' => [],

    'defect_categories' => [],

    'root_cause_methods' => [],

    /*
    | Optional CAPA parts that need approval before they are used (prompt
    | Step 16): preventive action, and an effectiveness review that must
    | find the action effective before an NCR may close.
    */
    'preventive_action_enabled' => false,

    'effectiveness_review_required' => false,

    /*
    | Stages whose completion needs an evidence reference. Verification
    | always does: an NCR never closes without evidence.
    */
    'evidence_required_stages' => ['VERIFICATION'],

];
