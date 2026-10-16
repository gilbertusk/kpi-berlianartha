<?php

declare(strict_types=1);

namespace App\Modules\Quality\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrStage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reads and moves NCRs. An NCR belongs to Quality Control (the
 * `quality.qc_unit_code` team) and to the team responsible for the
 * nonconformance; it is readable in the `quality_record.view` scope of
 * either, and by every PIC of one of its stages.
 *
 * Quality Control raises, verifies, closes, reopens, and corrects. The
 * investigation stages (containment, root cause, corrective action) may
 * also be completed by the responsible team's managers and by the stage
 * PIC. No other approval exists until DEC-002 and DEC-009 configure one.
 */
final readonly class QualityAccess
{
    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
        private string $qcUnitCode,
    ) {}

    public function qcUnitId(): ?int
    {
        foreach ($this->directory->snapshot($this->access->businessToday())->all() as $unit) {
            if ($unit->code === $this->qcUnitCode) {
                return $unit->id;
            }
        }

        return null;
    }

    public function readScope(User $user): UnitScope
    {
        return $this->access->unitScope($user, Capability::QualityRecordView);
    }

    public function isQcManager(User $user): bool
    {
        $unitId = $this->qcUnitId();

        return $unitId !== null && $this->access->allows($user, Capability::QualityRecordManage, $this->access->unitTarget($unitId));
    }

    public function managesResponsibleUnit(User $user, QualityNcr $ncr): bool
    {
        return $this->access->allows($user, Capability::QualityRecordManage, $this->access->unitTarget($ncr->responsible_unit_id));
    }

    public function isPic(User $user, QualityNcr $ncr): bool
    {
        return QualityNcrStage::query()->where('quality_ncr_id', $ncr->id)->where('pic_user_id', $user->id)->exists();
    }

    public function canRead(User $user, QualityNcr $ncr): bool
    {
        $scope = $this->readScope($user);

        return $scope->coversUnit($ncr->qc_unit_id) || $scope->coversUnit($ncr->responsible_unit_id) || $this->isPic($user, $ncr);
    }

    /**
     * The NCR pages open for Quality Control's readers and for teams that
     * have an NCR in their scope or a stage to work on.
     */
    public function canView(User $user): bool
    {
        $scope = $this->readScope($user);
        $qcUnitId = $this->qcUnitId();

        if ($scope->isEmpty()) {
            return QualityNcrStage::query()->where('pic_user_id', $user->id)->exists();
        }

        if ($scope->all || ($qcUnitId !== null && $scope->coversUnit($qcUnitId))) {
            return true;
        }

        $query = QualityNcr::query();
        $this->restrict($query, $user);

        return $query->exists();
    }

    /**
     * Completing the open stage: Quality Control always; the responsible
     * team's managers and the stage PIC for the investigation stages.
     */
    public function canComplete(User $user, QualityNcr $ncr, QualityNcrStage $stage): bool
    {
        if ($this->isQcManager($user)) {
            return true;
        }

        return in_array($stage->stage, [NcrStage::Containment, NcrStage::RootCause, NcrStage::CorrectiveAction], true)
            && ($this->managesResponsibleUnit($user, $ncr) || $stage->pic_user_id === $user->id);
    }

    /**
     * Restricted evidence is read by Quality Control, the responsible
     * team's managers, the person who recorded it, and the holders of
     * `audit_report.view` (Auditor, HR Performance).
     */
    public function canSeeRestrictedEvidence(User $user, QualityNcr $ncr, QualityNcrStage $stage): bool
    {
        return $this->isQcManager($user)
            || $this->managesResponsibleUnit($user, $ncr)
            || $stage->completed_by_user_id === $user->id
            || $this->access->allows($user, Capability::AuditReportView);
    }

    /**
     * @param  Builder<QualityNcr>  $query
     */
    public function restrict(Builder $query, User $user): void
    {
        $scope = $this->readScope($user);

        if ($scope->all) {
            return;
        }

        $units = $scope->unitIds === [] ? [0] : $scope->unitIds;
        $query->where(fn (Builder $visible) => $visible
            ->whereIn('qc_unit_id', $units)
            ->orWhereIn('responsible_unit_id', $units)
            ->orWhereIn('id', QualityNcrStage::query()->where('pic_user_id', $user->id)->select('quality_ncr_id')));
    }
}
