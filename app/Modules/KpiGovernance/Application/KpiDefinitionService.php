<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\KpiGovernance\Domain\DefinitionContent;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\StateTransitionGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * KPI dictionary use cases. A published version is never edited: every
 * change starts a new draft version with a reason, and publishing it closes
 * the previous published version on the new effective date.
 */
final readonly class KpiDefinitionService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private StateTransitionGuard $transitions,
        private ScoreStrategyRegistry $strategies,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
        private VersionTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public function createDraft(Actor $actor, string $code, int $ownerUnitId, array $content, string $origin = KpiDefinition::ORIGIN_AUTHORED, ?string $changeReason = null): KpiDefinitionVersion
    {
        $code = self::normalizeCode($code);
        $definitionContent = $this->validDraftContent($content);

        if (! in_array($origin, [KpiDefinition::ORIGIN_AUTHORED, KpiDefinition::ORIGIN_PRD_CANDIDATE], true)) {
            throw new BusinessRuleViolation('invalid_origin', 'Asal KPI tidak dikenal.');
        }

        return $this->transactions->run(function () use ($actor, $code, $ownerUnitId, $definitionContent, $origin, $changeReason): KpiDefinitionVersion {
            if ($this->directory->snapshot($this->today())->unit($ownerUnitId) === null) {
                throw new BusinessRuleViolation('invalid_owner_unit', 'Unit pemilik KPI harus aktif hari ini.');
            }

            if (KpiDefinition::query()->where('code', $code)->exists()) {
                throw new BusinessRuleViolation('kpi_code_taken', 'Kode KPI sudah digunakan.');
            }

            try {
                $definition = KpiDefinition::query()->create([
                    'code' => $code,
                    'origin' => $origin,
                    'owner_unit_id' => $ownerUnitId,
                    'created_by_user_id' => $actor->userId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('kpi_code_taken', 'Kode KPI sudah digunakan.');
            }

            $version = KpiDefinitionVersion::query()->create([
                ...$definitionContent->toArray(),
                'kpi_definition_id' => $definition->id,
                'version_number' => 1,
                'status' => LifecycleStatus::Draft,
                'change_reason' => self::optionalText($changeReason),
                'created_by_user_id' => $actor->userId,
            ]);

            $this->record($actor, $definition, 'kpi_definition.created', null, [
                'code' => $code,
                'origin' => $origin,
                'version' => 1,
                'name' => $definitionContent->get('name'),
            ], $changeReason);

            return $version;
        });
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function updateDraft(Actor $actor, int $versionId, int $expectedVersion, array $content): KpiDefinitionVersion
    {
        $definitionContent = $this->validDraftContent($content);

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $definitionContent): KpiDefinitionVersion {
            $version = KpiDefinitionVersion::query()->with('definition')->findOrFail($versionId);
            $this->assertStatus($version, LifecycleStatus::Draft, 'Hanya versi draft yang dapat diubah.');

            $before = $version->content()->toArray();
            $after = $definitionContent->toArray();
            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                throw new BusinessRuleViolation('no_change', 'Tidak ada perubahan pada draft.');
            }

            $attributes = array_intersect_key($after, array_flip($changed));

            if (array_key_exists('score_parameters', $attributes)) {
                $attributes['score_parameters'] = $attributes['score_parameters'] === null ? null : json_encode($attributes['score_parameters'], JSON_THROW_ON_ERROR);
            }

            $version = $this->lock->update($version, $expectedVersion, $attributes);

            $this->record(
                $actor,
                $version->definition,
                'kpi_definition.draft_updated',
                ['version' => $version->version_number, ...array_intersect_key($before, array_flip($changed))],
                ['version' => $version->version_number, ...array_intersect_key($after, array_flip($changed))],
                null,
            );

            return $version;
        });
    }

    /**
     * Starts the next draft from the newest version. Only one working
     * (draft or review) version may exist per KPI at a time.
     */
    public function startNewVersion(Actor $actor, int $definitionId, string $changeReason): KpiDefinitionVersion
    {
        $changeReason = self::requireText($changeReason, 'change_reason', 'Alasan perubahan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $definitionId, $changeReason): KpiDefinitionVersion {
            $definition = KpiDefinition::query()->whereKey($definitionId)->lockForUpdate()->firstOrFail();
            $latest = KpiDefinitionVersion::query()->where('kpi_definition_id', $definition->id)->orderByDesc('version_number')->firstOrFail();

            if ($latest->status === LifecycleStatus::Draft || $latest->status === LifecycleStatus::Review) {
                throw new BusinessRuleViolation('working_version_exists', 'KPI ini masih memiliki versi draft atau review.');
            }

            $version = KpiDefinitionVersion::query()->create([
                ...$latest->content()->toArray(),
                'kpi_definition_id' => $definition->id,
                'version_number' => $latest->version_number + 1,
                'status' => LifecycleStatus::Draft,
                // A new version must be signed off and dated again.
                'signoff_reference' => null,
                'effective_from' => null,
                'change_reason' => $changeReason,
                'previous_version_id' => $latest->id,
                'created_by_user_id' => $actor->userId,
            ]);

            $this->record($actor, $definition, 'kpi_definition.version_started', ['version' => $latest->version_number], ['version' => $version->version_number], $changeReason);

            return $version;
        });
    }

    public function submitForReview(Actor $actor, int $versionId, int $expectedVersion): KpiDefinitionVersion
    {
        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion): KpiDefinitionVersion {
            $version = KpiDefinitionVersion::query()->with('definition')->findOrFail($versionId);
            $this->assertCompleteForPublish($version);
            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Review->value, LifecycleStatus::transitions());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Review->value,
                'submitted_by_user_id' => $actor->userId,
                'submitted_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $version->definition, 'kpi_definition.submitted', ['status' => 'draft'], ['status' => 'review', 'version' => $version->version_number], null);

            return $version;
        });
    }

    public function returnToDraft(Actor $actor, int $versionId, int $expectedVersion, string $reason): KpiDefinitionVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pengembalian wajib diisi.');

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $reason): KpiDefinitionVersion {
            $version = KpiDefinitionVersion::query()->with('definition')->findOrFail($versionId);
            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Draft->value, LifecycleStatus::transitions());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Draft->value,
                'returned_by_user_id' => $actor->userId,
                'returned_at' => CarbonImmutable::now(),
                'return_reason' => $reason,
            ]);

            $this->record($actor, $version->definition, 'kpi_definition.returned', ['status' => 'review'], ['status' => 'draft', 'version' => $version->version_number], $reason);

            return $version;
        });
    }

    public function publish(Actor $actor, int $versionId, int $expectedVersion): KpiDefinitionVersion
    {
        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion): KpiDefinitionVersion {
            $version = KpiDefinitionVersion::query()->with('definition')->findOrFail($versionId);
            KpiDefinition::query()->lockForUpdate()->findOrFail($version->kpi_definition_id);

            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Published->value, LifecycleStatus::transitions());
            $this->assertCompleteForPublish($version);

            if ($actor->userId !== null && $version->submitted_by_user_id === $actor->userId) {
                throw new BusinessRuleViolation('self_review', 'Versi yang Anda ajukan harus direview dan dipublish oleh orang lain.');
            }

            $closed = $this->timeline->closePrevious($version, $this->today());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Published->value,
                'published_by_user_id' => $actor->userId,
                'published_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $version->definition, 'kpi_definition.published', $closed, [
                'version' => $version->version_number,
                'effective_from' => $version->effective_from,
                'score_strategy' => $version->score_strategy.'@'.$version->score_strategy_version,
                'signoff_reference' => $version->signoff_reference,
            ], $version->change_reason);

            return $version;
        });
    }

    public function retire(Actor $actor, int $versionId, int $expectedVersion, string $effectiveUntil, string $reason): KpiDefinitionVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan retire wajib diisi.');
        self::requirePeriod($effectiveUntil);
        self::requireNotBefore($effectiveUntil, $this->today());

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $effectiveUntil, $reason): KpiDefinitionVersion {
            // Lock the KPI first so a concurrent publish cannot close this
            // version between the checks below and the update.
            $definitionId = KpiDefinitionVersion::query()->whereKey($versionId)->value('kpi_definition_id');
            KpiDefinition::query()->lockForUpdate()->findOrFail($definitionId);
            $version = KpiDefinitionVersion::query()->with('definition')->lockForUpdate()->findOrFail($versionId);
            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Retired->value, LifecycleStatus::transitions());

            if ($effectiveUntil <= (string) $version->effective_from) {
                throw new BusinessRuleViolation('invalid_period', 'Tanggal akhir harus setelah tanggal mulai berlaku. Versi yang belum berlaku dibatalkan dengan "Batalkan versi terjadwal".');
            }

            if ($version->effective_until !== null && $effectiveUntil > $version->effective_until) {
                throw new BusinessRuleViolation('invalid_period', 'Tanggal akhir tidak boleh melewati batas versi ini.');
            }

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Retired->value,
                'effective_until' => $effectiveUntil,
                'retired_by_user_id' => $actor->userId,
                'retired_at' => CarbonImmutable::now(),
                'retire_reason' => $reason,
            ]);

            $this->record($actor, $version->definition, 'kpi_definition.retired', ['status' => 'published'], ['status' => 'retired', 'version' => $version->version_number, 'effective_until' => $effectiveUntil], $reason);

            return $version;
        });
    }

    public function withdrawScheduled(Actor $actor, int $versionId, int $expectedVersion, string $reason): KpiDefinitionVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pembatalan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $reason): KpiDefinitionVersion {
            $definitionId = KpiDefinitionVersion::query()->whereKey($versionId)->value('kpi_definition_id');
            $definition = KpiDefinition::query()->whereKey($definitionId)->lockForUpdate()->firstOrFail();
            $version = KpiDefinitionVersion::query()->lockForUpdate()->findOrFail($versionId);

            $after = $this->timeline->withdrawScheduled($version, $expectedVersion, $actor->userId, $reason, $this->today());
            $this->record($actor, $definition, 'kpi_definition.withdrawn', ['status' => 'published', 'effective_from' => $version->effective_from], $after, $reason);

            return $version->refresh();
        });
    }

    private function assertCompleteForPublish(KpiDefinitionVersion $version): void
    {
        $errors = $version->content()->publishErrors($this->strategies);

        if ($version->effective_from !== null) {
            try {
                self::requirePeriod($version->effective_from);
            } catch (BusinessRuleViolation) {
                $errors[] = 'Tanggal berlaku tidak valid.';
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleViolation('definition_incomplete', 'Definisi KPI belum lengkap: '.implode(' ', $errors));
        }
    }

    private function assertStatus(KpiDefinitionVersion $version, LifecycleStatus $expected, string $message): void
    {
        if ($version->status !== $expected) {
            throw new BusinessRuleViolation('version_not_editable', $message);
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function validDraftContent(array $content): DefinitionContent
    {
        $definitionContent = DefinitionContent::fromArray($content);
        $errors = $definitionContent->draftErrors($this->strategies);
        $effectiveFrom = $definitionContent->get('effective_from');

        if (is_string($effectiveFrom)) {
            self::requirePeriod($effectiveFrom);
        }

        if ($errors !== []) {
            throw new BusinessRuleViolation('invalid_definition', implode(' ', $errors));
        }

        $strategy = $this->strategies->find(
            is_string($definitionContent->get('score_strategy')) ? $definitionContent->get('score_strategy') : null,
            is_int($definitionContent->get('score_strategy_version')) ? $definitionContent->get('score_strategy_version') : null,
        );
        $parameters = $definitionContent->get('score_parameters');

        // Store canonical decimal strings when the parameters are complete;
        // incomplete drafts keep what was entered so nothing is lost.
        if ($strategy !== null && is_array($parameters) && $strategy->validate($parameters) === []) {
            return DefinitionContent::fromArray([...$definitionContent->toArray(), 'score_parameters' => $strategy->normalize($parameters)]);
        }

        return $definitionContent;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, KpiDefinition $definition, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'kpi_definition',
            entityId: $definition->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }

    private function today(): string
    {
        return $this->time->businessDate(CarbonImmutable::now());
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{1,59}$/', $code) !== 1) {
            throw new BusinessRuleViolation('invalid_code', 'Kode KPI hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung (2–60 karakter).');
        }

        return $code;
    }
}
