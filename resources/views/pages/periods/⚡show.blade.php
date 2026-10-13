<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Application\PeriodQuery;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Application\PeriodSetup;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\PeriodType;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use App\Modules\Target\Application\TargetQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Title('Periode penilaian')] class extends GuardedPage {
    use WithPagination;

    #[Locked]
    public string $periodId = '';

    /** Lock version of the period when the page loaded it. */
    #[Locked]
    public int $workingLock = 0;

    public string $name = '';
    public string $type = '';
    public string $startsOn = '';
    public string $endsOn = '';
    public string $referenceDate = '';
    public string $notes = '';

    /** @var array<string, string> */
    public array $deadlines = [];

    public string $rescheduleStage = '';
    public string $rescheduleDate = '';
    public string $rescheduleReason = '';

    public string $subjectType = '';
    public string $coverage = '';
    public bool $gapsOnly = false;
    public string $search = '';

    public function mount(AssessmentPeriod $period): void
    {
        $this->authorize('view', $period);
        $this->periodId = $period->public_id;
        $this->loadForm();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['subjectType', 'coverage', 'gapsOnly', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->with(['scopeUnit', 'deadlines', 'openedBy'])->where('public_id', $this->periodId)->firstOrFail();
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return $this->currentUser()->can('update', $this->period);
    }

    /**
     * Who is assessed by whom: the plan of a draft, or the stored snapshot.
     *
     * @return array{rows: LengthAwarePaginator, summary: array<string, int>, preview: bool}|null
     */
    #[Computed]
    public function assignments(): ?array
    {
        try {
            return app(PeriodQuery::class)->assignments(
                $this->period,
                $this->access()->unitScope($this->currentUser(), Capability::PeriodView),
                SubjectType::tryFrom($this->subjectType)?->value,
                in_array($this->coverage, ['covered', 'not_covered'], true) ? $this->coverage : null,
                $this->gapsOnly,
                $this->search,
                $this->getPage(),
                CarbonImmutable::now(),
            );
        } catch (SafeDomainException $exception) {
            $this->addError('assignments', $exception->safeMessage());

            return null;
        }
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function progressBlockers(): array
    {
        return $this->period->status === PeriodStatus::Open
            ? app(PeriodService::class)->blockersFor($this->period, PeriodStatus::InProgress)
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function completeness(): ?array
    {
        if ($this->period->status === PeriodStatus::Draft || ! $this->currentUser()->can('viewTargets', $this->period)) {
            return null;
        }

        return app(TargetQuery::class)->completeness($this->period, $this->access()->unitScope($this->currentUser(), Capability::TargetView));
    }

    #[Computed]
    public function audit(): LengthAwarePaginator
    {
        return app(PeriodQuery::class)->auditTrail($this->period, $this->getPage('auditPage'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function actorNames(): array
    {
        $ids = collect($this->audit->items())->pluck('actor_identifier')->filter()->unique()->all();

        return User::query()->whereIn('public_id', $ids)->pluck('name', 'public_id')->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function stageOptions(): array
    {
        return collect(DeadlineStage::cases())->mapWithKeys(fn (DeadlineStage $stage): array => [$stage->value => $stage->label()])->all();
    }

    public function saveDraft(PeriodService $periods): void
    {
        $this->authorize('update', $this->period);
        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:'.implode(',', array_keys(PeriodType::options()))],
            'startsOn' => ['required', 'date_format:Y-m-d'],
            'endsOn' => ['required', 'date_format:Y-m-d'],
            'referenceDate' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'deadlines' => ['array'],
            'deadlines.*' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $period = $this->period;

        $this->runAndRefresh(__('Periode draft disimpan.'), fn () => $periods->updateDraft($this->manager(), $period->id, $this->workingLock, new PeriodSetup(
            code: $period->code,
            name: $this->name,
            type: $this->type,
            startsOn: $this->startsOn,
            endsOn: $this->endsOn,
            unitId: $period->organization_unit_id,
            referenceDate: $this->referenceDate,
            notes: $this->notes,
            deadlines: array_intersect_key($this->deadlines, $this->stageOptions),
        )));
    }

    public function open(PeriodService $periods): void
    {
        $this->authorize('update', $this->period);

        $this->runAndRefresh(
            __('Periode dibuka. Snapshot penugasan dan daftar target sudah dibuat.'),
            fn () => $periods->open($this->manager(), $this->period->id, $this->workingLock),
        );
    }

    public function startProgress(PeriodService $periods): void
    {
        $this->authorize('update', $this->period);

        $this->runAndRefresh(
            __('Periode masuk tahap pengisian aktual.'),
            fn () => $periods->startProgress($this->manager(), $this->period->id, $this->workingLock),
        );
    }

    public function reschedule(PeriodService $periods): void
    {
        $this->authorize('update', $this->period);
        $this->validate([
            'rescheduleStage' => ['required', 'in:'.implode(',', array_keys($this->stageOptions))],
            'rescheduleDate' => ['nullable', 'date_format:Y-m-d'],
            'rescheduleReason' => ['required', 'string', 'max:2000'],
        ]);

        $done = $this->runAndRefresh(__('Deadline dijadwalkan ulang.'), fn () => $periods->rescheduleDeadline(
            $this->manager(),
            $this->period->id,
            $this->workingLock,
            DeadlineStage::from($this->rescheduleStage),
            $this->rescheduleDate,
            $this->rescheduleReason,
        ));

        if ($done) {
            $this->reset('rescheduleStage', 'rescheduleDate', 'rescheduleReason');
        }
    }

    private function manager(): Actor
    {
        return $this->actorFor(Capability::PeriodManage, $this->access()->unitTarget($this->period->organization_unit_id));
    }

    private function runAndRefresh(string $message, \Closure $operation): bool
    {
        $done = $this->attempt('action', $message, $operation);

        if ($done) {
            unset($this->period, $this->assignments, $this->progressBlockers, $this->completeness, $this->audit, $this->actorNames);
            $this->loadForm();
        }

        return $done;
    }

    private function loadForm(): void
    {
        $period = $this->period;

        $this->workingLock = $period->lock_version;
        $this->name = $period->name;
        $this->type = $period->period_type->value;
        $this->startsOn = $period->starts_on;
        $this->endsOn = $period->ends_on;
        $this->referenceDate = $period->assignment_reference_date;
        $this->notes = (string) $period->notes;
        $this->deadlines = $period->deadlineMap();
    }
}; ?>

@php
    $period = $this->period;
    $isDraft = $period->status === PeriodStatus::Draft;
    $assignments = $this->assignments;
    $completeness = $this->completeness;
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$period->name" :description="$period->code.' · '.$period->period_type->label().' · '.$period->starts_on.' — '.$period->ends_on.' · '.$period->scopeUnit->code">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('periods.index')" wire:navigate>{{ __('Kembali ke daftar periode') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.periods.partials.nav', ['period' => $period])

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'assignments']])

    <dl class="grid gap-x-6 gap-y-3 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-3 dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</dt>
            <dd>@include('pages.periods.partials.status-badge', ['status' => $period->status])</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Zona waktu periode') }}</dt>
            <dd class="text-sm">{{ $period->timezone }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Tanggal acuan penugasan') }}</dt>
            <dd class="text-sm">{{ $period->assignment_reference_date }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Dibuka') }}</dt>
            <dd class="text-sm">
                {{ $period->opened_at ? $period->opened_at->setTimezone($period->timezone)->format('Y-m-d H:i').' · '.($period->openedBy?->name ?? __('Sistem')) : '—' }}
            </dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Catatan') }}</dt>
            <dd class="text-sm break-words">{{ $period->notes ?? '—' }}</dd>
        </div>
    </dl>

    <x-ui.table-shell :caption="__('Deadline per tahap')" :columns="[__('Tahap'), __('Deadline (sampai akhir hari)')]">
        @foreach (DeadlineStage::cases() as $stage)
            <tr wire:key="deadline-{{ $stage->value }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $stage->label() }}</th>
                <td class="px-4 py-3">{{ $period->deadlineFor($stage) ?? __('Belum ditetapkan') }}</td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    @if ($isDraft && $this->canUpdate)
        <x-ui.panel :title="__('Ubah periode draft')" :description="__('Kode dan scope tidak dapat diubah. Setelah periode dibuka, hanya deadline yang dapat dijadwalkan ulang dengan alasan.')">
            <form wire:submit="saveDraft" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" :label="__('Nama periode')" required wire:model="name" />
                <x-ui.select name="type" :label="__('Tipe periode')" required :options="PeriodType::options()" wire:model="type" />
                <x-ui.input name="startsOn" type="date" :label="__('Mulai')" required wire:model="startsOn" />
                <x-ui.input name="endsOn" type="date" :label="__('Selesai')" required wire:model="endsOn" />
                <x-ui.input name="referenceDate" type="date" :label="__('Tanggal acuan penugasan')" wire:model="referenceDate" />
                <x-ui.textarea name="notes" :label="__('Catatan')" wire:model="notes" />

                <fieldset class="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                    <legend class="mb-2 text-base font-semibold">{{ __('Deadline per tahap (opsional)') }}</legend>
                    @foreach (DeadlineStage::cases() as $stage)
                        <x-ui.input name="deadlines.{{ $stage->value }}" type="date" :label="$stage->label()" wire:model="deadlines.{{ $stage->value }}" />
                    @endforeach
                </fieldset>

                <div class="sm:col-span-2"><x-ui.button type="submit">{{ __('Simpan draft') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->canUpdate && in_array($period->status, [PeriodStatus::Draft, PeriodStatus::Open], true))
        <x-ui.panel :title="__('Tindakan periode')">
            <div class="flex flex-col gap-4">
                @if ($isDraft)
                    <x-ui.alert variant="info" :title="__('Membuka periode menyimpan snapshot penugasan di bawah ini dan membuat daftar target.')">
                        {{ __('Setelah dibuka, perubahan organisasi, jabatan, template, atau approver tidak mengubah periode ini. Periksa pratinjau sebelum membuka.') }}
                    </x-ui.alert>
                    <div><x-ui.button wire:click="open" wire:confirm="{{ __('Buka periode ini? Snapshot penugasan tidak dapat diubah setelahnya.') }}">{{ __('Buka periode') }}</x-ui.button></div>
                @else
                    @if ($this->progressBlockers === [])
                        <x-ui.alert variant="success" :title="__('Semua target wajib sudah final.')" />
                        <div><x-ui.button wire:click="startProgress">{{ __('Mulai tahap pengisian aktual') }}</x-ui.button></div>
                    @else
                        <x-ui.alert variant="warning" :title="__('Belum dapat masuk tahap pengisian aktual')">
                            <ul class="list-disc ps-5">
                                @foreach ($this->progressBlockers as $blocker)
                                    <li>{{ $blocker }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif
                @endif
            </div>
        </x-ui.panel>
    @endif

    @if ($this->canUpdate && in_array($period->status, [PeriodStatus::Open, PeriodStatus::InProgress, PeriodStatus::Review], true))
        <x-ui.panel :title="__('Jadwalkan ulang deadline')" :description="__('Tidak ada grace period otomatis. Setiap perubahan deadline wajib beralasan dan tercatat di audit.')">
            <form wire:submit="reschedule" class="grid gap-4 sm:grid-cols-3">
                <x-ui.select name="rescheduleStage" :label="__('Tahap')" required :placeholder="__('Pilih tahap')" :options="$this->stageOptions" wire:model="rescheduleStage" />
                <x-ui.input name="rescheduleDate" type="date" :label="__('Deadline baru')" :hint="__('Kosongkan untuk menghapus deadline tahap ini.')" wire:model="rescheduleDate" />
                <x-ui.textarea name="rescheduleReason" :label="__('Alasan')" required wire:model="rescheduleReason" />
                <div class="sm:col-span-3"><x-ui.button type="submit" variant="secondary">{{ __('Simpan deadline') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($completeness !== null)
        <section class="flex flex-col gap-3" aria-labelledby="completeness-heading">
            <h2 id="completeness-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Kelengkapan target') }}</h2>
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __(':final dari :required target wajib sudah final.', ['final' => $completeness['final'], 'required' => $completeness['required']]) }}
                {{ __('Menunggu persetujuan: :submitted · Draft: :draft · Belum diisi: :missing · Revisi terbuka: :revisions', ['submitted' => $completeness['submitted'], 'draft' => $completeness['draft'], 'missing' => $completeness['missing'], 'revisions' => $completeness['revisions_open']]) }}
            </p>
            <div><x-ui.button variant="secondary" :href="route('periods.targets', $period)" wire:navigate>{{ __('Buka matriks target') }}</x-ui.button></div>
        </section>
    @endif

    <section class="flex flex-col gap-4" aria-labelledby="assignments-heading">
        <h2 id="assignments-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">
            {{ $assignments !== null && $assignments['preview'] ? __('Pratinjau penugasan (belum disimpan)') : __('Snapshot penugasan') }}
        </h2>

        @if ($assignments !== null)
            @if ($assignments['preview'])
                <x-ui.alert variant="info" :title="__('Pratinjau dihitung dari data organisasi, template, dan role per tanggal acuan :date.', ['date' => $period->assignment_reference_date])">
                    {{ __('Isi pratinjau masih dapat berubah sampai periode dibuka.') }}
                </x-ui.alert>
            @endif

            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('Pegawai: :employees · Unit: :units · Tercakup template: :covered · Tidak tercakup: :not_covered · Tercakup tetapi jalur approver belum lengkap: :gaps', ['employees' => $assignments['summary']['employees'], 'units' => $assignments['summary']['units'], 'covered' => $assignments['summary']['covered'], 'not_covered' => $assignments['summary']['not_covered'], 'gaps' => $assignments['summary']['approver_gaps']]) }}
            </p>

            @if ($assignments['summary']['approver_gaps'] > 0)
                <x-ui.alert variant="warning" :title="__('Jalur approver belum lengkap')">
                    {{ __('Approver per team belum diputuskan (DEC-002). Tahap tanpa approver ditampilkan apa adanya; sistem tidak mengisi pengganti.') }}
                </x-ui.alert>
            @endif

            <form wire:submit.prevent class="grid gap-4 sm:grid-cols-4" role="search" aria-label="{{ __('Filter penugasan') }}">
                <x-ui.input name="search" type="search" :label="__('Cari NIK, kode unit, atau nama')" wire:model.live.debounce.400ms="search" />
                <x-ui.select name="subjectType" :label="__('Subjek')" :placeholder="__('Semua')" :options="['employee' => __('Pegawai'), 'unit' => __('Unit')]" wire:model.live="subjectType" />
                <x-ui.select name="coverage" :label="__('Template')" :placeholder="__('Semua')" :options="['covered' => __('Tercakup'), 'not_covered' => __('Tidak tercakup')]" wire:model.live="coverage" />
                <label class="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" wire:model.live="gapsOnly" class="size-4"> {{ __('Hanya yang approver-nya belum lengkap') }}</label>
            </form>

            <x-ui.table-shell :caption="__('Siapa dinilai oleh siapa')" :columns="[__('Subjek'), __('Team / jabatan'), __('Template'), ...array_map(fn ($stage) => $stage->label(), ApprovalStage::cases())]">
                @foreach ($assignments['rows'] as $row)
                    <tr wire:key="assignment-{{ $row->key }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">
                            {{ $row->subjectName }}
                            <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $row->subjectType->label() }} · {{ $row->subjectCode }}</span>
                        </th>
                        <td class="px-4 py-3">{{ $row->unitLabel }}{{ $row->positionTitle ? ' · '.$row->positionTitle : '' }}</td>
                        <td class="px-4 py-3">
                            @if ($row->coverage === Coverage::Covered)
                                {{ $row->templateLabel }}
                            @else
                                <x-ui.status-badge status="warning">{{ $row->coverage->label() }}</x-ui.status-badge>
                                @if ($row->templateLabel)
                                    <span class="block text-xs">{{ $row->templateLabel }}</span>
                                @endif
                            @endif
                        </td>
                        @foreach (ApprovalStage::cases() as $stage)
                            <td class="px-4 py-3 text-xs">
                                @if (isset($row->approvers[$stage->value]))
                                    {{ $row->approverText($stage) }}
                                @else
                                    <x-ui.status-badge status="warning">{{ $row->approverText($stage) }}</x-ui.status-badge>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada penugasan')" :description="__('Tidak ada pegawai atau unit yang cocok dengan filter dalam scope Anda.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            {{ $assignments['rows']->links() }}
        @endif
    </section>

    @include('pages.kpi.partials.audit-trail', ['entries' => $this->audit, 'actorNames' => $this->actorNames])
</div>
