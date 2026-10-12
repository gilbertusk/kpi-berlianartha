<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use App\Modules\Shared\Domain\ErrorCode;
use RuntimeException;
use Throwable;

abstract class SafeDomainException extends RuntimeException
{
    /**
     * @param  array<string, bool|float|int|string|null>  $safeContext
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        private readonly string $safeMessage,
        private readonly int $status,
        private readonly array $safeContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode->value, 0, $previous);
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function safeContext(): array
    {
        return $this->safeContext;
    }
}
