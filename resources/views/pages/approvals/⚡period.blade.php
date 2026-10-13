<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Workflow\Application\ReviewRow;
use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Domain\ReviewState;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Alur approval periode')] class extends GuardedPage {
    #[Locked]
    public string $periodId = '';

    /** Lock version of the period as this account saw it. */
    #[Locked]
    public int $periodLock = 0;

    #[Url(as: 'status')]
    public string $state = '';

    #[Url(as: 'cari')]
    public string $search = '';

    public function mount(AssessmentPeriod $period): void
    {
        $this->authorize('viewWorkflow', $period);
        $this->periodId = $period->public_id;
        $this->periodLock = $period->lock_version;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['state', 'search'], true)) {
            unset($this->rows);
        }
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->where('public_id', $this->periodId)->firstOrFail();
    }

    /**
     * @return Collection<int, ReviewRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(WorkflowQuery::class)->board(
            $this->period,
            $this->currentUser(),
            ReviewState::tryFrom($this->state)?->value,
            $this->search,
        );
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(WorkflowQuery::class)->stateCounts($this->period, $this->currentUser());
    }

    /**
     * @return array{next: PeriodStatus|null, blockers: list<string>}
     */
    #[Computed]
    public function checklist(): array
    {
        return app(WorkflowQuery::class)->checklist($this->period);
    }

    #[Computed]
    public function canAdvance(): bool
    {
        return $this->checklist['next'] !== null && $this->currentUser()->can('update', $this->period);
    }

    /**
     * Moves the period to its next status when the precheck has no blocker.
     * The precheck runs again inside the transaction.
     */
    public function advance(PeriodService $periods): void
    {
        $this->authorize('viewWorkflow', $this->period);
        $this->authorize('update', $this->period);

        $next = $this->checklist['next'];
        abort_if($next === null, 422);

        $done = $this->attempt('action', __('Periode berpindah ke status :status.', ['status' => $next->label()]), function () use ($periods, $next): void {
            $periods->advance(
                $this->actorFor(Capability::PeriodManage, $this->access()->unitTarget($this->period->organization_unit_id)),
                $this->period->id,
                $this->periodLock,
                $next,
            );
        });

        unset($this->period, $this->rows, $this->counts, $this->checklist);

        if ($done) {
            $this->periodLock = $this->period->lock_version;
        }
    }
}; ?>

@php
    $period = $this->period;
    $checklist = $this->checklist;
    $tone = fn (ReviewState $state): string => match ($state) {
        ReviewState::FinalApproved => 'success',
        ReviewState::Returned => 'warning',
        default => 'info',
    };
    $stateOptions = collect(ReviewState::cases())->mapWithKeys(fn (ReviewState $state) => [$state->value => $state->label()])->all();
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Alur approval').' — '.$period->name" :description="$period->code.' · '.$period->starts_on.' — '.$period->ends_on.' · '.$period->status->label()">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('approvals.index')" wire:navigate>{{ __('Kembali ke approval') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <x-ui.panel
        :title="$checklist['next'] === null ? __('Checklist periode') : __('Checklist menuju :status', ['status' => $checklist['next']->label()])"
        :description="__('Periode hanya maju bila seluruh pemeriksaan lulus. Periode tidak pernah mundur; setelah Review, data hanya berubah melalui koreksi yang disetujui.')"
    >
        @if ($checklist['next'] === null)
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ $period->status === PeriodStatus::Locked ? __('Periode sudah dikunci.') : __('Periode belum mencapai tahap pengisian aktual.') }}
            </p>
        @elseif ($checklist['blockers'] === [])
            <x-ui.status-badge status="success">{{ __('Semua pemeriksaan lulus') }}</x-ui.status-badge>
        @else
            <ul class="list-disc ps-5 text-sm text-zinc-800 dark:text-zinc-200" aria-label="{{ __('Hal yang masih menahan periode') }}">
                @foreach ($checklist['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        @endif

        @if ($this->canAdvance)
            <div class="mt-4">
                <x-ui.button wire:click="advance" wire:loading.attr="disabled" wire:target="advance" :disabled="$checklist['blockers'] !== []"
                    wire:confirm="{{ __('Pindahkan periode ke status :status? Langkah ini tidak dapat dibatalkan.', ['status' => $checklist['next']->label()]) }}">
                    {{ $checklist['next'] === PeriodStatus::Locked ? __('Kunci periode') : __('Lanjutkan ke :status', ['status' => $checklist['next']->label()]) }}
                </x-ui.button>
            </div>
        @endif
    </x-ui.panel>

    @if ($period->status !== PeriodStatus::InProgress)
        <section class="flex flex-col gap-2" aria-labelledby="summary-heading">
            <h2 id="summary-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Ringkasan dalam scope Anda') }}</h2>
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                @foreach (ReviewState::cases() as $state)
                    {{ $state->label() }}: {{ $this->counts[$state->value] }}@if (! $loop->last) · @endif
                @endforeach
            </p>
        </section>

        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-2" role="search" aria-label="{{ __('Filter scorecard') }}">
            <x-ui.input name="search" type="search" :label="__('Cari NIK, nama, atau unit')" wire:model.live.debounce.400ms="search" />
            <x-ui.select name="state" :label="__('Status approval')" :placeholder="__('Semua status')" :options="$stateOptions" wire:model.live="state" />
        </form>

        <x-ui.table-shell :caption="__('Status approval per scorecard')" :columns="[__('Subjek'), __('Status'), __('Putaran'), __('Skor hitung'), __('Skor berlaku'), __('Rating'), __('Catatan'), __('Buka')]">
            @foreach ($this->rows as $row)
                @php($review = $row->review)
                <tr wire:key="review-{{ $review->public_id }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">
                        {{ $review->assignment->subject_name }}
                        <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $review->assignment->subject_type->label() }} · {{ $review->assignment->subject_code }} · {{ $review->assignment->unit_code }}</span>
                    </th>
                    <td class="px-4 py-3"><x-ui.status-badge :status="$tone($review->state)">{{ $review->state->label() }}</x-ui.status-badge></td>
                    <td class="px-4 py-3">{{ $review->round }}</td>
                    <td class="px-4 py-3">{{ $review->calculation->final_score ?? '—' }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $row->score['score'] ?? '—' }}@if ($row->score['adjusted']) <span class="font-normal">({{ __('dikalibrasi') }})</span>@endif</td>
                    <td class="px-4 py-3">{{ $row->score['rating_label'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs">
                        @if ($row->waitingOn)
                            <x-ui.status-badge status="warning">{{ $row->waitingOn }}</x-ui.status-badge>
                        @elseif ($row->stale)
                            <x-ui.status-badge status="warning">{{ __('Ada perhitungan lebih baru') }}</x-ui.status-badge>
                        @elseif ($row->actionable)
                            {{ __('Menunggu keputusan Anda') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('approvals.review', $review) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                            {{ __('Buka') }}<span class="sr-only"> {{ $review->assignment->subject_code }}</span>
                        </a>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Tidak ada scorecard')" :description="__('Tidak ada scorecard dalam alur approval yang cocok dengan filter dan scope Anda.')" />
            </x-slot:empty>
        </x-ui.table-shell>
    @endif
</div>
