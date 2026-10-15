<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\Operations\Domain\RevisionCategory;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/**
 * Design revisions per team and category for a date range. Only internal
 * errors are attributed to the team; external revisions are shown next to
 * them and never added in. Each count is the number of rows of the
 * "Revisi Design" report with the same filters.
 */
final class DesignRevisionAttributionReport extends OperationalRecordReport
{
    protected function typeKey(): string
    {
        return 'design_revision';
    }

    public function key(): string
    {
        return 'design_revision_attribution';
    }

    public function title(): string
    {
        return 'Atribusi revisi Design';
    }

    public function description(): string
    {
        return 'Jumlah revisi per team menurut kategori. Hanya kesalahan internal yang dibebankan ke team; revisi eksternal ditampilkan terpisah.';
    }

    public function columns(): array
    {
        $columns = ['Team', 'Dari', 'Sampai', 'Total revisi'];

        foreach (RevisionCategory::cases() as $category) {
            $columns[] = $category->label();
        }

        return [...$columns, 'Dibebankan ke team (kesalahan internal)'];
    }

    public function filters(): array
    {
        return ['unit', 'from', 'to'];
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if ($filters->from === null || $filters->to === null) {
            throw new BusinessRuleViolation('date_range_required', 'Atribusi revisi memerlukan tanggal awal dan tanggal akhir.');
        }

        return parent::authorize($user, $filters);
    }

    public function rows(ReportContext $context): iterable
    {
        $counts = [];

        foreach ($this->records($context)->with('unit')->orderBy('id')->lazyById(500) as $record) {
            $code = $record->unit->code;
            $category = (string) ($record->values()['category'] ?? '');
            $counts[$code] ??= array_fill_keys(array_map(static fn (RevisionCategory $case): string => $case->value, RevisionCategory::cases()), 0);

            if (array_key_exists($category, $counts[$code])) {
                $counts[$code][$category]++;
            }
        }

        ksort($counts);

        foreach ($counts as $code => $byCategory) {
            yield [
                $code, $context->filters->from, $context->filters->to, (string) array_sum($byCategory),
                ...array_map(static fn (int $count): string => (string) $count, array_values($byCategory)),
                (string) $byCategory[RevisionCategory::InternalError->value],
            ];
        }
    }
}
