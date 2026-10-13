<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScorecardQuery;
use App\Modules\Scoring\Application\ScorecardRow;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Scoring\Application\ScoringService;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Scorecard')] class extends GuardedPage {
    use WithPagination;

    #[Locked]
    public string $periodId = '';

    #[Url(as: 'unit')]
    public string $unit = '';

    #[Url(as: 'status')]
    public string $state = '';

    #[Url(as: 'cari')]
    public string $search = '';

    public string $reason = '';

    public function mount(AssessmentPeriod $period): void
    {
        $this->authorize('viewScorecards', $period);
        $this->periodId = $period->public_id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['unit', 'state', 'search'], true)) {
            $this->resetPage();
            unset($this->rows);
        }
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->with('scopeUnit')->where('public_id', $this->periodId)->firstOrFail();
    }

    /**
     * @return LengthAwarePaginator<int, ScorecardRow>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return app(ScorecardQuery::class)->rows(
            $this->period,
            $this->scope(),
            $this->unit === '' ? null : $this->unit,
            array_key_exists($this->state, ScorecardQuery::STATES) ? $this->state : null,
            $this->search,
            $this->getPage(),
        );
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function summary(): array
    {
        return app(ScorecardQuery::class)->summary($this->period, $this->scope());
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        return app(ScorecardQuery::class)->unitOptions($this->period, $this->scope());
    }

    /**
     * The approved policy for the period, a refusal message, or null.
     *
     * @return array{policy: ScorecardPolicy|null, problem: string|null}
     */
    #[Computed]
    public function policy(): array
    {
        try {
            return ['policy' => app(ScoringService::class)->policyFor($this->period), 'problem' => null];
        } catch (BusinessRuleViolation $violation) {
            return ['policy' => null, 'problem' => $violation->safeMessage()];
        }
    }

    #[Computed]
    public function scorable(): bool
    {
        return in_array($this->period->status, [PeriodStatus::InProgress, PeriodStatus::Review], true);
    }

    #[Computed]
    public function canCalculate(): bool
    {
        return $this->scorable && $this->policy['policy'] !== null && $this->currentUser()->can('calculateScorecards', $this->period);
    }

    #[Computed]
    public function canExport(): bool
    {
        return $this->scope()->reachesOthers();
    }

    public function calculate(ScoringService $scoring, ScorePeriodAccess $access): void
    {
        $this->authorize('calculateScorecards', $this->period);

        $summary = null;

        $done = $this->attempt('action', '', function () use ($scoring, $access, &$summary): void {
            $summary = $scoring->calculate(
                $this->actorFor(Capability::ScorecardCalculate),
                $this->period->id,
                $access->calculateScope($this->currentUser()),
                $this->reason,
            );
        });

        if ($done && is_array($summary)) {
            $this->flash = __('Perhitungan selesai: :created baru, :unchanged tidak berubah; :complete lengkap, :incomplete belum lengkap.', $summary);
            $this->reset('reason');
            unset($this->rows, $this->summary);
        }
    }

    private function scope(): ScoreAccess
    {
        return app(ScorePeriodAccess::class)->viewScope($this->currentUser());
    }
}; ?>

