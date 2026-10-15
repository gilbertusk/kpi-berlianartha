<?php

use App\Modules\Accounting\Domain\AgingBuckets;

return [

    /*
    | The department whose units hold accounting data (PRD §4: the Accounting
    | group with Admin Accounting, Account Receivable, and Tax Invoice).
    */
    'department_code' => env('ACCOUNTING_DEPARTMENT_CODE', 'ACC'),

    /*
    | Aging buckets. The default is the set the PRD prescribes (§10.2): not
    | yet due, 1–30, 31–60, 61–90, and more than 90 days past due. A change
    | needs a business decision and a new version name, which is printed on
    | every aging report.
    */
    'aging_buckets' => AgingBuckets::PRD_DEFAULT,

];
