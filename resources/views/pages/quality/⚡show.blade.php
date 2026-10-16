<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Quality\Application\NcrQuery;
use App\Modules\Quality\Application\NcrService;
use App\Modules\Quality\Application\QualityAccess;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Domain\QualityRules;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrStage;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('NCR')] class extends GuardedPage {
    #[Locked]
    public string $ncrId = '';

    #[Locked]
    public int $version = 0;

    /** @var array<string, string> */
    public array $step = [];

    public bool $restricted = false;

    public string $reason = '';

    public string $reopenTarget = '';

    /** @var array<string, string> */
    public array $correction = [];

    public function mount(string $ncr): void
    {
        Gate::authorize('quality.view');
        $this->ncrId = $ncr;
        $this->version = $this->ncr->lock_version;
        $this->resetForms();
    }

    #[Computed]
    public function ncr(): QualityNcr
    {
        $ncr = QualityNcr::query()->with(['qcUnit', 'responsibleUnit', 'owner', 'repeatOf', 'repeats', 'kpiVersion', 'sourceRecord.batch', 'events.actor'])->where('public_id', $this->ncrId)->first();
        abort_if($ncr === null || ! app(QualityAccess::class)->canRead($this->currentUser(), $ncr), 404);

        return $ncr;
    }

    /**
     * @return list<array{stage: QualityNcrStage, completed_on: string|null, late_days: int}>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(NcrQuery::class)->timeline($this->ncr);
    }

    #[Computed]
    public function open(): ?QualityNcrStage
    {
        return QualityNcrStage::query()->where('quality_ncr_id', $this->ncr->id)->whereNull('completed_at')->first();
    }

    #[Computed]
    public function canComplete(): bool
    {
        return $this->open !== null && app(QualityAccess::class)->canComplete($this->currentUser(), $this->ncr, $this->open);
    }

    #[Computed]
    public function isQc(): bool
    {
        return app(QualityAccess::class)->isQcManager($this->currentUser());
    }

    #[Computed]
    public function qualityRules(): ?QualityRules
    {
        try {
            return NcrService::rules();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function people(): array
    {
        return User::query()->where('status', AccountStatus::Active->value)->orderBy('name')->limit(500)->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all();
    }

    public function advance(NcrService $ncrs): void
    {
        $this->act(__('Tahap diselesaikan.'), fn () => $ncrs->advance($this->selfActor(), $this->currentUser(), $this->ncr->id, $this->version, [...$this->step, 'evidence_restricted' => $this->restricted]));
    }

    public function returnToCorrectiveAction(NcrService $ncrs): void
    {
        $this->act(__('NCR dikembalikan ke Corrective Action.'), fn () => $ncrs->returnToCorrectiveAction($this->selfActor(), $this->currentUser(), $this->ncr->id, $this->version, $this->reason, [...$this->step, 'evidence_restricted' => $this->restricted]));
    }

    public function reopen(NcrService $ncrs): void
    {
        $target = NcrStage::tryFrom($this->reopenTarget);

        if ($target === null) {
            $this->addError('action', __('Pilih tahap untuk membuka kembali NCR.'));

            return;
        }

        $this->act(__('NCR dibuka kembali.'), fn () => $ncrs->reopen($this->selfActor(), $this->currentUser(), $this->ncr->id, $this->version, $target, $this->reason, (int) ($this->step['next_pic_user_id'] ?? 0), (string) ($this->step['next_due_date'] ?? '')));
    }

    public function correct(NcrService $ncrs): void
    {
        $changes = [];

        foreach ($this->correction as $field => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            // __clear empties a link or severity; other fields keep their value when left blank.
            if ($value === '__clear' && ! in_array($field, ['kpi_code', 'repeat_of', 'severity'], true)) {
                continue;
            }

            $changes[$field] = $value === '__clear' ? '' : $value;
        }

        $this->act(__('Koreksi NCR dicatat.'), fn () => $ncrs->correct($this->selfActor(), $this->currentUser(), $this->ncr->id, $this->version, $changes, $this->reason));
    }

    /**
     * @param  callable(): QualityNcr  $operation
     */
    private function act(string $message, callable $operation): void
    {
        Gate::authorize('quality.view');
        $result = null;

        $done = $this->attempt('action', $message, function () use ($operation, &$result): void {
            $result = $operation();
        });

        if ($done && $result instanceof QualityNcr) {
            $this->version = $result->lock_version;
            $this->resetForms();
        }

        unset($this->ncr, $this->timeline, $this->open, $this->canComplete);
    }

    private function resetForms(): void
    {
        $this->step = ['content' => '', 'evidence_reference' => '', 'root_cause_method' => '', 'preventive_action' => '', 'effectiveness_result' => '', 'next_pic_user_id' => '', 'next_due_date' => ''];
        $this->correction = ['category_code' => '', 'severity' => '', 'quantity' => '', 'unit' => '', 'description' => '', 'kpi_code' => '', 'repeat_of' => ''];
        $this->restricted = false;
        $this->reason = '';
        $this->reopenTarget = '';
    }
}; ?>

