<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Workflow\Application\ReviewRow;
use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Approval')] class extends GuardedPage {
    /** @var list<string> review public ids ticked for bulk approval */
    public array $selected = [];

    public string $comment = '';

    /**
     * Lock version of each listed review as this account saw it.
     *
     * @var array<string, int>
     */
    #[Locked]
    public array $locks = [];

    /** @var list<string> */
    #[Locked]
    public array $failures = [];

    public function mount(): void
    {
        Gate::authorize('approvals.view');
        $this->captureLocks();
    }

    /**
     * @return Collection<int, ReviewRow>
     */
    #[Computed]
    public function inbox(): Collection
    {
        return app(WorkflowQuery::class)->inbox($this->currentUser());
    }

    /**
     * @return Collection<int, ReviewRow>
     */
    #[Computed]
    public function toResubmit(): Collection
    {
        return app(WorkflowQuery::class)->toResubmit($this->currentUser());
    }

    /**
     * @return Collection<int, CorrectionRequest>
     */
    #[Computed]
    public function corrections(): Collection
    {
        return app(WorkflowQuery::class)->correctionsToDecide($this->currentUser());
    }

    /**
     * @return Collection<int, AssessmentPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return AssessmentPeriod::query()
            ->whereIn('status', [PeriodStatus::InProgress->value, PeriodStatus::Review->value, PeriodStatus::Finalized->value, PeriodStatus::Locked->value])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (AssessmentPeriod $period): bool => $this->currentUser()->can('viewWorkflow', $period))
            ->values();
    }

    /**
     * Approves the ticked scorecards that wait for a review or a final
     * approval by this account. Calibration is never done in bulk.
     */
    public function approveSelected(WorkflowService $workflow): void
    {
        Gate::authorize('approvals.view');
        $this->failures = [];

        $locks = [];
        $names = [];

        foreach ($this->inbox as $row) {
            $id = $row->review->public_id;

            if (in_array($id, $this->selected, true) && $row->actionable !== ApprovalStage::Calibration && isset($this->locks[$id])) {
                $locks[$row->review->id] = $this->locks[$id];
                $names[$row->review->id] = $row->review->assignment->subject_code;
            }
        }

        $results = [];

        $done = $this->attempt('action', '', function () use ($workflow, $locks, &$results): void {
            $results = $workflow->approveMany($this->selfActor(), $this->currentUser(), $locks, $this->comment);
        });

        if (! $done) {
            return;
        }

        $approved = 0;

        foreach ($results as $reviewId => $problem) {
            if ($problem === null) {
                $approved++;
            } else {
                $this->failures[] = "{$names[$reviewId]}: {$problem}";
            }
        }

        $this->flash = __(':approved scorecard disetujui, :failed tidak diproses.', ['approved' => $approved, 'failed' => count($this->failures)]);
        $this->reset('selected', 'comment');
        unset($this->inbox);
        $this->captureLocks();
    }

    private function captureLocks(): void
    {
        $this->locks = [];

        foreach ($this->inbox as $row) {
            $this->locks[$row->review->public_id] = $row->review->lock_version;
        }
    }
}; ?>

