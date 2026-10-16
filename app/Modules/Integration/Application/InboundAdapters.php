<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the adapter a connection names (`integration.adapter.<name>`).
 */
final readonly class InboundAdapters
{
    public function __construct(private Container $container) {}

    public function for(string $name): InboundAdapter
    {
        $binding = "integration.adapter.{$name}";
        $adapter = $this->container->bound($binding) ? $this->container->make($binding) : null;

        if (! $adapter instanceof InboundAdapter) {
            throw new BusinessRuleViolation('invalid_connection', 'Adapter koneksi tidak dikenal.');
        }

        return $adapter;
    }
}
