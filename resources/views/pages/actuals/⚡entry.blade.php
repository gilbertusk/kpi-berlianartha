<?php

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Application\ActualQuery;
use App\Modules\ActualEvidence\Application\ActualRow;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Domain\EvidenceRules;
use App\Modules\ActualEvidence\Domain\Exceptions\ActualEntriesRejected;
use App\Modules\ActualEvidence\Domain\ObservationWindow;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\EvidenceFile;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Domain\Vocabulary;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Aktual')] class extends GuardedPage {
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $periodId = '';

    #[Url(as: 'dari')]
    public string $windowStart = '';

    #[Url(as: 'sampai')]
    public string $windowEnd = '';

    #[Url(as: 'unit')]
    public string $unit = '';

    #[Url(as: 'status')]
    public string $state = '';

    #[Url(as: 'cari')]
    public string $search = '';

    /** @var array<string, string> target public id => value typed */
    public array $values = [];

    /** @var array<string, bool> target public id => entered as not applicable */
    public array $notApplicable = [];

    /** @var array<string, string> target public id => note */
    public array $notes = [];

    /** @var array<string, int> target public id => draft lock version first shown (0 = no entry) */
    #[Locked]
    public array $locks = [];

    /** @var array<string, list<string>> target public id => messages of the last rejected save */
    #[Locked]
    public array $rowErrors = [];

    public string $lateReason = '';

    #[Locked]
    public ?string $detailOf = null;

    #[Locked]
    public ?string $rejecting = null;

    #[Locked]
    public int $rejectingLock = 0;

    public string $rejectReason = '';

    /** @var TemporaryUploadedFile|null */
    public $evidenceFile = null;

    #[Locked]
    public ?string $attachingTo = null;

    public function mount(AssessmentPeriod $period): void
    {
        $this->authorize('viewActuals', $period);
        $this->periodId = $period->public_id;
        $this->windowStart = $this->windowStart !== '' ? $this->windowStart : $period->starts_on;
        $this->windowEnd = $this->windowEnd !== '' ? $this->windowEnd : $period->ends_on;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['unit', 'state', 'search', 'windowStart', 'windowEnd'], true)) {
            $this->resetPage();
            unset($this->rows);
        }

        // Typed values belong to one observation window; a filter or search
        // keeps them, another window starts clean.
        if (in_array($property, ['windowStart', 'windowEnd'], true)) {
            $this->reset('values', 'notApplicable', 'notes', 'locks', 'rowErrors');
        }
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->with(['scopeUnit', 'deadlines'])->where('public_id', $this->periodId)->firstOrFail();
    }

    /**
     * @return LengthAwarePaginator<int, ActualRow>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        // The window comes from the URL and a live input; only real dates may
        // reach the query.
        [$windowStart, $windowEnd] = $this->window();

        $rows = app(ActualQuery::class)->rows(
            $this->period,
            $this->accessFor(Capability::ActualView),
            $windowStart,
            $windowEnd,
            $this->unit === '' ? null : $this->unit,
            array_key_exists($this->state, ActualQuery::STATES) ? $this->state : null,
            $this->search,
            $this->getPage(),
        );

        // Shows the current entry until the user types, and remembers which
        // version the user saw so a save cannot overwrite a newer one.
        foreach ($rows as $row) {
            $id = $row->target->public_id;

            if (isset($this->locks[$id])) {
                continue;
            }

            $editable = $row->version !== null && $row->version->status->isEditable();
            $this->locks[$id] = $editable ? (int) $row->version?->lock_version : 0;

            if ($editable) {
                $isValue = $row->version?->entry_kind === EntryKind::Value;
                $this->values[$id] ??= $isValue ? (string) $row->version?->displayValue() : '';
                $this->notApplicable[$id] ??= ! $isValue;
                $this->notes[$id] ??= (string) $row->version?->note;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function completeness(): array
    {
        return app(ActualQuery::class)->completeness($this->period, $this->accessFor(Capability::ActualView));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        return app(ActualQuery::class)->unitOptions($this->period, $this->accessFor(Capability::ActualView));
    }

    #[Computed]
    public function inProgress(): bool
    {
        return $this->period->status === PeriodStatus::InProgress;
    }

    #[Computed]
    public function canEnter(): bool
    {
        return $this->inProgress && $this->currentUser()->can('enterActuals', $this->period);
    }

    #[Computed]
    public function canVerify(): bool
    {
        return $this->inProgress && $this->currentUser()->can('verifyActuals', $this->period);
    }

    /**
     * File import is a bulk tool for accounts that enter actuals for units,
     * not for an employee's own entries.
     */
    #[Computed]
    public function canImport(): bool
    {
        return $this->canEnter && $this->accessFor(Capability::ActualEnter)->units->reachesOthers();
    }

    #[Computed]
    public function isLate(): bool
    {
        return app(ActualService::class)->isLate($this->period);
    }

    #[Computed]
    public function detailTarget(): ?PeriodTarget
    {
        return $this->detailOf === null ? null : $this->findTarget($this->detailOf, Capability::ActualView);
    }

    /**
     * @return Collection<int, KpiActual>|null
     */
    #[Computed]
    public function entries(): ?Collection
    {
        return $this->detailTarget === null ? null : app(ActualQuery::class)->entries($this->detailTarget);
    }

    #[Computed]
    public function audit(): ?LengthAwarePaginator
    {
        return $this->detailTarget === null ? null : app(ActualQuery::class)->auditTrail($this->detailTarget, $this->getPage('auditPage'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function actorNames(): array
    {
        $ids = collect($this->audit?->items() ?? [])->pluck('actor_identifier')->filter()->unique()->all();

        return User::query()->whereIn('public_id', $ids)->pluck('name', 'public_id')->all();
    }

    public function saveDrafts(ActualService $actuals): void
    {
        $this->authorize('enterActuals', $this->period);
        $this->validate([
            'windowStart' => ['required', 'date_format:Y-m-d'],
            'windowEnd' => ['required', 'date_format:Y-m-d'],
            'values.*' => ['nullable', 'string', 'max:40'],
            'notes.*' => ['nullable', 'string', 'max:1000'],
            'lateReason' => ['nullable', 'string', 'max:2000'],
        ]);

        $entries = [];
        $blocked = [];

        foreach ($this->rows as $row) {
            $id = $row->target->public_id;
            $typed = trim((string) ($this->values[$id] ?? ''));
            $isNotApplicable = (bool) ($this->notApplicable[$id] ?? false);

            if ($typed === '' && ! $isNotApplicable) {
                continue;
            }

            // Someone submitted or verified this entry after the page loaded.
            if (! $row->acceptsEntry()) {
                $blocked[$id] = [__('Aktual ini sudah diajukan atau diverifikasi dan tidak dapat diubah. Nilai yang Anda ketik tidak disimpan.')];

                continue;
            }

            $entries[] = new ActualEntry(
                reference: $id,
                targetId: $row->target->id,
                windowStart: $this->windowStart,
                windowEnd: $this->windowEnd,
                value: $isNotApplicable ? null : $typed,
                unit: (string) $row->target->definitionVersion->unit,
                note: $this->notes[$id] ?? null,
                expectedLockVersion: $this->locks[$id] ?? null,
            );
        }

        $result = null;
        $this->rowErrors = $blocked;

        if ($blocked !== []) {
            $this->flash = null;
            $this->addError('action', __(':count aktual tidak dapat diubah. Tidak ada aktual yang disimpan.', ['count' => count($blocked)]));

            return;
        }

        $saved = $this->attempt('action', __('Aktual disimpan sebagai draft.'), function () use ($actuals, $entries, &$result): void {
            try {
                $result = $actuals->saveDrafts($this->actorFor(Capability::ActualEnter), $this->period->id, $this->accessFor(Capability::ActualEnter), $entries, $this->lateReason);
            } catch (ActualEntriesRejected $rejected) {
                $this->rowErrors = $rejected->rowErrors;

                throw $rejected;
            }
        });

        if ($saved && is_array($result)) {
            $this->flash = __('Draft aktual disimpan: :created baru, :updated diperbarui, :unchanged tidak berubah.', $result);
            $this->refreshState();
        }
    }

    public function submitDrafts(ActualService $actuals): void
    {
        $this->authorize('enterActuals', $this->period);
        $count = 0;

        $done = $this->attempt('action', __('Aktual diajukan.'), function () use ($actuals, &$count): void {
            // Only entries this account wrote: a bulk submit must not lock a
            // colleague's unfinished draft.
            $count = $actuals->submit($this->actorFor(Capability::ActualEnter), $this->period->id, $this->accessFor(Capability::ActualEnter), null, $this->lateReason, true);
        });

        if ($done) {
            $this->flash = __(':count aktual diajukan untuk verifikasi.', ['count' => $count]);
            $this->refreshState();
        }
    }

    public function verifySubmitted(ActualService $actuals): void
    {
        $this->authorize('verifyActuals', $this->period);
        $result = null;

        $done = $this->attempt('action', __('Aktual diverifikasi.'), function () use ($actuals, &$result): void {
            $result = $actuals->verify($this->actorFor(Capability::ActualVerify), $this->period->id, $this->accessFor(Capability::ActualVerify));
        });

        if ($done && is_array($result)) {
            $this->flash = __(':verified aktual terverifikasi. :skipped_self dilewati karena milik atau isian Anda sendiri; :blocked_evidence tertahan karena bukti belum lolos pemindaian.', $result);
            $this->refreshState();
        }
    }

    public function startReject(string $actualId): void
    {
        $actual = $this->findActual($actualId, Capability::ActualVerify);

        $this->rejecting = $actual->public_id;
        $this->rejectingLock = (int) $actual->versions->last()?->lock_version;
        $this->rejectReason = '';
    }

    public function confirmReject(ActualService $actuals): void
    {
        $actual = $this->findActual((string) $this->rejecting, Capability::ActualVerify);
        $this->validate(['rejectReason' => ['required', 'string', 'max:2000']]);

        $version = $actual->versions->last();
        abort_if($version === null, 404);

        $done = $this->attempt('action', __('Aktual ditolak dan dikembalikan ke pengisi.'), fn () => $actuals->reject(
            $this->actorFor(Capability::ActualVerify, $this->access()->unitTarget($actual->target->organization_unit_id)),
            $version->id,
            $this->rejectingLock,
            $this->accessFor(Capability::ActualVerify),
            $this->rejectReason,
        ));

        if ($done) {
            $this->rejecting = null;
            $this->refreshState();
        }
    }

    public function showDetail(string $targetId): void
    {
        $this->detailOf = $this->findTarget($targetId, Capability::ActualView)->public_id;
        $this->attachingTo = null;
        $this->resetPage('auditPage');
        unset($this->detailTarget, $this->entries, $this->audit, $this->actorNames);
    }

    public function startAttach(string $actualId): void
    {
        $this->attachingTo = $this->findActual($actualId, Capability::ActualEnter)->public_id;
        $this->reset('evidenceFile');
        $this->resetErrorBag();
    }

    public function attachEvidence(EvidenceService $files): void
    {
        $actual = $this->findActual((string) $this->attachingTo, Capability::ActualEnter);
        $this->validate(['evidenceFile' => ['required', 'file', 'max:'.(int) ceil(config('evidence.max_bytes') / 1024)]], [], ['evidenceFile' => __('file bukti')]);

        $file = $this->evidenceFile;
        abort_unless($file instanceof TemporaryUploadedFile, 422);

        $done = $this->attempt('evidence', __('Bukti dilampirkan.'), fn () => $files->attach(
            $this->actorFor(Capability::ActualEnter),
            $actual->id,
            $file->getClientOriginalName(),
            (string) $file->get(),
        ));

        $file->delete();
        $this->reset('evidenceFile');

        if ($done) {
            $this->attachingTo = null;
            unset($this->entries, $this->audit, $this->actorNames, $this->rows);
        }
    }

    public function removeEvidence(string $evidenceId, EvidenceService $files): void
    {
        $evidence = $this->findEvidence($evidenceId, Capability::ActualEnter);

        if ($this->attempt('evidence', __('Bukti dilepas dari aktual.'), fn () => $files->remove($this->actorFor(Capability::ActualEnter), $evidence->id))) {
            unset($this->entries, $this->audit, $this->actorNames, $this->rows);
        }
    }

    public function openEvidence(string $evidenceId, EvidenceService $files): void
    {
        $evidence = $this->findEvidence($evidenceId, Capability::ActualView);
        $link = null;

        $this->attempt('evidence', __('Tautan bukti dibuat.'), function () use ($files, $evidence, &$link): void {
            $link = $files->linkFor($evidence, $this->currentUser());
        });

        if (is_string($link)) {
            $this->flash = null;
            $this->redirect($link);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        return ObservationWindow::isDate($this->windowStart) && ObservationWindow::isDate($this->windowEnd)
            ? [$this->windowStart, $this->windowEnd]
            : [$this->period->starts_on, $this->period->ends_on];
    }

    private function accessFor(Capability $capability): ActualAccess
    {
        return app(ActualPeriodAccess::class)->scope($this->currentUser(), $capability);
    }

    private function findTarget(string $publicId, Capability $capability): PeriodTarget
    {
        $target = PeriodTarget::query()
            ->with('definitionVersion')
            ->where('assessment_period_id', $this->period->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        abort_unless($this->accessFor($capability)->covers($target), 403);

        return $target;
    }

    private function findActual(string $publicId, Capability $capability): KpiActual
    {
        $actual = KpiActual::query()
            ->with(['target', 'versions'])
            ->where('public_id', $publicId)
            ->whereHas('target', fn ($target) => $target->where('assessment_period_id', $this->period->id))
            ->firstOrFail();

        abort_unless($this->accessFor($capability)->covers($actual->target), 403);

        return $actual;
    }

    private function findEvidence(string $publicId, Capability $capability): EvidenceFile
    {
        $evidence = EvidenceFile::query()->with('actual.target')->where('public_id', $publicId)->firstOrFail();

        abort_unless($evidence->actual->target->assessment_period_id === $this->period->id, 404);
        abort_unless($this->accessFor($capability)->covers($evidence->actual->target), 403);

        return $evidence;
    }

    private function refreshState(): void
    {
        $this->reset('values', 'notApplicable', 'notes', 'locks', 'lateReason');
        unset($this->rows, $this->completeness, $this->entries, $this->audit, $this->actorNames);
    }
}; ?>

@php
    $period = $this->period;
    $completeness = $this->completeness;
    $unitLabel = fn (?string $unit): string => Vocabulary::label(Vocabulary::UNITS, $unit);
    $stateTone = fn (string $state): string => match ($state) { 'verified' => 'success', 'submitted' => 'info', 'rejected' => 'danger', 'draft' => 'neutral', default => 'warning' };
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Aktual').' — '.$period->name" :description="$period->code.' · '.$period->starts_on.' — '.$period->ends_on.' · '.$period->status->label()">
        <x-slot:actions>
            @if ($this->canImport)
                <x-ui.button variant="secondary" :href="route('actuals.import', $period)" wire:navigate>{{ __('Import aktual') }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" :href="route('actuals.index')" wire:navigate>{{ __('Kembali ke daftar') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'evidence']])

    <section class="flex flex-col gap-2" aria-labelledby="completeness-heading">
        <h2 id="completeness-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Kelengkapan aktual') }}</h2>
        <p class="text-sm text-zinc-700 dark:text-zinc-300">
            {{ __('Target wajib: :targets · Belum ada aktual: :missing · Draft: :draft · Ditolak: :rejected · Menunggu verifikasi: :submitted · Terverifikasi: :verified · Entri terlambat: :late', $completeness) }}
        </p>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Nilai nol adalah nilai. N/A adalah entri tersendiri dengan alasan. "Belum ada aktual" berarti tidak ada entri sama sekali.') }}</p>
    </section>

    @if (! $this->inProgress)
        <x-ui.alert variant="warning" :title="__('Aktual periode ini tidak dapat diubah')">
            {{ __('Aktual diisi dan diverifikasi hanya selama periode berstatus In progress.') }}
        </x-ui.alert>
    @elseif ($this->isLate)
        <x-ui.alert variant="warning" :title="__('Batas waktu pengisian aktual sudah lewat')">
            {{ __('Setiap entri dan pengajuan wajib disertai alasan keterlambatan dan ditandai terlambat.') }}
        </x-ui.alert>
    @endif

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-5" role="search" aria-label="{{ __('Filter aktual') }}">
        <x-ui.input name="windowStart" type="date" :label="__('Observasi dari')" :hint="__('KPI harian diisi per tanggal.')" wire:model.live="windowStart" />
        <x-ui.input name="windowEnd" type="date" :label="__('Observasi sampai')" wire:model.live="windowEnd" />
        <x-ui.input name="search" type="search" :label="__('Cari KPI, NIK, atau nama')" wire:model.live.debounce.400ms="search" />
        <x-ui.select name="unit" :label="__('Unit')" :placeholder="__('Semua unit')" :options="$this->unitOptions" wire:model.live="unit" />
        <x-ui.select name="state" :label="__('Status')" :placeholder="__('Semua status')" :options="ActualQuery::STATES" wire:model.live="state" />
    </form>

    <form wire:submit="saveDrafts" class="flex flex-col gap-4">
        <x-ui.table-shell :caption="__('Aktual untuk rentang observasi terpilih')" :columns="[__('Subjek'), __('KPI'), __('Target'), __('Aktual'), __('Catatan'), __('Status'), __('Tindakan')]">
            @foreach ($this->rows as $row)
                @php($id = $row->target->public_id)
                <tr wire:key="actual-{{ $id }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">
                        {{ $row->target->subject_name }}
                        <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $row->target->subject_type->label() }} · {{ $row->target->subject_code }} · {{ $row->target->unit_code }}</span>
                    </th>
                    <td class="px-4 py-3">
                        {{ $row->target->kpi_name }}
                        <span class="block font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $row->target->kpi_code }} · {{ $unitLabel($row->target->definitionVersion->unit) }} · {{ Vocabulary::label(Vocabulary::FREQUENCIES, $row->target->definitionVersion->frequency) }}</span>
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Bukti') }}: {{ Vocabulary::label(Vocabulary::EVIDENCE_REQUIREMENTS, $row->target->definitionVersion->evidence_requirement) }}</span>
                    </td>
                    <td class="px-4 py-3">{{ $row->targetValue ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($this->canEnter && $row->acceptsEntry())
                            <label class="sr-only" for="value-{{ $id }}">{{ __('Aktual :kpi untuk :subject', ['kpi' => $row->target->kpi_code, 'subject' => $row->target->subject_code]) }}</label>
                            <input id="value-{{ $id }}" type="text" inputmode="decimal" wire:model="values.{{ $id }}"
                                @if (isset($rowErrors[$id])) aria-invalid="true" aria-describedby="value-{{ $id }}-error" @endif
                                class="w-28 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                            <label class="mt-1 flex items-center gap-1 text-xs"><input type="checkbox" wire:model="notApplicable.{{ $id }}" class="size-4"> {{ __('N/A') }}</label>
                        @else
                            {{ $row->version?->displayValue() ?? '—' }}
                        @endif

                        @if (isset($rowErrors[$id]))
                            <p id="value-{{ $id }}-error" class="mt-1 text-xs text-danger-700 dark:text-danger-300">{{ implode(' ', $rowErrors[$id]) }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($this->canEnter && $row->acceptsEntry())
                            <label class="sr-only" for="note-{{ $id }}">{{ __('Catatan :kpi untuk :subject', ['kpi' => $row->target->kpi_code, 'subject' => $row->target->subject_code]) }}</label>
                            <input id="note-{{ $id }}" type="text" wire:model="notes.{{ $id }}" class="w-44 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                        @else
                            {{ $row->version?->note ?? '—' }}
                        @endif
                        @if ($row->version?->reject_reason && $row->version->status !== ActualStatus::Verified)
                            <span class="mt-1 block text-xs text-danger-700 dark:text-danger-300">{{ __('Alasan ditolak') }}: {{ $row->version->reject_reason }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.status-badge :status="$stateTone($row->state())">{{ ActualQuery::STATES[$row->state()] }}</x-ui.status-badge>
                        @if ($row->version?->is_late)
                            <x-ui.status-badge status="warning">{{ __('Terlambat') }}</x-ui.status-badge>
                        @endif
                        <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Bukti') }}: {{ $row->actual?->evidence->count() ?? 0 }} · {{ __('Entri') }}: {{ $row->entryCount }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            @if ($this->canVerify && $row->actual !== null && $row->version?->status === ActualStatus::Submitted)
                                <x-ui.button variant="ghost" wire:click="startReject('{{ $row->actual->public_id }}')">{{ __('Tolak') }}<span class="sr-only"> {{ $row->target->kpi_code }} {{ $row->target->subject_code }}</span></x-ui.button>
                            @endif
                            <x-ui.button variant="ghost" wire:click="showDetail('{{ $id }}')">{{ __('Bukti & riwayat') }}<span class="sr-only"> {{ $row->target->kpi_code }} {{ $row->target->subject_code }}</span></x-ui.button>
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Tidak ada target')" :description="__('Tidak ada target yang cocok dengan filter dalam scope Anda.')" />
            </x-slot:empty>
        </x-ui.table-shell>

        {{ $this->rows->links() }}

        @if ($this->canEnter)
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.textarea name="lateReason" :label="$this->isLate ? __('Alasan keterlambatan (wajib)') : __('Alasan keterlambatan (bila terlambat)')" :required="$this->isLate" wire:model="lateReason" />
                <div class="flex flex-wrap items-end gap-2">
                    <x-ui.button type="submit">{{ __('Simpan draft halaman ini') }}</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="submitDrafts" wire:confirm="{{ __('Ajukan semua aktual draft yang Anda isi pada periode ini untuk verifikasi? Aktual yang diajukan tidak dapat diubah kecuali ditolak.') }}">{{ __('Ajukan draft') }}</x-ui.button>
                </div>
            </div>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Satu kali simpan berlaku untuk seluruh baris di halaman ini: bila satu nilai ditolak, tidak ada nilai yang disimpan. Lampirkan bukti lewat "Bukti & riwayat" setelah draft tersimpan.') }}</p>
        @endif
    </form>

    @if ($this->canVerify)
        <x-ui.panel :title="__('Verifikasi')" :description="__('Aktual milik Anda sendiri, atau yang Anda isi atau ajukan, harus diverifikasi orang lain. Aktual dengan bukti wajib hanya dapat diverifikasi bila buktinya dapat dibuka.')">
            <x-ui.button wire:click="verifySubmitted" wire:confirm="{{ __('Verifikasi semua aktual yang menunggu dalam scope Anda? Aktual terverifikasi tidak dapat diubah.') }}">{{ __('Verifikasi aktual yang diajukan') }}</x-ui.button>
        </x-ui.panel>
    @endif

    @if ($rejecting !== null)
        <x-ui.panel :title="__('Tolak aktual')">
            <form wire:submit="confirmReject" class="grid gap-4 sm:grid-cols-2">
                <x-ui.textarea name="rejectReason" :label="__('Alasan penolakan')" required wire:model="rejectReason" />
                <div class="self-end"><x-ui.button type="submit" variant="danger">{{ __('Tolak dan kembalikan') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->entries !== null && $this->detailTarget !== null)
        <section class="flex flex-col gap-4" aria-labelledby="detail-heading">
            <h2 id="detail-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">
                {{ __('Bukti dan riwayat') }}: {{ $this->detailTarget->kpi_code }} · {{ $this->detailTarget->subject_code }}
            </h2>

            @forelse ($this->entries as $entry)
                @php($version = $entry->versions->last())
                <x-ui.panel :title="__('Observasi').' '.$entry->windowLabel()" wire:key="entry-{{ $entry->public_id }}">
                    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                        <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Nilai') }}</dt><dd>{{ $version?->displayValue() ?? '—' }}</dd></div>
                        <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</dt><dd>{{ $version?->status->label() ?? '—' }}{{ $version?->is_late ? ' · '.__('terlambat') : '' }}</dd></div>
                        <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Sumber') }}</dt><dd>{{ $version?->source === 'import' ? __('Import') : __('Manual') }}{{ $entry->source_system ? ' · '.$entry->source_system.' / '.$entry->source_reference : '' }}</dd></div>
                        <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Pengisi / pengaju / verifikator') }}</dt><dd>{{ $version?->updatedBy?->name ?? '—' }} / {{ $version?->submittedBy?->name ?? '—' }} / {{ $version?->verifiedBy?->name ?? '—' }}</dd></div>
                        @if ($version?->late_reason)
                            <div class="sm:col-span-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Alasan terlambat') }}</dt><dd>{{ $version->late_reason }}</dd></div>
                        @endif
                    </dl>

                    <x-ui.table-shell class="mt-4" :caption="__('File bukti')" :columns="[__('File'), __('Ukuran'), __('Pemindaian'), __('Pengunggah'), __('Tindakan')]">
                        @foreach ($entry->evidence as $file)
                            <tr wire:key="evidence-{{ $file->public_id }}">
                                <td class="px-4 py-3">{{ $file->original_name }}<span class="block font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $file->detected_mime }} · sha256 {{ substr($file->checksum, 0, 12) }}…</span></td>
                                <td class="px-4 py-3">{{ number_format($file->byte_size / 1024, 1) }} KB</td>
                                <td class="px-4 py-3"><x-ui.status-badge :status="$file->scan_status->isServable() ? 'neutral' : 'warning'">{{ $file->scan_status->label() }}</x-ui.status-badge></td>
                                <td class="px-4 py-3">{{ $file->uploadedBy?->name ?? __('Sistem') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($file->scan_status->isServable())
                                            <x-ui.button variant="ghost" wire:click="openEvidence('{{ $file->public_id }}')">{{ __('Unduh') }}<span class="sr-only"> {{ $file->original_name }}</span></x-ui.button>
                                        @endif
                                        @if ($this->canEnter && $version?->status->isEditable())
                                            <x-ui.button variant="ghost" wire:click="removeEvidence('{{ $file->public_id }}')" wire:confirm="{{ __('Lepas file bukti ini dari aktual?') }}">{{ __('Lepas') }}<span class="sr-only"> {{ $file->original_name }}</span></x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        <x-slot:empty>
                            <x-ui.empty-state :title="__('Belum ada bukti')" />
                        </x-slot:empty>
                    </x-ui.table-shell>

                    @if ($this->canEnter && $version?->status->isEditable())
                        @if ($attachingTo === $entry->public_id)
                            <form wire:submit="attachEvidence" class="mt-4 flex flex-col gap-3">
                                <div class="grid gap-1.5">
                                    <x-ui.label for="evidenceFile" :required="true">{{ __('File bukti') }}</x-ui.label>
                                    <input id="evidenceFile" name="evidenceFile" type="file" wire:model="evidenceFile" class="text-sm" @error('evidenceFile') aria-invalid="true" aria-describedby="evidenceFile-error" @enderror>
                                    <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Jenis: :types. Maksimal :size MB per file dan :count file per aktual. Jenis file diperiksa dari isinya.', ['types' => implode(', ', EvidenceRules::extensions()), 'size' => round(config('evidence.max_bytes') / 1048576, 1), 'count' => config('evidence.max_files_per_actual')]) }}</p>
                                    @error('evidenceFile')
                                        <p id="evidenceFile-error" class="text-sm text-danger-700 dark:text-danger-300">{{ $message }}</p>
                                    @enderror
                                    <p wire:loading wire:target="evidenceFile" role="status" class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Mengunggah…') }}</p>
                                </div>
                                <div><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="evidenceFile,attachEvidence">{{ __('Lampirkan') }}</x-ui.button></div>
                            </form>
                        @else
                            <div class="mt-4"><x-ui.button variant="secondary" wire:click="startAttach('{{ $entry->public_id }}')">{{ __('Lampirkan bukti') }}</x-ui.button></div>
                        @endif
                    @endif
                </x-ui.panel>
            @empty
                <x-ui.empty-state :title="__('Belum ada aktual untuk target ini')" :description="__('Target ini berstatus missing sampai ada entri nilai atau N/A.')" />
            @endforelse

            @if ($this->audit !== null)
                @include('pages.kpi.partials.audit-trail', ['entries' => $this->audit, 'actorNames' => $this->actorNames])
            @endif
        </section>
    @endif
</div>
