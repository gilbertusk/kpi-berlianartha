<?php

use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\Workflow\Application\CorrectionService;
use App\Modules\Workflow\Application\ReviewRow;
use App\Modules\Workflow\Application\WorkflowAccess;
use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Domain\DecisionOutcome;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Approval scorecard')] class extends GuardedPage {
    #[Locked]
    public string $reviewId = '';

    /** Lock version of the review as this account saw it. */
    #[Locked]
    public int $lock = 0;

    public string $comment = '';

    public string $calibratedScore = '';

    public string $calibrationReason = '';

    public string $calibrationEvidence = '';

    public string $correctionActual = '';

    public string $correctionValue = '';

    public string $correctionNote = '';

    public string $correctionReason = '';

    public string $correctionEvidence = '';

    public function mount(ScorecardReview $review): void
    {
        $this->authorize('viewReview', $review);
        $this->reviewId = $review->public_id;
        // The version shown on the page, read from the database.
        $this->lock = $this->review->lock_version;
    }

    #[Computed]
    public function review(): ScorecardReview
    {
        return ScorecardReview::query()
            ->with(['assignment.approvers', 'assignment.employee', 'calculation', 'calibrations.calibratedBy', 'period', 'decisions.actor', 'decisions.actingFor'])
            ->where('public_id', $this->reviewId)
            ->firstOrFail();
    }

    #[Computed]
    public function row(): ReviewRow
    {
        return app(WorkflowQuery::class)->row($this->review, $this->currentUser());
    }

    /**
     * @return list<array{stage: ApprovalStage, approvers: list<string>, gap: string|null, bypass: string|null, decision: string|null}>
     */
    #[Computed]
    public function chain(): array
    {
        return app(WorkflowQuery::class)->chain($this->review);
    }

    /**
     * @return Collection<int, ScorecardCalculationLine>
     */
    #[Computed]
    public function lines(): Collection
    {
        return ScorecardCalculationLine::query()->where('scorecard_calculation_id', $this->review->scorecard_calculation_id)->orderBy('line_number')->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function correctable(): array
    {
        if (! in_array($this->review->period->status, [PeriodStatus::Review, PeriodStatus::Finalized, PeriodStatus::Locked], true)) {
            return [];
        }

        return app(WorkflowQuery::class)->correctableActuals($this->review, $this->currentUser());
    }

    #[Computed]
    public function canResubmit(): bool
    {
        return ($this->review->state === ReviewState::Returned || $this->row->stale)
            && $this->review->period->status === PeriodStatus::Review
            && app(WorkflowAccess::class)->mayResubmit($this->currentUser(), $this->review->assignment);
    }

    public function approve(WorkflowService $workflow): void
    {
        $this->guard();
        $this->decide(__('Scorecard disetujui.'), fn () => $workflow->approve($this->selfActor(), $this->currentUser(), $this->review->id, $this->lock, $this->comment));
    }

    public function sendBack(WorkflowService $workflow): void
    {
        $this->guard();
        $this->decide(__('Scorecard dikembalikan.'), fn () => $workflow->returnForRevision($this->selfActor(), $this->currentUser(), $this->review->id, $this->lock, $this->comment));
    }

    public function calibrate(WorkflowService $workflow): void
    {
        $this->guard();
        $this->decide(__('Kalibrasi disimpan.'), fn () => $workflow->calibrate(
            $this->selfActor(),
            $this->currentUser(),
            $this->review->id,
            $this->lock,
            $this->calibratedScore,
            $this->calibrationReason,
            $this->calibrationEvidence,
        ));
    }

    public function resubmit(WorkflowService $workflow): void
    {
        $this->guard();
        $this->decide(__('Scorecard diajukan ulang.'), fn () => $workflow->resubmit($this->selfActor(), $this->currentUser(), $this->review->id, $this->lock, $this->comment));
    }

    public function requestCorrection(CorrectionService $corrections): void
    {
        $this->guard();

        // Only actuals behind this scorecard that the account may correct.
        abort_unless(array_key_exists($this->correctionActual, $this->correctable), 422);
        $actual = KpiActual::query()->where('public_id', $this->correctionActual)->firstOrFail();

        $done = $this->attempt('correction', __('Permintaan koreksi dikirim dan menunggu persetujuan.'), function () use ($corrections, $actual): void {
            $corrections->request(
                $this->selfActor(),
                $this->currentUser(),
                $actual->id,
                $this->correctionValue,
                $this->correctionNote,
                $this->correctionReason,
                $this->correctionEvidence,
            );
        });

        if ($done) {
            $this->reset('correctionActual', 'correctionValue', 'correctionNote', 'correctionReason', 'correctionEvidence');
        }
    }

    /**
     * Access is checked again on every action, not only when the page opens.
     */
    private function guard(): void
    {
        $this->authorize('viewReview', $this->review);
    }

    private function decide(string $message, Closure $operation): void
    {
        $done = $this->attempt('action', $message, $operation);
        unset($this->review, $this->row, $this->chain, $this->lines, $this->correctable, $this->canResubmit);

        if ($done) {
            $this->lock = $this->review->lock_version;
            $this->reset('comment', 'calibratedScore', 'calibrationReason', 'calibrationEvidence');
        }
    }
}; ?>

