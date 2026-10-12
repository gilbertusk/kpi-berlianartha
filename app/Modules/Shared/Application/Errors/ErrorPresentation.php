<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Errors;

use App\Modules\Shared\Domain\ErrorCode;

final readonly class ErrorPresentation
{
    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public ErrorCode $code,
        public string $message,
        public int $httpStatus,
        public array $context = [],
    ) {}
}
