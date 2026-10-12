<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use App\Modules\Shared\Domain\ErrorCode;

final class IdempotencyConflict extends SafeDomainException
{
    public function __construct(string $scope)
    {
        parent::__construct(
            ErrorCode::IDEMPOTENCY_CONFLICT,
            'Permintaan dengan kunci yang sama memiliki isi berbeda atau masih diproses.',
            409,
            ['scope' => $scope],
        );
    }
}