@php
    $review = $this->review;
    $row = $this->row;
    $assignment = $review->assignment;
    $calculation = $review->calculation;
    $period = $review->period;
    $calibration = $review->currentCalibration();
    $zone = $period->timezone;
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Approval scorecard').' — '.$assignment->subject_name"
        :description="$assignment->subject_type->label().' · '.$assignment->subject_code.' · '.$assignment->pathLabel().' · '.$period->code.' ('.$period->status->label().')'"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('approvals.period', $period)" wire:navigate>{{ __('Alur approval periode') }}</x-ui.button>
            @can('viewScorecards', $period)
                <x-ui.button variant="secondary" :href="route('scorecards.show', [$period, $assignment->public_id])" wire:navigate>{{ __('Rincian perhitungan') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'correction']])

    @if ($row->stale)
        <x-ui.alert variant="warning" :title="__('Ada perhitungan yang lebih baru')">
            {{ __('Scorecard ini dihitung ulang setelah masuk alur approval. Persetujuan yang sudah diberikan berlaku untuk skor lama; HR perlu mengajukannya ulang dengan perhitungan terbaru.') }}
        </x-ui.alert>
    @endif

    @if ($row->waitingOn)
        <x-ui.alert variant="warning" :title="__('Tahap ini belum memiliki approver')">
            {{ $row->waitingOn }}. {{ __('Sistem tidak mengisi approver dengan asumsi. Tahap ini menunggu konfigurasi approver atau bypass yang disetujui untuk team ini.') }}
        </x-ui.alert>
    @endif

    <x-ui.panel :title="__('Status: :state', ['state' => $review->state->label()])" :description="__('Putaran :round · perhitungan #:sequence · aturan :policy', ['round' => $review->round, 'sequence' => $calculation->sequence_number, 'policy' => $calculation->policyReference()])">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Skor hasil perhitungan') }}</dt><dd class="text-lg font-semibold">{{ $calculation->final_score ?? '—' }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Rating hasil perhitungan') }}</dt><dd>{{ $calculation->rating_label ?? '—' }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Skor setelah kalibrasi') }}</dt><dd class="text-lg font-semibold">{{ $calibration?->calibrated_final_score ?? '—' }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Rating setelah kalibrasi') }}</dt><dd>{{ $calibration?->calibrated_rating_label ?? '—' }}</dd></div>
        </dl>

        @if ($calibration)
            <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                {{ $calibration->is_adjusted ? __('Skor diubah pada kalibrasi oleh :name.', ['name' => $calibration->calibratedBy->name]) : __('Kalibrasi oleh :name mengonfirmasi skor hasil perhitungan.', ['name' => $calibration->calibratedBy->name]) }}
                @if ($calibration->reason) {{ __('Alasan: :reason', ['reason' => $calibration->reason]) }} @endif
                @if ($calibration->evidence_reference) {{ __('Referensi bukti: :ref', ['ref' => $calibration->evidence_reference]) }} @endif
            </p>
        @elseif ($review->calibrations->isNotEmpty())
            <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Kalibrasi sebelumnya dibuat untuk perhitungan atau putaran lain dan tidak berlaku untuk skor ini; lihat riwayat.') }}</p>
        @endif
    </x-ui.panel>

    <x-ui.table-shell :caption="__('KPI pada perhitungan yang disetujui')" :columns="[__('KPI'), __('Bobot'), __('Target'), __('Nilai terukur'), __('Status data'), __('Skor KPI'), __('Kontribusi')]">
        @foreach ($this->lines as $line)
            <tr wire:key="line-{{ $line->line_number }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $line->kpi_name }} <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $line->kpi_code }}</span></th>
                <td class="px-4 py-3">{{ $line->weight }}</td>
                <td class="px-4 py-3">{{ $line->target_value ?? '—' }}</td>
                <td class="px-4 py-3">{{ $line->measured_value ?? '—' }}</td>
                <td class="px-4 py-3">{{ $line->data_status->label() }}</td>
                <td class="px-4 py-3">{{ $line->kpi_score ?? '—' }}</td>
                <td class="px-4 py-3">{{ $line->contribution ?? '—' }}</td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    <x-ui.table-shell :caption="__('Rantai approver putaran ini')" :columns="[__('Tahap'), __('Approver saat periode dibuka'), __('Keputusan putaran ini')]">
        @foreach ($this->chain as $step)
            <tr wire:key="chain-{{ $step['stage']->value }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $step['stage']->label() }}</th>
                <td class="px-4 py-3">
                    @if ($step['approvers'] !== [])
                        {{ implode(', ', $step['approvers']) }}
                    @else
                        <x-ui.status-badge status="warning">{{ $step['gap'] ?? __('Belum dikonfigurasi') }}</x-ui.status-badge>
                        @if ($step['bypass'])
                            <span class="mt-1 block text-xs">{{ __('Bypass berlaku: :ref', ['ref' => $step['bypass']]) }}</span>
                        @endif
                    @endif
                </td>
                <td class="px-4 py-3">{{ $step['decision'] ?? __('Belum ada') }}</td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    @if ($row->actionable === ApprovalStage::Review || $row->actionable === ApprovalStage::FinalApproval)
        <x-ui.panel :title="__('Keputusan Anda: :stage', ['stage' => $row->actionable->label()])" :description="__('Menyetujui meneruskan scorecard ke tahap berikutnya. Mengembalikan wajib disertai alasan.')">
            <div class="grid gap-4">
                <x-ui.textarea name="comment" :label="__('Catatan (wajib saat mengembalikan)')" wire:model="comment" />
                <div class="flex flex-wrap gap-3">
                    <x-ui.button wire:click="approve" wire:loading.attr="disabled" wire:target="approve,sendBack">{{ $row->actionable === ApprovalStage::FinalApproval ? __('Beri approval final') : __('Setujui review') }}</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="sendBack" wire:loading.attr="disabled" wire:target="approve,sendBack">{{ __('Kembalikan') }}</x-ui.button>
                </div>
            </div>
        </x-ui.panel>
    @elseif ($row->actionable === ApprovalStage::Calibration)
        <x-ui.panel
            :title="__('Kalibrasi')"
            :description="$row->viaBypass
                ? __('Tahap review tidak memiliki approver dan bypass untuk team ini sudah disetujui; bypass dicatat bersama kalibrasi ini.')
                : __('Kosongkan skor kalibrasi untuk mengonfirmasi skor hasil perhitungan. Skor yang berbeda wajib disertai alasan; skor hasil perhitungan tetap tersimpan.')"
        >
            <form wire:submit="calibrate" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="calibratedScore" :label="__('Skor kalibrasi (opsional)')" :hint="__('Skor hasil perhitungan: :score', ['score' => $calculation->final_score])" inputmode="decimal" wire:model="calibratedScore" />
                <x-ui.input name="calibrationEvidence" :label="__('Referensi bukti/notulen (opsional)')" wire:model="calibrationEvidence" />
                <div class="sm:col-span-2"><x-ui.textarea name="calibrationReason" :label="__('Alasan (wajib bila skor diubah)')" wire:model="calibrationReason" /></div>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="calibrate,sendBack">{{ __('Simpan kalibrasi') }}</x-ui.button>
                </div>
            </form>

            @unless ($row->viaBypass)
                <div class="mt-6 grid gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <x-ui.textarea name="comment" :label="__('Alasan pengembalian')" wire:model="comment" />
                    <div><x-ui.button variant="secondary" wire:click="sendBack" wire:loading.attr="disabled" wire:target="calibrate,sendBack">{{ __('Kembalikan') }}</x-ui.button></div>
                </div>
            @endunless
        </x-ui.panel>
    @endif

    @if ($this->canResubmit)
        <x-ui.panel :title="__('Ajukan ulang')" :description="__('Scorecard masuk kembali ke tahap review sebagai putaran baru dengan perhitungan lengkap terakhir. Riwayat putaran sebelumnya tetap tersimpan.')">
            <form wire:submit="resubmit" class="grid gap-4">
                <x-ui.textarea name="comment" :label="__('Catatan tindak lanjut')" required wire:model="comment" />
                <div><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="resubmit">{{ __('Ajukan ulang') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->correctable !== [])
        <x-ui.panel :title="__('Minta koreksi aktual')" :description="__('Aktual terverifikasi hanya berubah lewat koreksi yang disetujui orang lain. Persetujuan membuat versi aktual baru, menghitung ulang scorecard, dan mengulang approval scorecard yang skornya berubah. Versi lama tetap tersimpan.')">
            <form wire:submit="requestCorrection" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-ui.select name="correctionActual" :label="__('Aktual yang dikoreksi')" :placeholder="__('Pilih aktual')" :options="$this->correctable" required wire:model="correctionActual" /></div>
                <x-ui.input name="correctionValue" :label="__('Nilai yang benar')" :hint="__('Kosongkan untuk menjadikan N/A (catatan wajib).')" inputmode="decimal" wire:model="correctionValue" />
                <x-ui.input name="correctionEvidence" :label="__('Referensi bukti (opsional)')" wire:model="correctionEvidence" />
                <x-ui.textarea name="correctionNote" :label="__('Catatan aktual (wajib untuk N/A)')" wire:model="correctionNote" />
                <x-ui.textarea name="correctionReason" :label="__('Alasan koreksi')" required wire:model="correctionReason" />
                <div class="sm:col-span-2"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="requestCorrection">{{ __('Kirim permintaan koreksi') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.table-shell :caption="__('Riwayat approval')" :columns="[__('Waktu'), __('Putaran'), __('Tahap'), __('Keputusan'), __('Oleh'), __('Sebagai'), __('Catatan')]">
        @foreach ($review->decisions as $decision)
            <tr wire:key="decision-{{ $decision->public_id }}">
                <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $decision->decided_at->setTimezone($zone)->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-3">{{ $decision->round }}</td>
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ DecisionOutcome::stageLabel($decision->stage) }}</th>
                <td class="px-4 py-3">{{ $decision->outcome->label() }}</td>
                <td class="px-4 py-3">{{ $decision->actor->name }}@if ($decision->actingFor) <span class="block text-xs">{{ __('atas nama :name (delegasi)', ['name' => $decision->actingFor->name]) }}</span>@endif</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $decision->actor_role }}</td>
                <td class="px-4 py-3">{{ $decision->comment ?? '—' }}</td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada keputusan')" :description="__('Riwayat terisi saat tahap pertama diputuskan.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    @if ($review->calibrations->isNotEmpty())
        <x-ui.table-shell :caption="__('Riwayat kalibrasi')" :columns="[__('Waktu'), __('Putaran'), __('Skor hitung'), __('Skor kalibrasi'), __('Rating'), __('Oleh'), __('Alasan')]">
            @foreach ($review->calibrations as $entry)
                <tr wire:key="calibration-{{ $entry->public_id }}">
                    <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $entry->calibrated_at->setTimezone($zone)->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3">{{ $entry->round }}</td>
                    <td class="px-4 py-3">{{ $entry->original_final_score }} ({{ $entry->original_rating_label }})</td>
                    <td class="px-4 py-3 font-semibold">{{ $entry->calibrated_final_score }}</td>
                    <td class="px-4 py-3">{{ $entry->calibrated_rating_label }}</td>
                    <td class="px-4 py-3">{{ $entry->calibratedBy->name }}</td>
                    <td class="px-4 py-3">{{ $entry->reason ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table-shell>
    @endif
</div>
