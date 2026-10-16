<?php

declare(strict_types=1);

namespace App\Modules\Quality\Application;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrStage;
use App\Modules\Shared\Application\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The corrective action dashboard: NCRs in scope with the open stage, its
 * PIC and due date, overdue days, severity, owner, linked KPI version, and
 * repeat links. Overdue is the open stage's due date before today; no SLA
 * is applied until DEC-009 sets one.
 */
final readonly class NcrQuery
{
    public const OVERDUE = 'overdue';

    public const MINE = 'mine';

    public const REPEAT = 'repeat';

    public function __construct(
        private QualityAccess $access,
        private BusinessTime $time,
    ) {}

    /**
     * @param  array<string, string>  $filters  stage, unit (responsible team code), severity, flag (overdue|mine|repeat)
     * @param  list<int>|null  $unitIds  units whose NCRs are wanted, as QC owner or responsible team
     * @return list<array{ncr: QualityNcr, open: QualityNcrStage|null, overdue_days: int, repeats: int}>
     */
    public function rows(User $user, array $filters = [], int $limit = 500, ?array $unitIds = null): array
    {
        $today = $this->today();
        $filter = static fn (string $key): string => trim($filters[$key] ?? '');
        $query = QualityNcr::query()->with(['responsibleUnit', 'owner', 'repeatOf', 'kpiVersion', 'openStage.pic'])->withCount('repeats');
        $this->access->restrict($query, $user);

        $query
            ->when($filter('stage') !== '', fn (Builder $q) => $q->where('stage', $filter('stage')))
            ->when($filter('unit') !== '', fn (Builder $q) => $q->whereIn('responsible_unit_id', OrganizationUnit::query()->where('code', $filter('unit'))->select('id')))
            ->when($unitIds !== null, fn (Builder $q) => $q->where(fn (Builder $units) => $units->whereIn('responsible_unit_id', $unitIds === [] ? [0] : $unitIds)->orWhereIn('qc_unit_id', $unitIds === [] ? [0] : $unitIds)))
            ->when($filter('severity') !== '', fn (Builder $q) => $q->where('severity', $filter('severity')))
            ->when($filter('flag') === self::OVERDUE, fn (Builder $q) => $q->whereHas('openStage', fn (Builder $open) => $open->where('due_date', '<', $today)))
            ->when($filter('flag') === self::MINE, fn (Builder $q) => $q->where(fn (Builder $mine) => $mine->where('owner_user_id', $user->id)->orWhereHas('openStage', fn (Builder $open) => $open->where('pic_user_id', $user->id))))
            ->when($filter('flag') === self::REPEAT, fn (Builder $q) => $q->where(fn (Builder $repeat) => $repeat->whereNotNull('repeat_of_ncr_id')->orWhereHas('repeats')));

        $rows = [];

        foreach ($query->orderByDesc('detected_at')->orderByDesc('id')->limit($limit)->get() as $ncr) {
            $open = $ncr->openStage;
            $rows[] = [
                'ncr' => $ncr,
                'open' => $open,
                'overdue_days' => $open === null ? 0 : max(0, self::days($open->due_date, $today)),
                'repeats' => (int) $ncr->getAttribute('repeats_count'),
            ];
        }

        return $rows;
    }

    /**
     * Open NCRs per stage, overdue, and awaiting verification, in scope.
     *
     * @return array{stages: array<string, int>, overdue: int, verification: int, closed: int}
     */
    public function summary(User $user): array
    {
        $query = QualityNcr::query();
        $this->access->restrict($query, $user);
        $stages = (clone $query)->toBase()->selectRaw('stage, COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage');
        $overdue = (clone $query)->whereHas('openStage', fn (Builder $open) => $open->where('due_date', '<', $this->today()))->count();
        $counts = [];

        foreach (NcrStage::cases() as $stage) {
            $counts[$stage->value] = (int) ($stages[$stage->value] ?? 0);
        }

        return ['stages' => $counts, 'overdue' => $overdue, 'verification' => $counts[NcrStage::Verification->value], 'closed' => $counts[NcrStage::Closed->value]];
    }

    /**
     * Every stage pass of an NCR with its due date, completion date, and
     * days late, so overdue and closure times reconcile with the history.
     *
     * @return list<array{stage: QualityNcrStage, completed_on: string|null, late_days: int}>
     */
    public function timeline(QualityNcr $ncr): array
    {
        $today = $this->today();
        $rows = [];

        foreach (QualityNcrStage::query()->with(['pic', 'completedBy'])->where('quality_ncr_id', $ncr->id)->orderBy('sequence')->get() as $stage) {
            $completedOn = $stage->completed_at === null ? null : $this->time->businessDate($stage->completed_at);
            $rows[] = [
                'stage' => $stage,
                'completed_on' => $completedOn,
                'late_days' => max(0, self::days($stage->due_date, $completedOn ?? $today)),
            ];
        }

        return $rows;
    }

    public function today(): string
    {
        return $this->time->businessDate(CarbonImmutable::now());
    }

    public static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to.' 00:00:00 UTC') - strtotime($from.' 00:00:00 UTC')) / 86400);
    }
}
