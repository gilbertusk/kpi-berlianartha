<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

use InvalidArgumentException;

/**
 * The sidecar manifest of an inbound file (contract `kpi-inbound-v1`): what
 * the source says it sent, signed with the connection's shared key. The
 * file is accepted only when the signature, the checksum, and the row count
 * and control totals match.
 */
final readonly class Manifest
{
    public const CONTRACT = 'kpi-inbound-v1';

    /**
     * @param  array<string, string>  $controlTotals
     */
    private function __construct(
        public string $connection,
        public string $file,
        public string $sha256,
        public int $rows,
        public string $unit,
        public string $coverageDate,
        public string $sourceSystem,
        public string $sourceExtractedAt,
        public array $controlTotals,
        public string $signature,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['contract'] ?? null) !== self::CONTRACT) {
            throw new InvalidArgumentException('Manifest harus memakai kontrak '.self::CONTRACT.'.');
        }

        $text = static function (string $key, string $pattern) use ($data): string {
            $value = $data[$key] ?? null;

            if (! is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new InvalidArgumentException("Manifest: {$key} tidak valid.");
            }

            return $value;
        };

        $rows = $data['rows'] ?? null;

        if (! is_int($rows) || $rows < 0) {
            throw new InvalidArgumentException('Manifest: rows harus bilangan bulat tidak negatif.');
        }

        $totals = $data['control_totals'] ?? [];

        if (! is_array($totals)) {
            throw new InvalidArgumentException('Manifest: control_totals harus objek.');
        }

        $controls = [];

        foreach ($totals as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z_]{0,29}$/', $key) !== 1 || ! is_string($value) || preg_match('/^\d{1,16}(?:\.\d{1,3})?$/', $value) !== 1) {
                throw new InvalidArgumentException('Manifest: control_totals berisi kunci atau angka tidak valid (angka sebagai teks dengan titik desimal).');
            }

            $controls[$key] = $value;
        }

        return new self(
            $text('connection', '/^[a-z][a-z0-9-]{1,39}$/'),
            $text('file', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/'),
            $text('sha256', '/^[a-f0-9]{64}$/'),
            $rows,
            $text('unit', '/^[A-Z0-9][A-Z0-9-]{0,39}$/'),
            $text('coverage_date', '/^\d{4}-\d{2}-\d{2}$/'),
            $text('source_system', '/^[a-z][a-z0-9_-]{1,39}$/'),
            $text('source_extracted_at', '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/'),
            $controls,
            $text('signature', '/^[a-f0-9]{64}$/'),
        );
    }

    /**
     * The signed content: every field except the signature, in a fixed
     * order, so sender and receiver hash the same bytes.
     *
     * @param  array<mixed>  $data
     */
    public static function canonical(array $data): string
    {
        unset($data['signature']);
        ksort($data);

        if (isset($data['control_totals']) && is_array($data['control_totals'])) {
            ksort($data['control_totals']);

            // An empty object and an empty list sign the same way: {}.
            if ($data['control_totals'] === []) {
                $data['control_totals'] = new \stdClass;
            }
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function sign(array $data, string $key): string
    {
        return hash_hmac('sha256', self::canonical($data), $key);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function verify(array $data, string $key): bool
    {
        $signature = $data['signature'] ?? null;

        return is_string($signature) && hash_equals(self::sign($data, $key), $signature);
    }

    /**
     * The manifest as stored with the run: without its signature.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'contract' => self::CONTRACT,
            'connection' => $this->connection,
            'file' => $this->file,
            'sha256' => $this->sha256,
            'rows' => $this->rows,
            'unit' => $this->unit,
            'coverage_date' => $this->coverageDate,
            'source_system' => $this->sourceSystem,
            'source_extracted_at' => $this->sourceExtractedAt,
            'control_totals' => $this->controlTotals,
        ];
    }
}