@php
    $ncr = $this->ncr;
    $access = app(QualityAccess::class);
    $user = auth()->user();
    $local = fn ($moment): string => $moment === null ? '—' : app(\App\Modules\Shared\Application\Time\BusinessTime::class)->toBusiness($moment)->format('Y-m-d H:i');
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
    $open = $this->open;
    $rules = $this->qualityRules;
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$ncr->number.' — '.$ncr->stage->label()" :description="$ncr->description">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('quality.index')" wire:navigate>{{ __('Semua NCR') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <x-ui.panel :title="__('Temuan dan ketertelusuran')">
        <dl class="grid gap-2 text-sm sm:grid-cols-3">
            <div><dt class="font-medium">{{ __('Sumber') }}</dt><dd>{{ $ncr->source_type->label() }} · {{ $ncr->source_reference }}
                @if ($ncr->sourceRecord !== null)
                    <span class="block text-xs">{{ __('Inspeksi :stage pada batch :date v:version', ['stage' => $ncr->sourceRecord->detail('inspection_stage'), 'date' => $ncr->sourceRecord->batch->coverage_date, 'version' => $ncr->sourceRecord->batch->version]) }}
                        @can('production.view')<a href="{{ route('production.index', ['batch' => $ncr->sourceRecord->batch->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Lihat batch') }}</a>@endcan
                    </span>
                @endif
            </dd></div>
            <div><dt class="font-medium">{{ __('Team penanggung jawab') }}</dt><dd>{{ $ncr->responsibleUnit->code }} — {{ $ncr->responsibleUnit->name }}</dd></div>
            <div><dt class="font-medium">{{ __('Ditemukan') }}</dt><dd>{{ $local($ncr->detected_at) }}</dd></div>
            <div><dt class="font-medium">{{ __('Kategori / severity') }}</dt><dd>{{ $ncr->category_code }} · {{ $ncr->severity ?? __('severity belum ditetapkan') }}</dd></div>
            <div><dt class="font-medium">{{ __('Kuantitas') }}</dt><dd>{{ $ncr->quantity === null ? '—' : $ncr->quantity.' '.$ncr->unit }}</dd></div>
            <div><dt class="font-medium">{{ __('Work order / produk / lot') }}</dt><dd>{{ $ncr->work_order ?? '—' }} · {{ $ncr->product_code ?? '—' }} · {{ $ncr->lot ?? '—' }}</dd></div>
            <div><dt class="font-medium">{{ __('Proses / mesin / shift') }}</dt><dd>{{ $ncr->process_code ?? '—' }} · {{ $ncr->machine_code ?? '—' }} · {{ $ncr->shift_code ?? '—' }}</dd></div>
            <div><dt class="font-medium">{{ __('Pemilik') }}</dt><dd>{{ $ncr->owner->name }}</dd></div>
            <div><dt class="font-medium">{{ __('KPI terkait') }}</dt><dd>{{ $ncr->kpiVersion === null ? '—' : $ncr->kpiVersion->name.' (versi '.$ncr->kpiVersion->version_number.')' }}</dd></div>
            <div><dt class="font-medium">{{ __('Repeat issue') }}</dt><dd>
                @if ($ncr->repeatOf !== null)<a href="{{ route('quality.show', ['ncr' => $ncr->repeatOf->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Ulangan dari :n', ['n' => $ncr->repeatOf->number]) }}</a>@endif
                @foreach ($ncr->repeats as $repeat)<span class="block"><a href="{{ route('quality.show', ['ncr' => $repeat->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Diulang oleh :n', ['n' => $repeat->number]) }}</a></span>@endforeach
                @if ($ncr->repeatOf === null && $ncr->repeats->isEmpty())—@endif
            </dd></div>
        </dl>
    </x-ui.panel>

    <x-ui.table-shell :caption="__('Tahap, tenggat, dan bukti')" :columns="[__('Tahap'), __('PIC'), __('Tenggat'), __('Selesai'), __('Isi'), __('Bukti'), __('Hasil')]">
        @foreach ($this->timeline as $row)
            @php($stage = $row['stage'])
            <tr wire:key="stage-{{ $stage->id }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $stage->stage->label() }}<span class="block text-xs font-normal">{{ __('siklus :n', ['n' => $stage->cycle]) }}</span></th>
                <td class="px-4 py-3 text-xs">{{ $stage->pic->name }}</td>
                <td class="px-4 py-3 text-xs whitespace-nowrap">{{ $stage->due_date }}@if ($row['late_days'] > 0)<x-ui.status-badge status="danger">{{ __('Lewat :n hari', ['n' => $row['late_days']]) }}</x-ui.status-badge>@endif</td>
                <td class="px-4 py-3 text-xs whitespace-nowrap">{{ $row['completed_on'] ?? __('terbuka') }}@if ($stage->completedBy)<span class="block">{{ $stage->completedBy->name }}</span>@endif</td>
                <td class="px-4 py-3 text-xs">{{ $stage->content ?? '—' }}
                    @if ($stage->root_cause_method)<span class="block">{{ __('Metode: :m', ['m' => $stage->root_cause_method]) }}</span>@endif
                    @if ($stage->preventive_action)<span class="block">{{ __('Preventive: :p', ['p' => $stage->preventive_action]) }}</span>@endif
                    @if ($stage->effectiveness_result)<span class="block">{{ __('Efektivitas: :e', ['e' => $stage->effectiveness_result]) }}</span>@endif
                </td>
                <td class="px-4 py-3 text-xs">
                    @if ($stage->evidence_reference === null)
                        —
                    @elseif ($stage->evidence_restricted && ! $access->canSeeRestrictedEvidence($user, $ncr, $stage))
                        <x-ui.status-badge status="neutral">{{ __('Bukti terbatas') }}</x-ui.status-badge>
                    @else
                        {{ $stage->evidence_reference }}@if ($stage->evidence_restricted) <x-ui.status-badge status="warning">{{ __('Terbatas') }}</x-ui.status-badge>@endif
                    @endif
                </td>
                <td class="px-4 py-3 text-xs">{{ match ($stage->outcome) { 'completed' => __('Selesai'), 'returned' => __('Dikembalikan'), default => __('Terbuka') } }}</td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    @if ($this->canComplete && $open !== null)
        <x-ui.panel :title="__('Selesaikan tahap :stage', ['stage' => $open->stage->label()])" :description="$open->stage === NcrStage::Verification ? __('Menutup NCR membutuhkan hasil verifikasi dan referensi bukti.') : __('Tahap berikutnya: :next.', ['next' => $open->stage->next()?->label()])">
            <form wire:submit="advance" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-ui.textarea name="step.content" :label="$open->stage->contentLabel()" :required="$open->stage !== NcrStage::Finding" wire:model="step.content" /></div>
                <x-ui.input name="step.evidence_reference" :label="__('Referensi bukti')" :hint="__('Nomor dokumen, rekaman QC, atau lokasi arsip bukti')" :required="$rules?->needsEvidence($open->stage) ?? true" wire:model="step.evidence_reference" />
                <label class="flex items-center gap-2 self-end text-sm"><input type="checkbox" wire:model="restricted" class="rounded border-zinc-300"> {{ __('Bukti terbatas (data customer atau sensitif)') }}</label>
                @if ($open->stage === NcrStage::RootCause && ($rules?->rootCauseMethods ?? []) !== [])
                    <x-ui.select name="step.root_cause_method" :label="__('Metode root cause')" :placeholder="__('Pilih')" :options="array_combine($rules->rootCauseMethods, $rules->rootCauseMethods)" wire:model="step.root_cause_method" />
                @endif
                @if ($open->stage === NcrStage::CorrectiveAction && ($rules?->preventiveActionEnabled ?? false))
                    <div class="sm:col-span-2"><x-ui.textarea name="step.preventive_action" :label="__('Preventive action')" wire:model="step.preventive_action" /></div>
                @endif
                @if ($open->stage === NcrStage::Verification && ($rules?->effectivenessReviewRequired ?? false))
                    <x-ui.select name="step.effectiveness_result" :label="__('Hasil effectiveness review')" :placeholder="__('Pilih')" :options="['effective' => __('Efektif'), 'not_effective' => __('Tidak efektif')]" required wire:model="step.effectiveness_result" />
                @endif
                @if ($open->stage !== NcrStage::Verification)
                    <x-ui.select name="step.next_pic_user_id" :label="__('PIC tahap berikutnya')" :placeholder="__('Pilih')" :options="$this->people" required wire:model="step.next_pic_user_id" />
                    <x-ui.input name="step.next_due_date" type="date" :label="__('Tenggat tahap berikutnya')" required wire:model="step.next_due_date" />
                @endif
                <div class="sm:col-span-2"><x-ui.button type="submit">{{ $open->stage === NcrStage::Verification ? __('Verifikasi dan tutup NCR') : __('Selesaikan tahap') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->isQc && $open?->stage === NcrStage::Verification)
        <x-ui.panel :title="__('Verifikasi gagal')" :description="__('Mengembalikan NCR ke Corrective Action dengan alasan; tahap Verification tercatat sebagai dikembalikan.')">
            <form wire:submit="returnToCorrectiveAction" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" /></div>
                <x-ui.select name="step.next_pic_user_id" :label="__('PIC Corrective Action')" :placeholder="__('Pilih')" :options="$this->people" required wire:model="step.next_pic_user_id" />
                <x-ui.input name="step.next_due_date" type="date" :label="__('Tenggat')" required wire:model="step.next_due_date" />
                <div><x-ui.button type="submit" variant="secondary">{{ __('Kembalikan ke Corrective Action') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->isQc && $ncr->stage === NcrStage::Closed)
        <x-ui.panel :title="__('Buka kembali NCR')" :description="__('Siklus yang sudah ditutup tetap tersimpan; siklus baru dimulai pada tahap yang dipilih.')">
            <form wire:submit="reopen" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="reopenTarget" :label="__('Mulai dari tahap')" :placeholder="__('Pilih')" :options="collect(NcrStage::reopenTargets())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" required wire:model="reopenTarget" />
                <div class="sm:col-span-2"><x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" /></div>
                <x-ui.select name="step.next_pic_user_id" :label="__('PIC')" :placeholder="__('Pilih')" :options="$this->people" required wire:model="step.next_pic_user_id" />
                <x-ui.input name="step.next_due_date" type="date" :label="__('Tenggat')" required wire:model="step.next_due_date" />
                <div><x-ui.button type="submit" variant="secondary">{{ __('Buka kembali') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->isQc)
        <x-ui.panel :title="__('Koreksi data NCR')" :description="__('Hanya isian yang diisi yang diubah. Nilai sebelum dan sesudah tersimpan di riwayat. Isi __clear untuk mengosongkan KPI, severity, atau tautan repeat.')">
            <form wire:submit="correct" class="grid gap-4 sm:grid-cols-3">
                <x-ui.input name="correction.category_code" :label="__('Kategori defect')" wire:model="correction.category_code" />
                <x-ui.input name="correction.severity" :label="__('Severity')" wire:model="correction.severity" />
                <x-ui.input name="correction.quantity" :label="__('Kuantitas')" wire:model="correction.quantity" />
                <x-ui.input name="correction.unit" :label="__('Satuan')" wire:model="correction.unit" />
                <x-ui.input name="correction.kpi_code" :label="__('Kode KPI terkait')" wire:model="correction.kpi_code" />
                <x-ui.input name="correction.repeat_of" :label="__('Ulangan dari NCR')" wire:model="correction.repeat_of" />
                <div class="sm:col-span-3"><x-ui.textarea name="correction.description" :label="__('Uraian temuan')" wire:model="correction.description" /></div>
                <div class="sm:col-span-3"><x-ui.textarea name="reason" :label="__('Alasan koreksi')" required wire:model="reason" /></div>
                <div><x-ui.button type="submit" variant="secondary">{{ __('Simpan koreksi') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.table-shell :caption="__('Riwayat')" :columns="[__('#'), __('Waktu'), __('Aksi'), __('Tahap'), __('Oleh'), __('Alasan / perubahan')]">
        @foreach ($ncr->events as $event)
            <tr wire:key="event-{{ $event->id }}">
                <td class="px-4 py-3 text-xs">{{ $event->sequence }}</td>
                <td class="px-4 py-3 text-xs whitespace-nowrap">{{ $local($event->occurred_at) }}</td>
                <th scope="row" class="px-4 py-3 text-start text-xs font-medium">{{ $event->action }}</th>
                <td class="px-4 py-3 text-xs">{{ $event->from_stage?->label() ?? '—' }} → {{ $event->to_stage?->label() ?? '—' }}</td>
                <td class="px-4 py-3 text-xs">{{ $event->actor->name }}</td>
                <td class="px-4 py-3 text-xs">{{ $event->reason ?? '' }}@if ($event->changes)<span class="block font-mono">{{ json_encode($event->changes, JSON_UNESCAPED_UNICODE) }}</span>@endif</td>
            </tr>
        @endforeach
    </x-ui.table-shell>
</div>
