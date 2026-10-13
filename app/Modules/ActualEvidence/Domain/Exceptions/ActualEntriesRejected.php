<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Domain\Exceptions;

use App\Modules\Shared\Domain\ErrorCode;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;

/**
 * One or more actual entries of a batch are invalid. Nothing of the batch
 * was saved; the row errors say what to fix.
 */
final class ActualEntriesRejected extends SafeDomainException
{
    /**
     * @param  array<string, list<string>>  $rowErrors  entry reference => safe messages
     */
    public function __construct(public readonly array $rowErrors)
    {
        parent::__construct(
            ErrorCode::VALIDATION_FAILED,
            count($rowErrors).' aktual ditolak. Tidak ada aktual yang disimpan; perbaiki baris yang ditandai lalu simpan lagi.',
            422,
            ['rule' => 'actual_entries_rejected', 'rejected' => count($rowErrors)],
        );
    }
}
