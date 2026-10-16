<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The integration inventory as read from configuration.
 */
final class ConnectionRegistry
{
    /**
     * @return array<string, Connection>
     */
    public function all(): array
    {
        $connections = [];
        $config = Config::get('integrations.connections', []);

        foreach (is_array($config) ? $config : [] as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $connections[$key] = Connection::fromArray($key, $entry);
            }
        }

        return $connections;
    }

    public function get(string $key): Connection
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Koneksi {$key} tidak dikenal.");
    }
}
