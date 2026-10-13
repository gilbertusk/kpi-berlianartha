<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\PeriodAssignment\Application\PeriodLifecycleParticipant;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Database\Eloquent\Builder;

/**
 * A period enters review only when no actual is still a draft, waiting for
 * verification, or rejected.
 */
final class ActualReadiness implements PeriodLifecycleParticipant
{
    public function periodOpened(AssessmentPeriod $period, Actor $actor): void {}

    public function periodAdvanced(AssessmentPeriod $period, PeriodStatus $from, Actor $actor): void {}

    public function blockersFor(AssessmentPeriod $period, PeriodStatus $next): array
    {
        if ($next !== PeriodStatus::Review) {
            return [];
        }

        $open = KpiActualVersion::query()
            ->where('status', '<>', ActualStatus::Verified->value)
            ->whereHas('actual.target', fn (Builder $targets) => $targets->where('assessment_period_id', $period->id))
            ->count();

        return $open === 0 ? [] : ["{$open} aktual belum terverifikasi (draft, menunggu verifikasi, atau ditolak)."];
    }
}
