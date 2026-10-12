<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Audit;

use DateTimeInterface;
use JsonSerializable;
use Stringable;

final class AuditPayloadSanitizer
{
    private const REDACTED = '[REDACTED]';

    private const MAX_STRING_LENGTH = 4000;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;
            $sanitized[$key] = $this->isSensitiveKey($key)
                ? self::REDACTED
                : $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/password|secret|token|authorization|cookie|private[_-]?key|recovery[_-]?code|api[_-]?key|credential/i',
            $key,
        ) === 1;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->sanitize($value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return $this->sanitizeValue($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return $this->truncate((string) $value);
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_bool($value) || is_float($value) || is_int($value) || $value === null) {
            return $value;
        }

        return '[UNAVAILABLE]';
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH).'…[TRUNCATED]';
    }
}