@php
    $period = $this->period;
    $summary = $this->summary;
    $policy = $this->policy['policy'];
    $stateTone = fn (string $state): string => match ($state) { 'complete' => 'success', 'incomplete' => 'warning', 'not_covered' => 'neutral', default => 'info' };
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Scorecard').' — '.$period->name" :description="$period->code.' · '.$period->starts_on.' — '.$period->ends_on.' · '.$period->status->label()">
        <x-slot:actions>
            @if ($this->canExport)
                <x-ui.button variant="secondary" :href="route('scorecards.export', $period)">{{ __('Ekspor rekonsiliasi (CSV)') }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" :href="route('scorecards.index')" wire:navigate>{{ __('Kembali ke daftar') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'reason']])

    @if ($policy === null)
        <x-ui.alert variant="warning" :title="__('Perhitungan skor resmi diblokir')">{{ $this->policy['problem'] }}</x-ui.alert>
    @else
        <x-ui.panel :title="__('Aturan scorecard :ref', ['ref' => $policy->reference()])" :description="__('Sign-off: :ref · berlaku :from — :until', ['ref' => $policy->signoffReference, 'from' => $policy->effectiveFrom, 'until' => $policy->effectiveUntil ?? __('seterusnya')])">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Data terlambat') }}</dt><dd>{{ ScorecardPolicy::LATE_DATA_RULES[$policy->lateDataRule] }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Bobot KPI N/A') }}</dt><dd>{{ ScorecardPolicy::NOT_APPLICABLE_WEIGHT_RULES[$policy->notApplicableWeightRule] }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Pembulatan kontribusi') }}</dt><dd>{{ $policy->contributionRounding->describe() }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Pembulatan skor akhir') }}</dt><dd>{{ $policy->finalRounding->describe() }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Rating band') }}</dt><dd>{{ implode(' · ', array_map(fn ($band) => $band->describe(), $policy->ratingBands)) }}</dd></div>
            </dl>
        </x-ui.panel>
    @endif

    <section class="flex flex-col gap-2" aria-labelledby="summary-heading">
        <h2 id="summary-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Ringkasan dalam scope Anda') }}</h2>
        <p class="text-sm text-zinc-700 dark:text-zinc-300">
            {{ __('Subjek: :subjects · Tercakup template: :covered · Belum dihitung: :not_calculated · Lengkap: :complete · Belum lengkap: :incomplete', $summary) }}
        </p>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Skor akhir = jumlah kontribusi yang sudah dibulatkan, lalu dibulatkan dengan aturan skor akhir. Scorecard yang belum lengkap tidak diberi skor akhir atau rating.') }}</p>
    </section>

    @if ($this->canCalculate)
        <x-ui.panel :title="__('Hitung scorecard')" :description="__('Menghitung semua scorecard dalam scope Anda. Scorecard yang input dan aturannya tidak berubah tidak dihitung ulang. Perhitungan baru disimpan sebagai versi baru; versi lama tetap tersimpan.')">
            <form wire:submit="calculate" class="grid gap-4 sm:grid-cols-2">
                <x-ui.textarea name="reason" :label="__('Alasan perhitungan')" required wire:model="reason" />
                <div class="self-end"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="calculate">{{ __('Hitung scorecard') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @elseif (! $this->scorable)
        <x-ui.alert variant="info" :title="__('Scorecard tidak dihitung pada status periode ini')">
            {{ in_array($period->status, [PeriodStatus::Finalized, PeriodStatus::Locked], true) ? __('Periode sudah final. Skor hanya berubah melalui proses koreksi yang disetujui.') : __('Skor dihitung setelah periode masuk tahap In progress.') }}
        </x-ui.alert>
    @endif

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter scorecard') }}">
        <x-ui.input name="search" type="search" :label="__('Cari NIK, nama, atau unit')" wire:model.live.debounce.400ms="search" />
        <x-ui.select name="unit" :label="__('Unit')" :placeholder="__('Semua unit')" :options="$this->unitOptions" wire:model.live="unit" />
        <x-ui.select name="state" :label="__('Status')" :placeholder="__('Semua status')" :options="ScorecardQuery::STATES" wire:model.live="state" />
    </form>

    <x-ui.table-shell :caption="__('Rekonsiliasi scorecard (perhitungan terakhir per subjek)')" :columns="[__('Subjek'), __('Template'), __('Status'), __('Bobot dinilai / N/A'), __('Σ kontribusi'), __('Skor akhir'), __('Rating'), __('Dihitung'), __('Detail')]">
        @foreach ($this->rows as $row)
            @php($calc = $row->calculation)
            <tr wire:key="scorecard-{{ $row->assignment->public_id }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">
                    {{ $row->assignment->subject_name }}
                    <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $row->assignment->subject_type->label() }} · {{ $row->assignment->subject_code }} · {{ $row->assignment->unit_code }}</span>
                </th>
                <td class="px-4 py-3 font-mono text-xs">{{ $row->assignment->template_code ? $row->assignment->template_code.' v'.$row->assignment->template_version_number : '—' }}</td>
                <td class="px-4 py-3"><x-ui.status-badge :status="$stateTone($row->state())">{{ ScorecardQuery::STATES[$row->state()] }}</x-ui.status-badge></td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $calc ? $calc->counted_weight.' / '.$calc->excluded_weight : '—' }}</td>
                <td class="px-4 py-3">{{ $calc?->contribution_total ?? '—' }}</td>
                <td class="px-4 py-3 font-semibold">{{ $calc?->final_score ?? '—' }}</td>
                <td class="px-4 py-3">{{ $calc?->rating_label ?? '—' }}</td>
                <td class="px-4 py-3 text-xs">{{ $calc ? '#'.$calc->sequence_number.' · '.$calc->calculated_at->setTimezone($period->timezone)->format('Y-m-d H:i') : '—' }}</td>
                <td class="px-4 py-3">
                    @if ($row->state() !== 'not_covered')
                        <a href="{{ route('scorecards.show', [$period, $row->assignment->public_id]) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                            {{ __('Buka') }}<span class="sr-only"> {{ $row->assignment->subject_code }}</span>
                        </a>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada scorecard')" :description="__('Tidak ada subjek yang cocok dengan filter dalam scope Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}
</div>
