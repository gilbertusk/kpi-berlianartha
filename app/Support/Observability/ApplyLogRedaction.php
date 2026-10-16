<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Config;
use Monolog\Logger as Monolog;

/**
 * Log channel tap: every channel gets the redaction processor.
 */
final class ApplyLogRedaction
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        $keys = Config::get('observability.redact_keys', []);

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveData(array_values(array_filter(is_array($keys) ? $keys : [], 'is_string'))));
        }
    }
}
