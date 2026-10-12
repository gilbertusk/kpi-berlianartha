<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Effective-date bookkeeping shared by KPI definition and template versions.
 * At most one version of a KPI/template is in force on any date. A version
 * withdrawn before it started keeps an empty interval (until = from) so it
 * never applies, and the version it had superseded is reopened.
 *
 * Every change to a sibling goes through the optimistic lock, so a stale
 * retire or publish on that sibling fails instead of creating an overlap.
 */
final readonly class VersionTimeline
{
    use GuardsInput;

    public function __construct(private OptimisticLock $lock) {}

    /**
     * Closes the version currently in force at the new version's start.
     *
     * @return array<string, mixed>|null audit "before" values
     */
    public function closePrevious(KpiDefinitionVersion|KpiTemplateVersion $version, string $businessToday): ?array
    {
        $from = (string) $version->effective_from;
        $closed = null;

        foreach ($this->siblings($version) as $other) {
            if (self::isEmpty($other) || ($other->effective_until !== null && $other->effective_until <= $from)) {
                continue;
            }

            if ($other->status !== LifecycleStatus::Published || (string) $other->effective_from >= $from) {
                throw new BusinessRuleViolation('effective_overlap', 'Tanggal berlaku bertumpang tindih dengan versi lain yang sudah published atau retired.');
            }

            self::requireNotBefore($from, $businessToday);

            $this->lock->update($other, $other->lock_version, ['effective_until' => $from]);
            $closed = ['superseded_version' => $other->version_number, 'superseded_until' => $from];
        }

        return $closed;
    }

    /**
     * Withdraws a published version that has not started yet and reopens the
     * version it superseded. Nothing that was ever in force changes.
     *
     * @return array<string, mixed> audit "after" values
     */
    public function withdrawScheduled(KpiDefinitionVersion|KpiTemplateVersion $version, int $expectedVersion, ?int $actorUserId, string $reason, string $businessToday): array
    {
        if ($version->status !== LifecycleStatus::Published || (string) $version->effective_from <= $businessToday) {
            throw new BusinessRuleViolation('not_scheduled', 'Hanya versi published yang belum mulai berlaku yang dapat dibatalkan. Gunakan retire untuk versi yang sudah berlaku.');
        }

        $from = (string) $version->effective_from;
        $reopened = null;

        foreach ($this->siblings($version) as $other) {
            if ($other->status === LifecycleStatus::Published && $other->effective_until === $from) {
                $this->lock->update($other, $other->lock_version, ['effective_until' => null]);
                $reopened = $other->version_number;
            }
        }

        $this->lock->update($version, $expectedVersion, [
            'status' => LifecycleStatus::Retired->value,
            'effective_until' => $from,
            'retired_by_user_id' => $actorUserId,
            'retired_at' => CarbonImmutable::now(),
            'retire_reason' => $reason,
        ]);

        return ['status' => 'retired', 'version' => $version->version_number, 'withdrawn_before' => $from, 'reopened_version' => $reopened];
    }

    public static function isEmpty(KpiDefinitionVersion|KpiTemplateVersion $version): bool
    {
        return $version->effective_until !== null && $version->effective_until <= (string) $version->effective_from;
    }

    /**
     * Published/retired siblings, locked for the rest of the transaction.
     *
     * @return Collection<int, KpiDefinitionVersion>|Collection<int, KpiTemplateVersion>
     */
    private function siblings(KpiDefinitionVersion|KpiTemplateVersion $version): Collection
    {
        if ($version instanceof KpiDefinitionVersion) {
            return KpiDefinitionVersion::query()
                ->where('kpi_definition_id', $version->kpi_definition_id)
                ->whereKeyNot($version->id)
                ->whereIn('status', [LifecycleStatus::Published->value, LifecycleStatus::Retired->value])
                ->lockForUpdate()
                ->get();
        }

        return KpiTemplateVersion::query()
            ->where('kpi_template_id', $version->kpi_template_id)
            ->whereKeyNot($version->id)
            ->whereIn('status', [LifecycleStatus::Published->value, LifecycleStatus::Retired->value])
            ->lockForUpdate()
            ->get();
    }
}