@php
    $inbox = $this->inbox;
    $bulk = $inbox->filter(fn ($row) => $row->actionable !== ApprovalStage::Calibration);
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Approval')"
        :description="__('Scorecard yang menunggu keputusan Anda. Setiap keputusan dicatat bersama role dan delegasi yang dipakai; skor hasil perhitungan tidak pernah ditimpa.')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('approvals.corrections')" wire:navigate>{{ __('Koreksi') }}</x-ui.button>
            @can('approvalBypasses.manage')
                <x-ui.button variant="secondary" :href="route('approvals.bypasses')" wire:navigate>{{ __('Bypass tahap review') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    @if ($failures !== [])
        <x-ui.alert variant="warning" :title="__('Scorecard yang tidak diproses')">
            <ul class="list-disc ps-5">
                @foreach ($failures as $failure)
                    <li>{{ $failure }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <section class="flex flex-col gap-3" aria-labelledby="inbox-heading">
        <h2 id="inbox-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Menunggu keputusan Anda (:count)', ['count' => $inbox->count()]) }}</h2>

        <form wire:submit="approveSelected" class="flex flex-col gap-3">
            <x-ui.table-shell :caption="__('Scorecard yang menunggu keputusan Anda')" :columns="[__('Pilih'), __('Subjek'), __('Periode'), __('Tahap'), __('Skor'), __('Rating'), __('Putaran'), __('Buka')]">
                @foreach ($inbox as $row)
                    @php($review = $row->review)
                    <tr wire:key="inbox-{{ $review->public_id }}">
                        <td class="px-4 py-3">
                            @if ($row->actionable !== ApprovalStage::Calibration)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:model="selected" value="{{ $review->public_id }}" class="size-4">
                                    <span class="sr-only">{{ __('Pilih :subject', ['subject' => $review->assignment->subject_code]) }}</span>
                                </label>
                            @else
                                <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Satu per satu') }}</span>
                            @endif
                        </td>
                        <th scope="row" class="px-4 py-3 text-start font-medium">
                            {{ $review->assignment->subject_name }}
                            <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $review->assignment->subject_code }} · {{ $review->assignment->unit_code }}</span>
                        </th>
                        <td class="px-4 py-3">{{ $review->period->code }}</td>
                        <td class="px-4 py-3">
                            <x-ui.status-badge status="info">{{ $row->actionable->label() }}</x-ui.status-badge>
                            @if ($row->viaBypass)
                                <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Review dilewati lewat bypass yang disetujui') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-semibold">{{ $row->score['score'] ?? '—' }}@if ($row->score['adjusted']) <span class="font-normal">({{ __('dikalibrasi') }})</span>@endif</td>
                        <td class="px-4 py-3">{{ $row->score['rating_label'] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $review->round }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('approvals.review', $review) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                                {{ __('Buka') }}<span class="sr-only"> {{ $review->assignment->subject_code }}</span>
                            </a>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada yang menunggu keputusan Anda')" :description="__('Scorecard muncul di sini saat tahap yang menjadi kewenangan Anda tiba.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            @if ($bulk->isNotEmpty())
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.textarea name="comment" :label="__('Catatan untuk scorecard terpilih (opsional)')" wire:model="comment" />
                    <div class="self-end">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="approveSelected">{{ __('Setujui yang dipilih') }}</x-ui.button>
                    </div>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Persetujuan massal berlaku untuk tahap review dan approval final. Kalibrasi dan pengembalian dilakukan dari halaman scorecard.') }}</p>
            @endif
        </form>
    </section>

    @if ($this->toResubmit->isNotEmpty())
        <x-ui.table-shell :caption="__('Scorecard yang menunggu pengajuan ulang')" :columns="[__('Subjek'), __('Periode'), __('Sebab'), __('Putaran'), __('Buka')]">
            @foreach ($this->toResubmit as $row)
                @php($review = $row->review)
                <tr wire:key="resubmit-{{ $review->public_id }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">{{ $review->assignment->subject_name }} <span class="font-mono text-xs font-normal">{{ $review->assignment->subject_code }}</span></th>
                    <td class="px-4 py-3">{{ $review->period->code }}</td>
                    <td class="px-4 py-3">{{ $row->stale ? __('Ada perhitungan lebih baru') : __('Dikembalikan approver') }}</td>
                    <td class="px-4 py-3">{{ $review->round }}</td>
                    <td class="px-4 py-3"><a href="{{ route('approvals.review', $review) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">{{ __('Tindak lanjuti') }}<span class="sr-only"> {{ $review->assignment->subject_code }}</span></a></td>
                </tr>
            @endforeach
        </x-ui.table-shell>
    @endif

    @if ($this->corrections->isNotEmpty())
        <x-ui.alert variant="info" :title="__(':count permintaan koreksi menunggu keputusan Anda', ['count' => $this->corrections->count()])">
            <a href="{{ route('approvals.corrections') }}" wire:navigate class="font-medium underline hover:no-underline">{{ __('Buka antrean koreksi') }}</a>
        </x-ui.alert>
    @endif

    <x-ui.table-shell :caption="__('Alur approval per periode')" :columns="[__('Kode'), __('Nama'), __('Rentang'), __('Status'), __('Alur approval')]">
        @foreach ($this->periods as $period)
            <tr wire:key="period-{{ $period->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $period->code }}</td>
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $period->name }}</th>
                <td class="px-4 py-3 whitespace-nowrap">{{ $period->starts_on }} — {{ $period->ends_on }}</td>
                <td class="px-4 py-3">{{ $period->status->label() }}</td>
                <td class="px-4 py-3"><a href="{{ route('approvals.period', $period) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">{{ __('Buka') }}<span class="sr-only"> {{ $period->code }}</span></a></td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada periode')" :description="__('Periode muncul di sini setelah masuk tahap In progress dan berada dalam scope Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
