<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\Integration\Domain\ConnectionStatus;

/**
 * One entry of the integration inventory (`config/integrations.php`). The
 * metadata describes the agreed contract; the runtime part (enabled, key,
 * run-as account) comes from the environment.
 */
final readonly class Connection
{
    public const MIN_KEY_LENGTH = 32;

    public const METADATA = ['system_of_record', 'direction', 'protocol', 'authentication', 'file_pattern', 'schema_version', 'schedule', 'expected_volume', 'owner', 'sla', 'retry', 'idempotency', 'reconciliation', 'error_handling', 'classification'];

    /**
     * @param  array<string, string>  $metadata
     * @param  list<string>  $controlTotals
     * @param  array<string, string>  $columnMap  vendor column => contract column
     */
    public function __construct(
        public string $key,
        public string $label,
        public ConnectionStatus $status,
        public string $statusReason,
        public ?string $adapter,
        public ?string $kind,
        public bool $enabled,
        public ?string $hmacKey,
        public ?string $previousHmacKey,
        public ?string $runAs,
        public array $controlTotals,
        public array $columnMap,
        public bool $autoCommit,
        public string $dataOwner,
        public array $metadata,
        public bool $restricted,
    ) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(string $key, array $config): self
    {
        $text = static fn (string $name, string $default = '—'): string => is_scalar($config[$name] ?? null) ? (string) $config[$name] : $default;
        $optional = static fn (string $name): ?string => is_string($config[$name] ?? null) && $config[$name] !== '' ? $config[$name] : null;
        $metadata = [];

        foreach (self::METADATA as $name) {
            $metadata[$name] = $text($name);
        }

        $map = [];

        foreach (is_array($config['column_map'] ?? null) ? $config['column_map'] : [] as $vendor => $contract) {
            if (is_string($vendor) && is_string($contract)) {
                $map[mb_strtolower(trim($vendor))] = mb_strtolower(trim($contract));
            }
        }

        $controls = array_values(array_filter(is_array($config['control_totals'] ?? null) ? $config['control_totals'] : [], 'is_string'));

        return new self(
            $key,
            $text('label', $key),
            ConnectionStatus::tryFrom($text('status')) ?? ConnectionStatus::Blocked,
            $text('status_reason', ''),
            $optional('adapter'),
            $optional('kind'),
            ($config['enabled'] ?? false) === true,
            $optional('hmac_key'),
            $optional('hmac_key_previous'),
            $optional('run_as'),
            $controls,
            $map,
            ($config['auto_commit'] ?? false) === true,
            $text('data_owner'),
            $metadata,
            str_starts_with($metadata['classification'], 'Restricted'),
        );
    }

    /**
     * Keys a manifest may be signed with: the current one, and during a
     * rotation the previous one until the sender has switched.
     *
     * @return list<string>
     */
    public function hmacKeys(): array
    {
        return array_values(array_filter([$this->hmacKey, $this->previousHmacKey], static fn (?string $key): bool => $key !== null));
    }

    public function isPipeline(): bool
    {
        return $this->adapter !== null;
    }

    /**
     * Why an enabled pipeline cannot run, or null when it can.
     */
    public function blockedBecause(): ?string
    {
        return match (true) {
            ! $this->isPipeline() => 'Bukan pipeline file.',
            ! $this->enabled => 'Koneksi belum diaktifkan.',
            $this->hmacKey === null => 'Kunci tanda tangan manifest belum disediakan di environment/secret store.',
            strlen($this->hmacKey) < self::MIN_KEY_LENGTH, $this->previousHmacKey !== null && strlen($this->previousHmacKey) < self::MIN_KEY_LENGTH => 'Kunci tanda tangan manifest terlalu pendek (minimal '.self::MIN_KEY_LENGTH.' karakter).',
            $this->runAs === null => 'Akun layanan (run-as) belum ditetapkan.',
            default => null,
        };
    }
}
