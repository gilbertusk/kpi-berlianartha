<?php

declare(strict_types=1);

namespace App\Modules\Quality\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Quality\Domain\NcrSource;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Domain\QualityRules;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrEvent;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrStage;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use LogicException;

/**
 * The NCR and CAPA lifecycle (PRD §10.3): Temuan, Containment, Root Cause,
 * Corrective Action, Verification, Closed. Each stage gets a PIC and a due
 * date when entered and its content and evidence when completed; a
 * completed stage never changes. A failed verification returns to
 * corrective action, a closed NCR may be reopened, and a correction of the
 * NCR header keeps its before and after. Nothing is ever deleted.
 */
final readonly class NcrService
{
    use GuardsInput;

    private const CODE = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,59}$/';

    private const QUANTITY = '/^\d{1,14}(?:\.\d{1,3})?$/';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $locks,
        private QualityAccess $access,
        private BusinessTime $time,
        private OrganizationDirectory $directory,
    ) {}

    public static function rules(): QualityRules
    {
        $config = Config::get('quality', []);

        return QualityRules::fromArray(is_array($config) ? $config : []);
    }

    /**
     * Raises an NCR at the Temuan stage.
     *
     * @param  array<string, mixed>  $input
     */
    public function raise(Actor $actor, User $user, array $input): QualityNcr
    {
        $this->assertActor($actor, $user);
        $qcUnitId = $this->access->qcUnitId();

        if ($qcUnitId === null || ! $this->access->isQcManager($user)) {
            throw new AuthorizationException;
        }

        $rules = $this->rulesOrFail();
        $source = NcrSource::tryFrom(self::text($input, 'source_type')) ?? throw new BusinessRuleViolation('invalid_source', 'Sumber NCR harus inspeksi, complaint, return, atau temuan internal.');
        $reference = self::text($input, 'source_reference');

        if ($reference === '' || mb_strlen($reference) > 200) {
            throw new BusinessRuleViolation('invalid_source', 'Referensi sumber wajib diisi (maks. 200 karakter).');
        }

        $record = $source === NcrSource::Inspection ? $this->inspection($qcUnitId, $reference) : null;
        $fields = [
            'category_code' => self::text($input, 'category_code') ?: (string) $record?->defect_code,
            'work_order' => self::text($input, 'work_order') ?: $record?->work_order,
            'product_code' => self::text($input, 'product_code') ?: $record?->product_code,
            'lot' => self::text($input, 'lot') ?: $record?->detail('lot'),
            'unit' => self::text($input, 'unit') ?: $record?->unit,
            'quantity' => self::text($input, 'quantity') ?: self::positiveOrNull($record?->reject_quantity),
            'process_code' => self::text($input, 'process_code') ?: null,
            'machine_code' => self::text($input, 'machine_code') ?: null,
            'shift_code' => self::text($input, 'shift_code') ?: null,
        ];
        $fields = [...$fields, 'category_code' => $this->category($rules, $fields['category_code']), 'severity' => $this->severity($rules, self::text($input, 'severity'))];
        $this->assertCodes($fields);
        $this->assertQuantity($fields['quantity'], $fields['unit']);
        $description = self::requireText(self::text($input, 'description'), 'description', 'Uraian temuan wajib diisi.', 2000);
        $detectedAt = self::text($input, 'detected_at') === '' && $record?->started_at !== null ? CarbonImmutable::instance($record->started_at) : $this->moment(self::text($input, 'detected_at'));
        $responsible = $this->activeUnit(self::text($input, 'responsible_unit'));
        $owner = $this->activeUser($input['owner_user_id'] ?? null, 'Pemilik NCR');
        $pic = $this->activeUser($input['pic_user_id'] ?? null, 'PIC tahap');
        $due = $this->dueDate(self::text($input, 'due_date'));
        $repeatOf = $this->repeatOf($user, self::text($input, 'repeat_of'));
        $kpi = $this->kpiVersion(self::text($input, 'kpi_code'));

        if (QualityNcr::query()->where('source_type', $source->value)->where('source_reference', $reference)->where('category_code', $fields['category_code'])->exists()) {
            throw new BusinessRuleViolation('duplicate_ncr', 'NCR untuk sumber dan kategori defect ini sudah ada.');
        }

        try {
            return $this->transactions->run(function () use ($actor, $user, $qcUnitId, $source, $reference, $record, $fields, $description, $detectedAt, $responsible, $owner, $pic, $due, $repeatOf, $kpi): QualityNcr {
                OrganizationUnit::query()->whereKey($qcUnitId)->lock('for no key update')->firstOrFail();
                $now = CarbonImmutable::now();
                $ncr = QualityNcr::query()->create([
                    ...$fields,
                    'number' => $this->nextNumber($now),
                    'qc_unit_id' => $qcUnitId,
                    'responsible_unit_id' => $responsible->id,
                    'stage' => NcrStage::Finding,
                    'cycle' => 1,
                    'source_type' => $source,
                    'source_reference' => $reference,
                    'production_record_id' => $record?->id,
                    'description' => $description,
                    'detected_at' => $detectedAt,
                    'repeat_of_ncr_id' => $repeatOf?->id,
                    'kpi_definition_version_id' => $kpi?->id,
                    'owner_user_id' => $owner->id,
                    'raised_by_user_id' => $user->id,
                ]);
                $this->enter($ncr, NcrStage::Finding, $pic, $due, $now);
                $this->event($ncr, $user, 'raised', null, NcrStage::Finding, null, null, $now);
                $this->audited($actor, $ncr, 'quality_ncr.raised', null, ['stage' => NcrStage::Finding->value, 'source' => $source->value, 'responsible_unit' => $responsible->code, 'repeat_of' => $repeatOf?->number, 'kpi_version' => $kpi?->public_id], null);

                return $ncr->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('duplicate_ncr', 'NCR untuk sumber dan kategori defect ini sudah ada.');
        }
    }

    /**
     * Completes the open stage and enters the next one, or closes the NCR
     * after verification.
     *
     * @param  array<string, mixed>  $input  content, evidence_reference, evidence_restricted, root_cause_method, preventive_action, effectiveness_result, next_pic_user_id, next_due_date
     */
    public function advance(Actor $actor, User $user, int $ncrId, int $expectedVersion, array $input): QualityNcr
    {
        $this->assertActor($actor, $user);
        $rules = $this->rulesOrFail();

        return $this->transactions->run(function () use ($actor, $user, $ncrId, $expectedVersion, $input, $rules): QualityNcr {
            [$ncr, $open] = $this->open($ncrId);

            if (! $this->access->canComplete($user, $ncr, $open)) {
                throw new AuthorizationException;
            }

            $stage = $open->stage;
            $next = $stage->next() ?? throw new LogicException('A closed NCR has no open stage.');
            $completion = $this->completion($rules, $stage, $input);
            $now = CarbonImmutable::now();
            $closing = $next === NcrStage::Closed;
            $pic = $closing ? null : $this->activeUser($input['next_pic_user_id'] ?? null, 'PIC tahap berikutnya');
            $due = $closing ? null : $this->dueDate(self::text($input, 'next_due_date'));
            $ncr = $this->locks->update($ncr, $expectedVersion, $closing ? ['stage' => $next, 'closed_at' => $now] : ['stage' => $next]);
            $open->forceFill([...$completion, 'outcome' => QualityNcrStage::COMPLETED, 'completed_by_user_id' => $user->id, 'completed_at' => $now])->save();

            if ($pic !== null && $due !== null) {
                $this->enter($ncr, $next, $pic, $due, $now);
            }

            $action = $closing ? 'closed' : 'advanced';
            $this->event($ncr, $user, $action, $stage, $next, null, null, $now);
            $this->audited($actor, $ncr, "quality_ncr.{$action}", ['stage' => $stage->value], ['stage' => $next->value, 'evidence' => $completion['evidence_reference'] !== null, 'effectiveness' => $completion['effectiveness_result']], null);

            return $ncr;
        });
    }

    /**
     * A verification that finds the corrective action insufficient returns
     * the NCR to corrective action.
     *
     * @param  array<string, mixed>  $input  content, evidence_reference, evidence_restricted, next_pic_user_id, next_due_date
     */
    public function returnToCorrectiveAction(Actor $actor, User $user, int $ncrId, int $expectedVersion, string $reason, array $input): QualityNcr
    {
        $this->assertActor($actor, $user);
        $reason = self::requireText($reason, 'reason', 'Alasan verifikasi gagal wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $ncrId, $expectedVersion, $reason, $input): QualityNcr {
            [$ncr, $open] = $this->open($ncrId);

            if (! $this->access->isQcManager($user)) {
                throw new AuthorizationException;
            }

            if ($open->stage !== NcrStage::Verification) {
                throw new BusinessRuleViolation('invalid_transition', 'Hanya NCR pada tahap Verification yang dapat dikembalikan ke Corrective Action.');
            }

            $pic = $this->activeUser($input['next_pic_user_id'] ?? null, 'PIC tahap berikutnya');
            $due = $this->dueDate(self::text($input, 'next_due_date'));
            $now = CarbonImmutable::now();
            $ncr = $this->locks->update($ncr, $expectedVersion, ['stage' => NcrStage::CorrectiveAction]);
            $open->forceFill([
                'content' => self::bounded($input, 'content', 2000, 'Hasil verifikasi') ?? $reason,
                'evidence_reference' => self::bounded($input, 'evidence_reference', 500, 'Referensi bukti'),
                'evidence_restricted' => (bool) ($input['evidence_restricted'] ?? false),
                'outcome' => QualityNcrStage::RETURNED,
                'completed_by_user_id' => $user->id,
                'completed_at' => $now,
            ])->save();
            $this->enter($ncr, NcrStage::CorrectiveAction, $pic, $due, $now);
            $this->event($ncr, $user, 'returned', NcrStage::Verification, NcrStage::CorrectiveAction, null, $reason, $now);
            $this->audited($actor, $ncr, 'quality_ncr.returned', ['stage' => NcrStage::Verification->value], ['stage' => NcrStage::CorrectiveAction->value], $reason);

            return $ncr;
        });
    }

    /**
     * Reopens a closed NCR at an investigation stage. The closed cycle stays
     * as it was; the new cycle starts beside it.
     */
    public function reopen(Actor $actor, User $user, int $ncrId, int $expectedVersion, NcrStage $target, string $reason, int $picUserId, string $dueDate): QualityNcr
    {
        $this->assertActor($actor, $user);
        $reason = self::requireText($reason, 'reason', 'Alasan membuka kembali NCR wajib diisi.', 1000);

        if (! in_array($target, NcrStage::reopenTargets(), true)) {
            throw new BusinessRuleViolation('invalid_transition', 'NCR dapat dibuka kembali pada tahap Containment, Root Cause, atau Corrective Action.');
        }

        $pic = $this->activeUser($picUserId, 'PIC tahap');
        $due = $this->dueDate($dueDate);

        return $this->transactions->run(function () use ($actor, $user, $ncrId, $expectedVersion, $target, $reason, $pic, $due): QualityNcr {
            $ncr = QualityNcr::query()->lockForUpdate()->findOrFail($ncrId);

            if (! $this->access->isQcManager($user)) {
                throw new AuthorizationException;
            }

            if ($ncr->stage !== NcrStage::Closed) {
                throw new BusinessRuleViolation('invalid_transition', 'Hanya NCR yang sudah Closed yang dapat dibuka kembali.');
            }

            $now = CarbonImmutable::now();
            $ncr = $this->locks->update($ncr, $expectedVersion, ['stage' => $target, 'cycle' => $ncr->cycle + 1, 'closed_at' => null]);
            $this->enter($ncr, $target, $pic, $due, $now);
            $this->event($ncr, $user, 'reopened', NcrStage::Closed, $target, null, $reason, $now);
            $this->audited($actor, $ncr, 'quality_ncr.reopened', ['stage' => NcrStage::Closed->value], ['stage' => $target->value, 'cycle' => $ncr->cycle], $reason);

            return $ncr;
        });
    }

    /**
     * Corrects the NCR header. The before and after stay in the history.
     *
     * @param  array<string, mixed>  $changes  category_code, severity, quantity, unit, description, kpi_code, repeat_of
     */
    public function correct(Actor $actor, User $user, int $ncrId, int $expectedVersion, array $changes, string $reason): QualityNcr
    {
        $this->assertActor($actor, $user);
        $reason = self::requireText($reason, 'reason', 'Alasan koreksi wajib diisi.', 1000);
        $rules = $this->rulesOrFail();

        return $this->transactions->run(function () use ($actor, $user, $ncrId, $expectedVersion, $changes, $reason, $rules): QualityNcr {
            $ncr = QualityNcr::query()->lockForUpdate()->findOrFail($ncrId);

            if (! $this->access->isQcManager($user)) {
                throw new AuthorizationException;
            }

            $after = [];

            if (array_key_exists('category_code', $changes)) {
                $after['category_code'] = $this->category($rules, self::text($changes, 'category_code'));
                $this->assertCodes(['category_code' => $after['category_code']]);
            }

            if (array_key_exists('severity', $changes)) {
                $after['severity'] = $this->severity($rules, self::text($changes, 'severity'));
            }

            if (array_key_exists('quantity', $changes) || array_key_exists('unit', $changes)) {
                $after['quantity'] = array_key_exists('quantity', $changes) ? (self::text($changes, 'quantity') ?: null) : $ncr->quantity;
                $after['unit'] = array_key_exists('unit', $changes) ? (self::text($changes, 'unit') ?: null) : $ncr->unit;
                $this->assertQuantity($after['quantity'], $after['unit']);
            }

            if (array_key_exists('description', $changes)) {
                $after['description'] = self::requireText(self::text($changes, 'description'), 'description', 'Uraian temuan wajib diisi.', 2000);
            }

            if (array_key_exists('kpi_code', $changes)) {
                $after['kpi_definition_version_id'] = $this->kpiVersion(self::text($changes, 'kpi_code'))?->id;
            }

            if (array_key_exists('repeat_of', $changes)) {
                $repeat = $this->repeatOf($user, self::text($changes, 'repeat_of'));

                if ($repeat !== null && $this->leadsTo($repeat, $ncr->id)) {
                    throw new BusinessRuleViolation('invalid_repeat', 'Tautan repeat tidak boleh membentuk lingkaran kembali ke NCR ini.');
                }

                $after['repeat_of_ncr_id'] = $repeat?->id;
            }

            $before = [];

            foreach ($after as $field => $value) {
                $current = $ncr->getAttribute($field);

                if ((string) $current === (string) $value || ($field === 'quantity' && $current !== null && $value !== null && BigDecimal::of((string) $current)->isEqualTo((string) $value))) {
                    unset($after[$field]);

                    continue;
                }

                $before[$field] = $current;
            }

            if ($after === []) {
                throw new BusinessRuleViolation('nothing_to_correct', 'Tidak ada perubahan.');
            }

            if (array_key_exists('category_code', $after) && QualityNcr::query()->where('source_type', $ncr->source_type->value)->where('source_reference', $ncr->source_reference)->where('category_code', $after['category_code'])->exists()) {
                throw new BusinessRuleViolation('duplicate_ncr', 'NCR untuk sumber dan kategori defect ini sudah ada.');
            }

            $now = CarbonImmutable::now();

            try {
                $ncr = $this->locks->update($ncr, $expectedVersion, $after);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('duplicate_ncr', 'NCR untuk sumber dan kategori defect ini sudah ada.');
            }

            $this->event($ncr, $user, 'corrected', $ncr->stage, $ncr->stage, ['before' => $before, 'after' => $after], $reason, $now);
            $this->audited($actor, $ncr, 'quality_ncr.corrected', $before, $after, $reason);

            return $ncr;
        });
    }

    /**
     * @return array{0: QualityNcr, 1: QualityNcrStage}
     */
    private function open(int $ncrId): array
    {
        $ncr = QualityNcr::query()->lockForUpdate()->findOrFail($ncrId);
        $open = QualityNcrStage::query()->where('quality_ncr_id', $ncr->id)->whereNull('completed_at')->first();

        if ($open === null) {
            throw new BusinessRuleViolation('invalid_transition', 'NCR ini sudah Closed. Buka kembali bila perlu tindak lanjut.');
        }

        return [$ncr, $open];
    }

    /**
     * What completing a stage records, checked against the approved rules.
     *
     * @param  array<string, mixed>  $input
     * @return array{content: string|null, evidence_reference: string|null, evidence_restricted: bool, root_cause_method: string|null, preventive_action: string|null, effectiveness_result: string|null}
     */
    private function completion(QualityRules $rules, NcrStage $stage, array $input): array
    {
        $content = self::bounded($input, 'content', 2000, $stage->contentLabel());
        $evidence = self::bounded($input, 'evidence_reference', 500, 'Referensi bukti');
        $method = self::text($input, 'root_cause_method') ?: null;
        $preventive = self::bounded($input, 'preventive_action', 2000, 'Preventive action');
        $effectiveness = self::text($input, 'effectiveness_result') ?: null;

        if ($stage !== NcrStage::Finding && $content === null) {
            throw new BusinessRuleViolation('content_required', "{$stage->contentLabel()} wajib diisi.");
        }

        if ($evidence === null && $rules->needsEvidence($stage)) {
            throw new BusinessRuleViolation('evidence_required', $stage === NcrStage::Verification
                ? 'NCR tidak dapat ditutup tanpa bukti verifikasi.'
                : "Tahap {$stage->label()} membutuhkan referensi bukti.");
        }

        if ($method !== null && ($stage !== NcrStage::RootCause || ! in_array($method, $rules->rootCauseMethods, true))) {
            throw new BusinessRuleViolation('invalid_root_cause_method', $rules->rootCauseMethods === []
                ? 'Metode root cause belum disetujui; tuliskan metode dan rujukannya pada uraian akar masalah.'
                : 'Metode root cause tidak dikenal.');
        }

        if ($preventive !== null && ($stage !== NcrStage::CorrectiveAction || ! $rules->preventiveActionEnabled)) {
            throw new BusinessRuleViolation('preventive_action_disabled', 'Preventive action belum disetujui sebagai bagian CAPA.');
        }

        if ($stage === NcrStage::Verification && $rules->effectivenessReviewRequired && $effectiveness !== 'effective') {
            throw new BusinessRuleViolation('effectiveness_required', 'NCR hanya dapat ditutup setelah effectiveness review menyatakan tindakan efektif.');
        }

        if ($effectiveness !== null && ($stage !== NcrStage::Verification || ! $rules->effectivenessReviewRequired || ! in_array($effectiveness, ['effective', 'not_effective'], true))) {
            throw new BusinessRuleViolation('effectiveness_not_required', 'Effectiveness review belum diwajibkan atau hasilnya tidak dikenal.');
        }

        return [
            'content' => $content,
            'evidence_reference' => $evidence,
            'evidence_restricted' => $evidence !== null && (bool) ($input['evidence_restricted'] ?? false),
            'root_cause_method' => $method,
            'preventive_action' => $preventive,
            'effectiveness_result' => $effectiveness,
        ];
    }

    private function enter(QualityNcr $ncr, NcrStage $stage, User $pic, string $due, CarbonImmutable $now): void
    {
        QualityNcrStage::query()->create([
            'quality_ncr_id' => $ncr->id,
            'sequence' => (int) QualityNcrStage::query()->where('quality_ncr_id', $ncr->id)->max('sequence') + 1,
            'cycle' => $ncr->cycle,
            'stage' => $stage,
            'pic_user_id' => $pic->id,
            'due_date' => $due,
            'entered_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function event(QualityNcr $ncr, User $user, string $action, ?NcrStage $from, ?NcrStage $to, ?array $changes, ?string $reason, CarbonImmutable $now): void
    {
        QualityNcrEvent::query()->create([
            'quality_ncr_id' => $ncr->id,
            'sequence' => (int) QualityNcrEvent::query()->where('quality_ncr_id', $ncr->id)->max('sequence') + 1,
            'action' => $action,
            'from_stage' => $from,
            'to_stage' => $to,
            'changes' => $changes,
            'reason' => $reason,
            'actor_user_id' => $user->id,
            'occurred_at' => $now,
        ]);
    }

    /**
     * Audit names the NCR, stages, and changed fields; evidence references
     * and stage content stay in the NCR history only.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audited(Actor $actor, QualityNcr $ncr, string $action, ?array $before, array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'quality_ncr',
            entityId: $ncr->number,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }

    /**
     * The inspection row of a current QC inspection batch.
     */
    private function inspection(int $qcUnitId, string $reference): ProductionRecord
    {
        $found = ProductionRecord::query()
            ->where('record_type', ProductionRecord::INSPECTION)
            ->where('event_key', $reference)
            ->whereIn('production_batch_id', ProductionBatch::query()->current()->where('organization_unit_id', $qcUnitId)->where('kind', BatchKind::Inspection->value)->select('id'))
            ->limit(2)
            ->get();

        if ($found->count() > 1) {
            throw new BusinessRuleViolation('ambiguous_inspection', 'ID inspeksi ini ada pada lebih dari satu batch inspeksi yang berlaku.');
        }

        return $found->first() ?? throw new BusinessRuleViolation('unknown_inspection', 'Inspeksi tidak ditemukan pada batch inspeksi QC yang berlaku.');
    }

    /**
     * A defect category from the approved taxonomy, or as the source gives
     * it while no taxonomy is approved.
     */
    private function category(QualityRules $rules, string $category): string
    {
        if ($category === '') {
            throw new BusinessRuleViolation('category_required', 'Kategori defect wajib diisi.');
        }

        if ($rules->defectCategories !== [] && ! in_array($category, $rules->defectCategories, true)) {
            throw new BusinessRuleViolation('unknown_category', 'Kategori defect tidak ada pada taxonomy yang disetujui.');
        }

        return $category;
    }

    /**
     * Without an approved severity scale an NCR carries no severity.
     */
    private function severity(QualityRules $rules, string $severity): ?string
    {
        if ($rules->severities === [] && $severity !== '') {
            throw new BusinessRuleViolation('severity_not_approved', 'Skala severity belum disetujui; NCR dicatat tanpa severity.');
        }

        if ($rules->severities !== [] && ! in_array($severity, $rules->severities, true)) {
            throw new BusinessRuleViolation('unknown_severity', 'Severity wajib dipilih dari skala yang disetujui.');
        }

        return $severity === '' ? null : $severity;
    }

    /**
     * Whether following repeat links from an NCR reaches another one.
     */
    private function leadsTo(QualityNcr $start, int $ncrId): bool
    {
        $seen = [];
        $current = $start;

        while ($current !== null && ! isset($seen[$current->id])) {
            if ($current->id === $ncrId) {
                return true;
            }

            $seen[$current->id] = true;
            $current = $current->repeat_of_ncr_id === null ? null : QualityNcr::query()->find($current->repeat_of_ncr_id);
        }

        return false;
    }

    private function activeUnit(string $code): OrganizationUnit
    {
        $unit = $code === '' ? null : OrganizationUnit::query()->where('code', $code)->first();

        if ($unit === null || ! in_array($unit->id, array_map(static fn ($node): int => $node->id, $this->directory->snapshot($this->time->businessDate(CarbonImmutable::now()))->all()), true)) {
            throw new BusinessRuleViolation('unknown_unit', 'Team penanggung jawab tidak ditemukan atau tidak aktif.');
        }

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function assertCodes(array $fields): void
    {
        foreach (['category_code', 'work_order', 'product_code', 'lot', 'process_code', 'machine_code', 'shift_code'] as $field) {
            $value = $fields[$field] ?? null;

            if ($value !== null && $value !== '' && preg_match(self::CODE, (string) $value) !== 1) {
                throw new BusinessRuleViolation('invalid_code', "{$field} harus berupa kode tanpa spasi.");
            }
        }
    }

    private function assertQuantity(mixed $quantity, mixed $unit): void
    {
        if ($quantity === null || $quantity === '') {
            return;
        }

        if (preg_match(self::QUANTITY, (string) $quantity) !== 1 || preg_match('/^0+(?:\.0+)?$/', (string) $quantity) === 1) {
            throw new BusinessRuleViolation('invalid_quantity', 'Kuantitas defect harus angka positif dengan titik desimal (maks. 3 desimal).');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,19}$/', (string) $unit) !== 1) {
            throw new BusinessRuleViolation('invalid_quantity', 'Kuantitas defect membutuhkan kode satuan.');
        }
    }

    private function moment(string $value): CarbonImmutable
    {
        $value = str_replace('T', ' ', $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new DateTimeZone($this->time->timezoneName()));

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $value) {
            throw new BusinessRuleViolation('invalid_detected_at', 'Waktu ditemukan wajib diisi (YYYY-MM-DD HH:MM).');
        }

        if ($parsed > CarbonImmutable::now()) {
            throw new BusinessRuleViolation('invalid_detected_at', 'Waktu ditemukan tidak boleh di masa depan.');
        }

        return CarbonImmutable::instance($parsed)->utc();
    }

    private function dueDate(string $value): string
    {
        self::requirePeriod($value);

        if ($value < $this->time->businessDate(CarbonImmutable::now())) {
            throw new BusinessRuleViolation('invalid_due_date', 'Tenggat tahap tidak boleh sebelum hari ini.');
        }

        return $value;
    }

    private function activeUser(mixed $id, string $label): User
    {
        $user = is_numeric($id) ? User::query()->find((int) $id) : null;

        if ($user === null || $user->status !== AccountStatus::Active) {
            throw new BusinessRuleViolation('invalid_pic', "{$label} harus akun aktif.");
        }

        return $user;
    }

    private function repeatOf(User $user, string $number): ?QualityNcr
    {
        if ($number === '') {
            return null;
        }

        $previous = QualityNcr::query()->where('number', $number)->first();

        if ($previous === null || ! $this->access->canRead($user, $previous)) {
            throw new BusinessRuleViolation('invalid_repeat', 'NCR sebelumnya tidak ditemukan.');
        }

        return $previous;
    }

    /**
     * The published version of a KPI at the time of linking: the NCR names
     * the exact definition version it relates to.
     */
    private function kpiVersion(string $code): ?KpiDefinitionVersion
    {
        if ($code === '') {
            return null;
        }

        $definition = KpiDefinition::query()->where('code', strtoupper($code))->first() ?? throw new BusinessRuleViolation('kpi_not_found', 'Kode KPI tidak ditemukan.');

        return KpiDefinitionVersion::query()->where('kpi_definition_id', $definition->id)->where('status', LifecycleStatus::Published->value)->orderByDesc('version_number')->first()
            ?? throw new BusinessRuleViolation('kpi_not_published', 'KPI ini belum memiliki versi yang dipublikasikan.');
    }

    private function nextNumber(CarbonImmutable $now): string
    {
        $year = substr($this->time->businessDate($now), 0, 4);
        $prefix = "NCR-{$year}-";
        $last = QualityNcr::query()->where('number', 'like', "{$prefix}%")->orderByRaw('LENGTH(number) DESC')->orderByDesc('number')->value('number');

        return sprintf('%s%04d', $prefix, $last === null ? 1 : (int) substr((string) $last, strlen($prefix)) + 1);
    }

    private function rulesOrFail(): QualityRules
    {
        try {
            return self::rules();
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('quality_rules_invalid', 'Pengaturan quality tidak valid: '.$exception->getMessage());
        }
    }

    private function assertActor(Actor $actor, User $user): void
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the acting account must be the same.');
        }
    }

    /**
     * Optional text that is refused, never cut, when it is too long: a
     * completed stage cannot be corrected later.
     *
     * @param  array<string, mixed>  $input
     */
    private static function bounded(array $input, string $key, int $max, string $label): ?string
    {
        $value = self::text($input, $key);

        if (mb_strlen($value) > $max) {
            throw new BusinessRuleViolation('text_too_long', "{$label} maksimal {$max} karakter.");
        }

        return $value === '' ? null : $value;
    }

    private static function positiveOrNull(?string $quantity): ?string
    {
        return $quantity === null || BigDecimal::of($quantity)->isZero() ? null : $quantity;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
