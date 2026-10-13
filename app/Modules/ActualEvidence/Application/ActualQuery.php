<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Domain\TargetValueRules;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scoped reads for actual entry and its completeness. Scope filtering
 * happens in SQL through {@see ActualAccess}.
 */
final class ActualQuery
{
    private const PER_PAGE = 25;

    /** @var array<string, string> */
    public const STATES = [
        'missing' => 'Belum ada aktual',
        'draft' => 'Draft',
        'submitted' => 'Menunggu verifikasi',
        'rejected' => 'Ditolak',
        'verified' => 'Terverifikasi',
    ];

    /**
     * Targets in scope with the entry for one observation window.
     *
     * @return LengthAwarePaginator<int, ActualRow>
     */
    public function rows(AssessmentPeriod $period, ActualAccess $access, string $windowStart, string $windowEnd, ?string $unitCode, ?string $state, string $search, int $page): LengthAwarePaginator
    {
        $query = $this->base($period, $access)->with('definitionVersion');

        if ($unitCode !== null) {
            $query->where('unit_code', $unitCode);
        }

        $inWindow = fn (Builder $actuals) => $actuals->where('window_start', $windowStart)->where('window_end', $windowEnd);

        if ($state === 'missing') {
            $query->whereNotExists(fn ($sub) => $sub->from('kpi_actuals')->whereColumn('kpi_actuals.period_target_id', 'period_targets.id')
                ->where('window_start', $windowStart)->where('window_end', $windowEnd));
        } elseif ($state !== null) {
            $query->whereExists(fn ($sub) => $sub->from('kpi_actuals')
                ->join('kpi_actual_versions', 'kpi_actual_versions.kpi_actual_id', '=', 'kpi_actuals.id')
                ->whereColumn('kpi_actuals.period_target_id', 'period_targets.id')
                ->where('window_start', $windowStart)->where('window_end', $windowEnd)
                ->where('kpi_actual_versions.status', $state));
        }

        $needle = mb_strtolower(trim($search));

        if ($needle !== '') {
            $pattern = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(kpi_code) like ?', [$pattern])
                ->orWhereRaw('lower(kpi_name) like ?', [$pattern])
                ->orWhereRaw('lower(subject_code) like ?', [$pattern])
                ->orWhereRaw('lower(subject_name) like ?', [$pattern]));
        }

        $targets = $query->orderBy('unit_code')->orderBy('subject_type')->orderBy('subject_code')->orderBy('kpi_code')->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $ids = collect($targets->items())->pluck('id')->all();
        $actuals = KpiActual::query()->whereIn('period_target_id', $ids)->tap($inWindow)->with(['versions', 'evidence'])->get()->keyBy('period_target_id');
        $counts = KpiActual::query()->whereIn('period_target_id', $ids)->selectRaw('period_target_id, count(*) as total')->groupBy('period_target_id')->pluck('total', 'period_target_id');
        $finalTargets = [];

        foreach (PeriodTargetVersion::query()->whereIn('period_target_id', $ids)->where('status', TargetStatus::Approved->value)->orderBy('version_number')->get(['period_target_id', 'target_value']) as $version) {
            $finalTargets[$version->period_target_id] = TargetValueRules::display($version->target_value);
        }

        return $targets->through(function (PeriodTarget $target) use ($actuals, $counts, $finalTargets): ActualRow {
            $actual = $actuals->get($target->id);

            return new ActualRow($target, $finalTargets[$target->id] ?? null, $actual, $actual?->versions->last(), (int) ($counts[$target->id] ?? 0));
        });
    }

    /**
     * Completeness per target inside the scope. A target is missing when it
     * has no entry at all; an entry of zero or N/A is not missing. How many
     * observation windows a target should have depends on the business
     * calendar, which is not decided (DEC-015), so windows are not counted.
     *
     * @return array{targets: int, missing: int, draft: int, submitted: int, rejected: int, verified: int, late: int, entries: int}
     */
    public function completeness(AssessmentPeriod $period, ActualAccess $access): array
    {
        $result = ['targets' => 0, 'missing' => 0, 'draft' => 0, 'submitted' => 0, 'rejected' => 0, 'verified' => 0, 'late' => 0, 'entries' => 0];
        $targetIds = $this->base($period, $access)->where('is_required', true)->select('id');
        $result['targets'] = (clone $targetIds)->count();

        $rows = KpiActual::query()
            ->join('kpi_actual_versions', 'kpi_actual_versions.kpi_actual_id', '=', 'kpi_actuals.id')
            ->whereIn('kpi_actuals.period_target_id', $targetIds)
            ->toBase()
            ->get(['kpi_actuals.period_target_id', 'kpi_actual_versions.status', 'kpi_actual_versions.is_late']);

        /** @var array<int, array<string, bool>> $perTarget */
        $perTarget = [];

        foreach ($rows as $row) {
            $result['entries']++;
            $result['late'] += (bool) $row->is_late ? 1 : 0;
            $perTarget[(int) $row->period_target_id][(string) $row->status] = true;
        }

        // A target counts once, by the least advanced state among its entries.
        foreach ($perTarget as $states) {
            foreach ([ActualStatus::Rejected, ActualStatus::Draft, ActualStatus::Submitted, ActualStatus::Verified] as $status) {
                if (isset($states[$status->value])) {
                    $result[$status->value]++;

                    break;
                }
            }
        }

        $result['missing'] = $result['targets'] - count($perTarget);

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function unitOptions(AssessmentPeriod $period, ActualAccess $access): array
    {
        return $this->base($period, $access)
            ->select(['unit_code', 'unit_name'])
            ->distinct()
            ->orderBy('unit_code')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->unit_code => "{$row->unit_code} — {$row->unit_name}"])
            ->all();
    }

    /**
     * Every entry of one target, newest window first.
     *
     * @return Collection<int, KpiActual>
     */
    public function entries(PeriodTarget $target): Collection
    {
        return KpiActual::query()
            ->where('period_target_id', $target->id)
            ->with(['versions.updatedBy', 'versions.submittedBy', 'versions.verifiedBy', 'evidence.uploadedBy'])
            ->orderByDesc('window_start')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function auditTrail(PeriodTarget $target, int $page): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'kpi_actual')
            ->whereIn('entity_id', KpiActual::query()->where('period_target_id', $target->id)->select('public_id'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'auditPage', $page);
    }

    /**
     * @return Builder<PeriodTarget>
     */
    public function base(AssessmentPeriod $period, ActualAccess $access): Builder
    {
        $query = PeriodTarget::query()->where('assessment_period_id', $period->id);
        $access->apply($query);

        return $query;
    }
}
