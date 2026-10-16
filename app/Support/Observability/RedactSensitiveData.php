<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Database\QueryException;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Removes secrets from log entries before any handler writes them:
 *  - context and extra values under sensitive keys (`api_key`, `X-Api-Key`,
 *    `privateKey`, ... all match `api_key` / `private_key`);
 *  - `key=value`, `"key": "value"`, bearer/basic tokens, authorization and
 *    cookie header lines, and URL credentials in any text;
 *  - exceptions, which are replaced by a summary (class, filtered message,
 *    location, frames). A database error keeps only its SQLSTATE and the SQL
 *    with placeholders, because its message carries the bound values.
 */
final readonly class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[REDACTED]';

    private const MAX_FRAMES = 15;

    /** @var list<string> */
    private array $needles;

    private string $keyPattern;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        $this->needles = array_values(array_unique(array_map(self::normalize(...), $keys)));
        $this->keyPattern = implode('|', array_map(
            static fn (string $key): string => str_replace(['_', '-'], '[_-]?', preg_quote(strtolower($key), '/')),
            $keys,
        ));
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $exception = $record->context['exception'] ?? null;
        $message = $exception instanceof Throwable && $record->message === $exception->getMessage()
            ? $this->safeMessage($exception)
            : $this->text($record->message);

        return $record->with(
            message: $message,
            context: $this->walk($record->context),
            extra: $this->walk($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function walk(array $values, int $depth = 0): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->sensitive($key)) {
                $values[$key] = self::MASK;
            } elseif ($value instanceof Throwable) {
                $values[$key] = $this->summarize($value);
            } elseif (is_array($value) && $depth < 8) {
                $values[$key] = $this->walk($value, $depth + 1);
            } elseif (is_string($value)) {
                $values[$key] = $this->text($value);
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Throwable $exception): array
    {
        $summary = [
            'class' => $exception::class,
            'message' => $this->safeMessage($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
            'trace' => array_map(
                static fn (array $frame): string => ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'],
                array_slice($exception->getTrace(), 0, self::MAX_FRAMES),
            ),
        ];

        if ($exception instanceof QueryException) {
            $summary['sql'] = mb_substr($exception->getSql(), 0, 500);
        }

        if ($exception->getPrevious() !== null) {
            $summary['previous'] = $exception->getPrevious()::class;
        }

        return $summary;
    }

    private function safeMessage(Throwable $exception): string
    {
        return $exception instanceof QueryException
            ? 'Database error SQLSTATE '.$exception->getCode()
            : $this->text($exception->getMessage());
    }

    private function sensitive(string $key): bool
    {
        $key = self::normalize($key);

        foreach ($this->needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function text(string $text): string
    {
        $mask = self::MASK;
        $patterns = [
            // Whole header lines whose value is a credential.
            '/\b((?:proxy-)?authorization|(?:set-)?cookie)(\s*:\s*)[^\r\n]+/i' => '$1$2'.$mask,
            '/\b(Bearer|Basic|Token|Digest)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 '.$mask,
            // scheme://user:password@host
            '/([a-z][a-z0-9+.-]*:\/\/)[^\s\/:@]+:[^\s\/@]+@/i' => '$1'.$mask.'@',
            // key=value, key: value, "key": "value", key='value'
            '/\b([A-Za-z_-]*(?:'.$this->keyPattern.')[A-Za-z_-]*)(["\']?\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;]+)/i' => '$1$2'.$mask,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    private static function normalize(string $key): string
    {
        return str_replace(['_', '-', ' '], '', strtolower($key));
    }
}
