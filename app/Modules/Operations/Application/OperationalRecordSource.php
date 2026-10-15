<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

/**
 * Import adapter boundary. A connector for a source system (CRM, ERP,
 * design tracker) implements this and delivers records; the register
 * validates, deduplicates, and audits them like manual entries. No
 * connector exists yet: integrations wait for DEC-008 and DEC-016.
 */
interface OperationalRecordSource
{
    /**
     * Stable name stored as the record's source system.
     */
    public function key(): string;

    /**
     * @return iterable<SourceRecord>
     */
    public function records(): iterable;
}
