<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use App\Modules\Shared\Domain\ErrorCode;

final class ConcurrencyConflict extends SafeDomainException
{
    public function __construct(string $entityType, int $expectedVersion)
    {
        parent::__construct(
            ErrorCode::CONCURRENCY_CONFLICT,
            'Data telah berubah. Muat ulang halaman sebelum mencoba kembali.',
            409,
            [
                'entity_type' => $entityType,
                'expected_version' => $expectedVersion,
            ],
        );
    }
}
