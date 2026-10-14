<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/**
 * The filters of a report request, validated by shape before anything is
 * queried. Whether a value is inside the account's scope is decided by the
 * report itself. The canonical array is what the export stores, prints, and
 * fingerprints.
 */
final readonly class ReportFilters
{
    public const LEVELS = ['employee' => 'Karyawan', 'team' => 'Team', 'department' => 'Department', 'company' => 'Perusahaan'];

    private const PATTERNS = [
        'period' => '/^[0-9A-HJKMNP-TV-Z]{26}$/',
        'unit' => '/^[A-Z0-9][A-Z0-9._-]{0,59}$/',
        'employee' => '/^[A-Za-z0-9][A-Za-z0-9.\/_-]{0,39}$/',
        'kpi' => '/^[A-Z0-9][A-Z0-9._-]{0,59}$/',
        'status' => '/^[a-z][a-z_]{0,29}$/',
        'source' => '/^[a-z][a-z_]{0,29}$/',
        'entity' => '/^[a-z][a-z_]{0,59}$/',
        'from' => '/^\d{4}-\d{2}-\d{2}$/',
        'to' => '/^\d{4}-\d{2}-\d{2}$/',
    ];

    private function __construct(
        public ?string $period,
        public ?string $unit,
        public ?string $level,
        public ?string $employee,
        public ?string $kpi,
        public ?string $status,
        public ?string $source,
        public ?string $entity,
        public ?string $from,
        public ?string $to,
        public bool $redOnly,
    ) {}

    /**
     * @param  array<mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $allowed = [...array_keys(self::PATTERNS), 'level', 'red_only'];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new BusinessRuleViolation('invalid_filter', 'Filter laporan tidak dikenal.');
            }
        }

        $values = [];

        foreach (self::PATTERNS as $field => $pattern) {
            $value = $input[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                $values[$field] = null;

                continue;
            }

            if (in_array($field, ['unit', 'kpi'], true) && is_string($value)) {
                $value = strtoupper($value);
            }

            if (! is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new BusinessRuleViolation('invalid_filter', "Nilai filter {$field} tidak valid.");
            }

            $values[$field] = $value;
        }

        foreach (['from', 'to'] as $field) {
            if ($values[$field] !== null) {
                [$year, $month, $day] = array_map(intval(...), explode('-', $values[$field]));

                if (! checkdate($month, $day, $year)) {
                    throw new BusinessRuleViolation('invalid_filter', "Tanggal filter {$field} tidak valid.");
                }
            }
        }

        if ($values['from'] !== null && $values['to'] !== null && $values['to'] < $values['from']) {
            throw new BusinessRuleViolation('invalid_filter', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
        }

        $level = $input['level'] ?? null;
        $level = $level === '' ? null : $level;

        if ($level !== null && (! is_string($level) || ! array_key_exists($level, self::LEVELS))) {
            throw new BusinessRuleViolation('invalid_filter', 'Tingkat scorecard tidak dikenal.');
        }

        $redOnly = $input['red_only'] ?? false;

        if (! is_bool($redOnly) && ! in_array($redOnly, ['1', '0', 1, 0, ''], true)) {
            throw new BusinessRuleViolation('invalid_filter', 'Nilai filter KPI merah tidak valid.');
        }

        return new self(
            $values['period'], $values['unit'], $level, $values['employee'], $values['kpi'], $values['status'],
            $values['source'], $values['entity'], $values['from'], $values['to'], in_array($redOnly, [true, '1', 1], true),
        );
    }

    /**
     * Only the filters that are set, in a fixed order.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $values = [
            'period' => $this->period, 'unit' => $this->unit, 'level' => $this->level, 'employee' => $this->employee,
            'kpi' => $this->kpi, 'status' => $this->status, 'source' => $this->source, 'entity' => $this->entity,
            'from' => $this->from, 'to' => $this->to, 'red_only' => $this->redOnly ? '1' : null,
        ];

        return array_filter($values, static fn (?string $value): bool => $value !== null);
    }

    /**
     * Refuses a filter the report does not offer, instead of ignoring it.
     *
     * @param  list<string>  $supported
     */
    public function assertOnly(array $supported): void
    {
        foreach (array_keys($this->toArray()) as $field) {
            if (! in_array($field, $supported, true)) {
                throw new BusinessRuleViolation('invalid_filter', "Filter {$field} tidak berlaku untuk laporan ini.");
            }
        }
    }
}
