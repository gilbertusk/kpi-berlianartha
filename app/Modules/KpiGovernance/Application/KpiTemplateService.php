<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\KpiGovernance\Domain\ItemApplicability;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Domain\TemplateComposition;
use App\Modules\KpiGovernance\Domain\Weight;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
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
use InvalidArgumentException;

/**
 * Scorecard template use cases. Items pin exact KPI definition versions and
 * can only change while the template version is a draft. Every item change
 * bumps the template version's lock_version so concurrent builders conflict.
 */
final readonly class KpiTemplateService
{
    use GuardsInput;

    private const MAX_ITEMS = 60;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private StateTransitionGuard $transitions,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
        private VersionTimeline $timeline,
    ) {}

    public function createTemplate(Actor $actor, string $code, string $name, int $unitId, string $subject, ?string $positionTitle, ?string $notes): KpiTemplateVersion
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{1,59}$/', $code) !== 1) {
            throw new BusinessRuleViolation('invalid_code', 'Kode template hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung (2–60 karakter).');
        }

        $name = self::requireText($name, 'name', 'Nama template wajib diisi.', 160);
        $positionTitle = self::optionalText($positionTitle, 120);

        if (! array_key_exists($subject, KpiTemplate::SUBJECTS)) {
            throw new BusinessRuleViolation('invalid_subject', 'Jenis scorecard tidak dikenal.');
        }

        return $this->transactions->run(function () use ($actor, $code, $name, $unitId, $subject, $positionTitle, $notes): KpiTemplateVersion {
            if ($this->directory->snapshot($this->today())->unit($unitId) === null) {
                throw new BusinessRuleViolation('invalid_unit', 'Unit template harus aktif hari ini.');
            }

            $positionKey = $positionTitle === null ? '' : mb_strtolower($positionTitle);

            $duplicateScope = KpiTemplate::query()
                ->where('organization_unit_id', $unitId)
                ->where('subject', $subject)
                ->where('position_key', $positionKey)
                ->exists();

            if ($duplicateScope || KpiTemplate::query()->where('code', $code)->exists()) {
                throw new BusinessRuleViolation('template_exists', 'Kode template atau kombinasi unit, jenis scorecard, dan jabatan sudah memiliki template.');
            }

            try {
                $template = KpiTemplate::query()->create([
                    'code' => $code,
                    'name' => $name,
                    'organization_unit_id' => $unitId,
                    'subject' => $subject,
                    'position_title' => $positionTitle,
                    'position_key' => $positionKey,
                    'created_by_user_id' => $actor->userId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('template_exists', 'Kode template atau kombinasi unit, jenis scorecard, dan jabatan sudah memiliki template.');
            }

            $version = KpiTemplateVersion::query()->create([
                'kpi_template_id' => $template->id,
                'version_number' => 1,
                'status' => LifecycleStatus::Draft,
                'notes' => self::optionalText($notes),
                'created_by_user_id' => $actor->userId,
            ]);

            $this->record($actor, $template, 'kpi_template.created', null, [
                'code' => $code,
                'name' => $name,
                'subject' => $subject,
                'position_title' => $positionTitle,
                'version' => 1,
            ], null);

            return $version;
        });
    }

    public function updateDraftHeader(Actor $actor, int $versionId, int $expectedVersion, ?string $notes, ?string $signoffReference, ?string $effectiveFrom): KpiTemplateVersion
    {
        $notes = self::optionalText($notes);
        $signoffReference = self::optionalText($signoffReference, 255);
        $effectiveFrom = self::optionalText($effectiveFrom, 10);

        if ($effectiveFrom !== null) {
            self::requirePeriod($effectiveFrom);
        }

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $notes, $signoffReference, $effectiveFrom): KpiTemplateVersion {
            $version = $this->draftVersion($versionId);
            $before = ['notes' => $version->notes, 'signoff_reference' => $version->signoff_reference, 'effective_from' => $version->effective_from];
            $after = ['notes' => $notes, 'signoff_reference' => $signoffReference, 'effective_from' => $effectiveFrom];

            if ($before === $after) {
                throw new BusinessRuleViolation('no_change', 'Tidak ada perubahan pada draft.');
            }

            $version = $this->lock->update($version, $expectedVersion, $after);
            $this->record($actor, $version->template, 'kpi_template.draft_updated', ['version' => $version->version_number, ...$before], ['version' => $version->version_number, ...$after], null);

            return $version;
        });
    }

    /**
     * @param  array<string, mixed>|null  $applicability
     */
    public function addItem(
        Actor $actor,
        int $versionId,
        int $expectedVersion,
        int $definitionVersionId,
        string $weight,
        ?string $component,
        bool $isMandatory,
        ?array $applicability,
    ): KpiTemplateItem {
        [$weightValue, $rule] = $this->validItemInput($weight, $applicability);
        $component = self::optionalText($component, 80);

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $definitionVersionId, $weightValue, $component, $isMandatory, $rule): KpiTemplateItem {
            $version = $this->draftVersion($versionId);
            $definitionVersion = KpiDefinitionVersion::query()->findOrFail($definitionVersionId);

            if ($definitionVersion->status === LifecycleStatus::Retired) {
                throw new BusinessRuleViolation('kpi_retired', 'KPI versi retired tidak dapat ditambahkan.');
            }

            $existing = KpiTemplateItem::query()->where('kpi_template_version_id', $version->id)->with('definitionVersion')->get();

            if ($existing->contains(fn (KpiTemplateItem $item): bool => $item->definitionVersion->kpi_definition_id === $definitionVersion->kpi_definition_id)) {
                throw new BusinessRuleViolation('duplicate_kpi', 'KPI ini sudah ada di template.');
            }

            if ($existing->count() >= self::MAX_ITEMS) {
                throw new BusinessRuleViolation('too_many_items', 'Template maksimal berisi '.self::MAX_ITEMS.' KPI.');
            }

            $this->lock->update($version, $expectedVersion, []);

            $item = KpiTemplateItem::query()->create([
                'kpi_template_version_id' => $version->id,
                'kpi_definition_version_id' => $definitionVersion->id,
                'component' => $component,
                'weight' => $weightValue->toDecimalString(),
                'sort_order' => ((int) $existing->max('sort_order')) + 1,
                'is_mandatory' => $isMandatory,
                'applicability' => $rule?->toArray(),
            ]);

            $this->record($actor, $version->template, 'kpi_template.item_added', null, [
                'version' => $version->version_number,
                'item_id' => $item->public_id,
                'kpi_definition_version_id' => $definitionVersion->public_id,
                'weight' => $weightValue->format(),
                'component' => $component,
                'is_mandatory' => $isMandatory,
                'applicability' => $rule?->toArray(),
            ], null);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>|null  $applicability
     */
    public function updateItem(Actor $actor, int $itemId, int $expectedVersion, string $weight, ?string $component, bool $isMandatory, ?array $applicability): KpiTemplateItem
    {
        [$weightValue, $rule] = $this->validItemInput($weight, $applicability);
        $component = self::optionalText($component, 80);

        return $this->transactions->run(function () use ($actor, $itemId, $expectedVersion, $weightValue, $component, $isMandatory, $rule): KpiTemplateItem {
            $item = KpiTemplateItem::query()->findOrFail($itemId);
            $version = $this->draftVersion($item->kpi_template_version_id);
            $this->lock->update($version, $expectedVersion, []);

            $before = ['weight' => Weight::fromString($item->weight)->format(), 'component' => $item->component, 'is_mandatory' => $item->is_mandatory, 'applicability' => $item->applicability];

            $item->forceFill([
                'weight' => $weightValue->toDecimalString(),
                'component' => $component,
                'is_mandatory' => $isMandatory,
                'applicability' => $rule?->toArray(),
            ])->save();

            $this->record($actor, $version->template, 'kpi_template.item_updated', ['item_id' => $item->public_id, ...$before], [
                'item_id' => $item->public_id,
                'weight' => $weightValue->format(),
                'component' => $component,
                'is_mandatory' => $isMandatory,
                'applicability' => $rule?->toArray(),
            ], null);

            return $item;
        });
    }

    public function removeItem(Actor $actor, int $itemId, int $expectedVersion): void
    {
        $this->transactions->run(function () use ($actor, $itemId, $expectedVersion): void {
            $item = KpiTemplateItem::query()->findOrFail($itemId);
            $version = $this->draftVersion($item->kpi_template_version_id);
            $this->lock->update($version, $expectedVersion, []);

            $item->delete();

            $this->record($actor, $version->template, 'kpi_template.item_removed', ['item_id' => $item->public_id, 'weight' => $item->weight], null, null);
        });
    }

    public function moveItem(Actor $actor, int $itemId, int $expectedVersion, int $offset): void
    {
        if (! in_array($offset, [-1, 1], true)) {
            throw new InvalidArgumentException('Offset must be -1 or 1.');
        }

        $this->transactions->run(function () use ($actor, $itemId, $expectedVersion, $offset): void {
            $item = KpiTemplateItem::query()->findOrFail($itemId);
            $version = $this->draftVersion($item->kpi_template_version_id);
            $items = KpiTemplateItem::query()->where('kpi_template_version_id', $version->id)->orderBy('sort_order')->orderBy('id')->get()->values();
            $index = $items->search(fn (KpiTemplateItem $candidate): bool => $candidate->id === $item->id);
            $swap = $index === false ? null : $items->get($index + $offset);

            if ($swap === null) {
                return;
            }

            $this->lock->update($version, $expectedVersion, []);

            // Renumber densely so the order is unambiguous after the swap.
            $ordered = $items->all();
            [$ordered[$index], $ordered[$index + $offset]] = [$ordered[$index + $offset], $ordered[$index]];

            foreach (array_values($ordered) as $position => $orderedItem) {
                $orderedItem->forceFill(['sort_order' => $position + 1])->save();
            }

            $this->record($actor, $version->template, 'kpi_template.item_moved', null, ['item_id' => $item->public_id, 'offset' => $offset], null);
        });
    }

    public function startNewVersion(Actor $actor, int $templateId, string $changeReason): KpiTemplateVersion
    {
        $changeReason = self::requireText($changeReason, 'change_reason', 'Alasan perubahan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $templateId, $changeReason): KpiTemplateVersion {
            $template = KpiTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();
            $latest = KpiTemplateVersion::query()->where('kpi_template_id', $template->id)->orderByDesc('version_number')->firstOrFail();

            if ($latest->status === LifecycleStatus::Draft || $latest->status === LifecycleStatus::Review) {
                throw new BusinessRuleViolation('working_version_exists', 'Template ini masih memiliki versi draft atau review.');
            }

            $version = KpiTemplateVersion::query()->create([
                'kpi_template_id' => $template->id,
                'version_number' => $latest->version_number + 1,
                'status' => LifecycleStatus::Draft,
                'notes' => $latest->notes,
                'change_reason' => $changeReason,
                'previous_version_id' => $latest->id,
                'created_by_user_id' => $actor->userId,
            ]);

            foreach ($latest->items()->get() as $item) {
                KpiTemplateItem::query()->create([
                    'kpi_template_version_id' => $version->id,
                    'kpi_definition_version_id' => $item->kpi_definition_version_id,
                    'component' => $item->component,
                    'weight' => $item->weight,
                    'sort_order' => $item->sort_order,
                    'is_mandatory' => $item->is_mandatory,
                    'applicability' => $item->applicability,
                ]);
            }

            $this->record($actor, $template, 'kpi_template.version_started', ['version' => $latest->version_number], ['version' => $version->version_number], $changeReason);

            return $version;
        });
    }

    public function submitForReview(Actor $actor, int $versionId, int $expectedVersion): KpiTemplateVersion
    {
        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion): KpiTemplateVersion {
            $version = KpiTemplateVersion::query()->with('template')->findOrFail($versionId);
            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Review->value, LifecycleStatus::transitions());
            $this->assertPublishable($version);

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Review->value,
                'submitted_by_user_id' => $actor->userId,
                'submitted_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $version->template, 'kpi_template.submitted', ['status' => 'draft'], ['status' => 'review', 'version' => $version->version_number], null);

            return $version;
        });
    }

    public function returnToDraft(Actor $actor, int $versionId, int $expectedVersion, string $reason): KpiTemplateVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pengembalian wajib diisi.');

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $reason): KpiTemplateVersion {
            $version = KpiTemplateVersion::query()->with('template')->findOrFail($versionId);
            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Draft->value, LifecycleStatus::transitions());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Draft->value,
                'returned_by_user_id' => $actor->userId,
                'returned_at' => CarbonImmutable::now(),
                'return_reason' => $reason,
            ]);

            $this->record($actor, $version->template, 'kpi_template.returned', ['status' => 'review'], ['status' => 'draft', 'version' => $version->version_number], $reason);

            return $version;
        });
    }

    public function publish(Actor $actor, int $versionId, int $expectedVersion): KpiTemplateVersion
    {
        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion): KpiTemplateVersion {
            $version = KpiTemplateVersion::query()->with('template')->findOrFail($versionId);
            KpiTemplate::query()->lockForUpdate()->findOrFail($version->kpi_template_id);

            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Published->value, LifecycleStatus::transitions());
            $this->assertPublishable($version);

            if ($actor->userId !== null && $version->submitted_by_user_id === $actor->userId) {
                throw new BusinessRuleViolation('self_review', 'Versi yang Anda ajukan harus direview dan dipublish oleh orang lain.');
            }

            $closed = $this->timeline->closePrevious($version, $this->today());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Published->value,
                'published_by_user_id' => $actor->userId,
                'published_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $version->template, 'kpi_template.published', $closed, [
                'version' => $version->version_number,
                'effective_from' => $version->effective_from,
                'signoff_reference' => $version->signoff_reference,
                'items' => $version->items()->count(),
            ], $version->change_reason);

            return $version;
        });
    }

    public function retire(Actor $actor, int $versionId, int $expectedVersion, string $effectiveUntil, string $reason): KpiTemplateVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan retire wajib diisi.');
        self::requirePeriod($effectiveUntil);
        self::requireNotBefore($effectiveUntil, $this->today());

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $effectiveUntil, $reason): KpiTemplateVersion {
            // Lock the template first so a concurrent publish cannot close
            // this version between the checks below and the update.
            $templateId = KpiTemplateVersion::query()->whereKey($versionId)->value('kpi_template_id');
            KpiTemplate::query()->lockForUpdate()->findOrFail($templateId);
            $version = KpiTemplateVersion::query()->with('template')->lockForUpdate()->findOrFail($versionId);
            $this->transitions->assertAllowed($version->status->value, LifecycleStatus::Retired->value, LifecycleStatus::transitions());

            if ($effectiveUntil <= (string) $version->effective_from
                || ($version->effective_until !== null && $effectiveUntil > $version->effective_until)) {
                throw new BusinessRuleViolation('invalid_period', 'Tanggal akhir harus setelah tanggal mulai dan tidak melewati batas versi ini.');
            }

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => LifecycleStatus::Retired->value,
                'effective_until' => $effectiveUntil,
                'retired_by_user_id' => $actor->userId,
                'retired_at' => CarbonImmutable::now(),
                'retire_reason' => $reason,
            ]);

            $this->record($actor, $version->template, 'kpi_template.retired', ['status' => 'published'], ['status' => 'retired', 'version' => $version->version_number, 'effective_until' => $effectiveUntil], $reason);

            return $version;
        });
    }

    public function withdrawScheduled(Actor $actor, int $versionId, int $expectedVersion, string $reason): KpiTemplateVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pembatalan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $reason): KpiTemplateVersion {
            $templateId = KpiTemplateVersion::query()->whereKey($versionId)->value('kpi_template_id');
            $template = KpiTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();
            $version = KpiTemplateVersion::query()->lockForUpdate()->findOrFail($versionId);

            $after = $this->timeline->withdrawScheduled($version, $expectedVersion, $actor->userId, $reason, $this->today());
            $this->record($actor, $template, 'kpi_template.withdrawn', ['status' => 'published', 'effective_from' => $version->effective_from], $after, $reason);

            return $version->refresh();
        });
    }

    /**
     * KPI versions pinned by this template version that stop being in force
     * while the template is still in force. Not a blocker (pinning keeps the
     * scorecard reproducible) but a new template version should adopt them.
     *
     * @return list<string>
     */
    public function pinnedKpiWarnings(KpiTemplateVersion $version): array
    {
        $warnings = [];

        foreach ($version->items()->with('definitionVersion')->get() as $item) {
            $definition = $item->definitionVersion;

            if ($definition->effective_until !== null && ($version->effective_until === null || $definition->effective_until < $version->effective_until)) {
                $warnings[] = "KPI {$definition->name} (v{$definition->version_number}) berakhir pada {$definition->effective_until}; buat versi template baru untuk memakai versi KPI berikutnya.";
            }
        }

        return $warnings;
    }

    /**
     * Problems that block review/publish. Empty list = publishable.
     *
     * @return list<string>
     */
    public function publishProblems(KpiTemplateVersion $version): array
    {
        $problems = [];

        if ($version->effective_from === null) {
            $problems[] = 'Tanggal berlaku wajib diisi.';
        }

        if ($version->signoff_reference === null) {
            $problems[] = 'Referensi sign-off bisnis wajib diisi.';
        }

        $items = $version->items()->with('definitionVersion')->get();

        $problems = [...$problems, ...TemplateComposition::publishErrors(array_values($items->map(fn (KpiTemplateItem $item): array => [
            'definition_id' => $item->definitionVersion->kpi_definition_id,
            'weight_units' => Weight::fromString($item->weight)->units,
        ])->all()))];

        foreach ($items as $item) {
            $definition = $item->definitionVersion;
            $label = "KPI {$definition->name} (v{$definition->version_number})";

            if ($definition->status !== LifecycleStatus::Published) {
                $problems[] = "{$label} belum published.";
            } elseif ($version->effective_from !== null
                && ((string) $definition->effective_from > $version->effective_from
                    || ($definition->effective_until !== null && $definition->effective_until <= $version->effective_from))) {
                $problems[] = "{$label} tidak berlaku pada tanggal mulai template.";
            }
        }

        return $problems;
    }

    private function assertPublishable(KpiTemplateVersion $version): void
    {
        $problems = $this->publishProblems($version);

        if ($problems !== []) {
            throw new BusinessRuleViolation('template_incomplete', 'Template belum dapat dipublish: '.implode(' ', $problems));
        }
    }

    private function draftVersion(int $versionId): KpiTemplateVersion
    {
        $version = KpiTemplateVersion::query()->with('template')->lockForUpdate()->findOrFail($versionId);

        if ($version->status !== LifecycleStatus::Draft) {
            throw new BusinessRuleViolation('version_not_editable', 'Hanya versi draft yang dapat diubah.');
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>|null  $applicability
     * @return array{0: Weight, 1: ItemApplicability|null}
     */
    private function validItemInput(string $weight, ?array $applicability): array
    {
        try {
            return [Weight::fromString($weight), ItemApplicability::fromArray($applicability)];
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('invalid_item', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, KpiTemplate $template, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'kpi_template',
            entityId: $template->public_id,
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
}
