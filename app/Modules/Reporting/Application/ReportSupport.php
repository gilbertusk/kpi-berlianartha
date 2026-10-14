<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;

/**
 * What every report needs to turn a request into an authorized context.
 */
final readonly class ReportSupport
{
    public function __construct(
        public AccessControl $access,
        private OrganizationDirectory $directory,
    ) {}

    public function mayUseReports(User $user): bool
    {
        return $this->access->allows($user, Capability::ReportView);
    }

    public function assertMayUseReports(User $user): void
    {
        if (! $this->mayUseReports($user)) {
            throw new AuthorizationException;
        }
    }

    /**
     * The period of the request. Reports cover periods past target setting.
     */
    public function period(ReportFilters $filters): AssessmentPeriod
    {
        if ($filters->period === null) {
            throw new BusinessRuleViolation('period_required', 'Pilih periode laporan.');
        }

        $period = AssessmentPeriod::query()->where('public_id', $filters->period)->first();

        if ($period === null || in_array($period->status, [PeriodStatus::Draft, PeriodStatus::Open], true)) {
            throw new AuthorizationException;
        }

        return $period;
    }

    /**
     * The unit filter as the ids of that unit and its subtree. The unit must
     * lie inside the scope the report is read under; an unknown unit and a
     * unit out of scope are refused the same way.
     *
     * @return list<int>|null
     */
    public function units(ReportFilters $filters, UnitScope ...$scopes): ?array
    {
        if ($filters->unit === null) {
            return null;
        }

        $snapshot = $this->directory->snapshot($this->access->businessToday());

        foreach ($snapshot->all() as $unit) {
            if ($unit->code !== $filters->unit) {
                continue;
            }

            foreach ($scopes as $scope) {
                if ($scope->coversUnit($unit->id)) {
                    return $snapshot->subtreeIds($unit->id);
                }
            }
        }

        throw new AuthorizationException;
    }

    /**
     * Human-readable organization scope printed on the export.
     */
    public function describe(ReportFilters $filters, UnitScope ...$scopes): string
    {
        $all = false;
        $ids = [];

        foreach ($scopes as $scope) {
            $all = $all || $scope->all;
            $ids = [...$ids, ...$scope->unitIds];
        }

        if ($all) {
            $base = 'Seluruh perusahaan';
        } else {
            $snapshot = $this->directory->snapshot($this->access->businessToday());
            $codes = [];

            foreach (array_unique($ids) as $id) {
                $unit = $snapshot->unit($id);

                if ($unit !== null) {
                    $codes[] = $unit->code;
                }
            }

            sort($codes);
            $base = $codes === [] ? 'Tidak ada unit' : count($codes).' unit: '.implode(', ', array_slice($codes, 0, 12)).(count($codes) > 12 ? ', …' : '');
        }

        return $filters->unit === null ? $base : "{$base}; dibatasi ke {$filters->unit} dan unit di bawahnya";
    }

    /**
     * The approved red-KPI threshold, or null while it is not approved.
     */
    public static function redThreshold(): ?string
    {
        $threshold = Config::get('dashboard.red_kpi_score_below');

        return is_string($threshold) && preg_match('/^\d{1,6}(\.\d{1,4})?$/', $threshold) === 1 ? $threshold : null;
    }
}
