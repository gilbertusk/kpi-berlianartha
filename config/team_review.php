<?php

return [

    /*
    | PRD §9.2: evidence of completion is required before an action is
    | verified "when configured". Off until the owning teams decide (DEC-009).
    */
    'completion_evidence_required' => (bool) env('TEAM_REVIEW_COMPLETION_EVIDENCE_REQUIRED', false),

    /*
    | Leave approval stays in the HR system when it is the source of truth.
    | The team page links to it once the URL is approved; nothing is built
    | here (prompt Step 11 item 6).
    */
    'hris_leave_url' => env('HRIS_LEAVE_URL'),

];
