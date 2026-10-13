<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scoped reads for the target matrix, the completeness indicator, and the
 * history of one target. Scope filtering happens in SQL.
 */
final class TargetQuery
{
    private const PER_PAGE = 25;

    /** @var array<string, string> */
    public const STATES = [
        'missing' => 'Belum diisi',
        'draft' => 'Draft',
        'submitted' => 'Menunggu persetujuan',
        'approved' => 'Final',
    ];

    /**
     * @return LengthAwarePaginator<int, TargetRow>
     */
    public function matrix(AssessmentPeriod $period, UnitScope $scope, ?string $unitCode, ?string $state, string $search, int $page): LengthAwarePaginator
    {
        $query = $this->base($period, $scope)->with('definitionVersion');

        if ($unitCode !== null) {
            $query->where('unit_code', $unitCode);
        }

        match ($state) {
            'missing' => $query->whereDoesntHave('versions'),
            'approved' => $query->whereHas('versions', fn (Builder $versions) => $versions->where('status', TargetStatus::Approved->value))
                ->whereDoesntHave('versions', fn (Builder $versions) => $versions->where('status', '<>', TargetStatus::Approved->value)),
            'draft', 'submitted' => $query->whereHas('versions', fn (Builder $versions) => $versions->where('status', $state)),
            default => null,
        };

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

        $versions = PeriodTargetVersion::query()
            ->whereIn('period_target_id', collect($targets->items())->pluck('id')->all())
            ->orderBy('version_number')
            ->get()
            ->groupBy('period_target_id');

        return $targets->through(function (PeriodTarget $target) use ($versions): TargetRow {
            /** @var Collection<int, PeriodTargetVersion> $own */
            $own = $versions->get($target->id) ?? new Collection;
            $latest = $own->last();

            return new TargetRow(
                target: $target,
                approved: $own->last(fn (PeriodTargetVersion $version): bool => $version->status === TargetStatus::Approved),
                working: $latest !== null && $latest->status !== TargetStatus::Approved ? $latest : null,
            );
        });
    }

    /**
     * How complete target setting is inside the scope. A target counts as
     * final when it has an approved version, even while a revision is open.
     *
     * @return array{required: int, final: int, submitted: int, draft: int, missing: int, optional: int, optional_final: int, revisions_open: int, units: list<array{code: string, name: string, required: int, final: int}>}
     */
    public function completeness(AssessmentPeriod $period, UnitScope $scope): array
    {
        $targets = $this->base($period, $scope)
            ->orderBy('unit_code')
            ->get(['id', 'unit_code', 'unit_name', 'is_required']);

        $statuses = [];

        $versions = PeriodTargetVersion::query()
            ->whereIn('period_target_id', $this->base($period, $scope)->select('id'))
            ->orderBy('version_number')
            ->get(['period_target_id', 'status']);

        foreach ($versions as $version) {
            $statuses[$version->period_target_id][] = $version->status;
        }

        $result = ['required' => 0, 'final' => 0, 'submitted' => 0, 'draft' => 0, 'missing' => 0, 'optional' => 0, 'optional_final' => 0, 'revisions_open' => 0, 'units' => []];
        $units = [];

        foreach ($targets as $target) {
            $own = $statuses[$target->id] ?? [];
            $isFinal = in_array(TargetStatus::Approved, $own, true);
            $last = $own === [] ? null : $own[array_key_last($own)];

            if ($isFinal && $last !== TargetStatus::Approved) {
                $result['revisions_open']++;
            }

            if (! $target->is_required) {
                $result['optional']++;
                $result['optional_final'] += $isFinal ? 1 : 0;

                continue;
            }

            $units[$target->unit_code] ??= ['code' => $target->unit_code, 'name' => $target->unit_name, 'required' => 0, 'final' => 0];
            $units[$target->unit_code]['required']++;
            $result['required']++;

            if ($isFinal) {
                $result['final']++;
                $units[$target->unit_code]['final']++;
            } elseif ($last === null) {
                $result['missing']++;
            } else {
                $result[$last === TargetStatus::Submitted ? 'submitted' : 'draft']++;
            }
        }

        $result['units'] = array_values($units);

        return $result;
    }

    /**
     * @return array<string, string> unit code => label, for the filter
     */
    public function unitOptions(AssessmentPeriod $period, UnitScope $scope): array
    {
        return $this->base($period, $scope)
            ->select(['unit_code', 'unit_name'])
            ->distinct()
            ->orderBy('unit_code')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->unit_code => "{$row->unit_code} — {$row->unit_name}"])
            ->all();
    }

    /**
     * @return Collection<int, PeriodTargetVersion>
     */
    public function history(PeriodTarget $target): Collection
    {
        return PeriodTargetVersion::query()
            ->where('period_target_id', $target->id)
            ->with(['createdBy', 'submittedBy', 'approvedBy'])
            ->orderByDesc('version_number')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function auditTrail(PeriodTarget $target, int $page): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'period_target')
            ->where('entity_id', $target->public_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'auditPage', $page);
    }

    /**
     * @return Builder<PeriodTarget>
     */
    public function base(AssessmentPeriod $period, UnitScope $scope): Builder
    {
        $query = PeriodTarget::query()->where('assessment_period_id', $period->id);

        if (! $scope->all) {
            $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }

        return $query;
    }
}
