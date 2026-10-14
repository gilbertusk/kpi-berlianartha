<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\Classification;
use Carbon\CarbonImmutable;

/**
 * What every export states about itself (PRD §13): the report, its filters
 * and period, when and by whom it was generated, in which timezone, under
 * which organization scope, and how it must be handled.
 */
final readonly class ExportMetadata
{
    /**
     * @param  array<string, string>  $filters  filter name => value
     */
    public function __construct(
        public string $reportTitle,
        public string $reportKey,
        public array $filters,
        public ?string $period,
        public string $timezone,
        public CarbonImmutable $generatedAt,
        public string $generatedBy,
        public string $scopeSummary,
        public Classification $classification,
        public string $exportId,
    ) {}

    /**
     * @return list<array{0: string, 1: string}> label and value, in print order
     */
    public function lines(): array
    {
        $filters = [];

        foreach ($this->filters as $name => $value) {
            $filters[] = "{$name} = {$value}";
        }

        return [
            ['Laporan', $this->reportTitle],
            ['Kode laporan', $this->reportKey],
            ['Periode', $this->period ?? 'Tidak dibatasi periode'],
            ['Filter', $filters === [] ? 'Tanpa filter tambahan' : implode('; ', $filters)],
            ['Scope organisasi', $this->scopeSummary],
            ['Dibuat pada', $this->generatedAt->setTimezone($this->timezone)->format('Y-m-d H:i:s').' ('.$this->timezone.')'],
            ['Dibuat oleh', $this->generatedBy],
            ['Template dan versi', 'Tercantum per baris pada kolom Template, Aturan scorecard, atau Versi bila berlaku'],
            ['Klasifikasi', $this->classification->label()],
            ['ID ekspor', $this->exportId],
        ];
    }
}
