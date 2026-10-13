<?php

use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\EvidenceFile;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Domain\Vocabulary;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Application\ScorecardQuery;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Scorecard')] class extends GuardedPage {
    #[Locked]
    public string $periodId = '';

    #[Locked]
    public string $assignmentId = '';

    #[Url(as: 'perhitungan')]
    public ?int $sequence = null;

    #[Locked]
    public ?int $detailLine = null;

    public function mount(AssessmentPeriod $period, string $assignment): void
    {
        $this->authorize('viewScorecards', $period);

        $subject = PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('public_id', $assignment)->firstOrFail();
        abort_unless(app(ScorePeriodAccess::class)->viewScope($this->currentUser())->covers($subject), 403);

        $this->periodId = $period->public_id;
        $this->assignmentId = $subject->public_id;
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->where('public_id', $this->periodId)->firstOrFail();
    }

    #[Computed]
    public function assignment(): PeriodAssignment
    {
        return PeriodAssignment::query()->where('assessment_period_id', $this->period->id)->where('public_id', $this->assignmentId)->firstOrFail();
    }

    #[Computed]
    public function calculation(): ?ScorecardCalculation
    {
        return app(ScorecardQuery::class)->calculation($this->assignment, $this->sequence);
    }

    /**
     * @return Collection<int, ScorecardCalculation>
     */
    #[Computed]
    public function history(): Collection
    {
        return app(ScorecardQuery::class)->history($this->assignment);
    }

    /**
     * True when the latest calculation no longer matches the data and rules.
     * Null when it cannot be checked (no approved policy).
     */
    #[Computed]
    public function stale(): ?bool
    {
        $latest = $this->history->first();

        if ($latest === null) {
            return null;
        }

        $current = app(ScorecardQuery::class)->currentFingerprint($this->period, $this->assignment);

        return $current === null ? null : $current !== $latest->input_hash;
    }

    #[Computed]
    public function detail(): ?ScorecardCalculationLine
    {
        return $this->detailLine === null ? null : $this->calculation?->lines->firstWhere('line_number', $this->detailLine);
    }

    /**
     * Evidence of the entries of the opened line, when the account may see
     * the actuals of that target.
     *
     * @return array<string, Collection<int, EvidenceFile>>|null actual public id => files; null = no access
     */
    #[Computed]
    public function evidence(): ?array
    {
        $line = $this->detail;

        if ($line === null || $line->target === null || ! app(ActualPeriodAccess::class)->allowsTarget($this->currentUser(), Capability::ActualView, $line->target)) {
            return null;
        }

        return KpiActual::query()
            ->where('period_target_id', $line->period_target_id)
            ->whereIn('public_id', array_column($line->trace(), 'reference'))
            ->with('evidence')
            ->get()
            ->mapWithKeys(fn (KpiActual $actual): array => [$actual->public_id => $actual->evidence])
            ->all();
    }

    /**
     * Access is checked again on every action, not only when the page opens.
     */
    private function guard(): void
    {
        $period = AssessmentPeriod::query()->where('public_id', $this->periodId)->firstOrFail();
        $this->authorize('viewScorecards', $period);

        $subject = PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('public_id', $this->assignmentId)->firstOrFail();
        abort_unless(app(ScorePeriodAccess::class)->viewScope($this->currentUser())->covers($subject), 403);
    }

    public function showLine(int $lineNumber): void
    {
        $this->guard();
        abort_unless($this->calculation?->lines->contains('line_number', $lineNumber) ?? false, 404);

        $this->detailLine = $this->detailLine === $lineNumber ? null : $lineNumber;
        unset($this->detail, $this->evidence);
    }

    public function updatedSequence(): void
    {
        $this->guard();
        $this->detailLine = null;
        unset($this->calculation, $this->detail, $this->evidence);
    }

    public function openEvidence(string $evidenceId, EvidenceService $files): void
    {
        $this->guard();
        $evidence = EvidenceFile::query()->with('actual.target')->where('public_id', $evidenceId)->firstOrFail();
        $line = $this->detail;

        // Only evidence of an entry shown in this scorecard, for an account that may see the actual.
        abort_unless($line !== null && $evidence->actual->period_target_id === $line->period_target_id
            && in_array($evidence->actual->public_id, array_column($line->trace(), 'reference'), true), 404);
        abort_unless(app(ActualPeriodAccess::class)->allowsTarget($this->currentUser(), Capability::ActualView, $evidence->actual->target), 403);

        $link = null;

        $this->attempt('evidence', '', function () use ($files, $evidence, &$link): void {
            $link = $files->linkFor($evidence, $this->currentUser());
        });

        if (is_string($link)) {
            $this->flash = null;
            $this->redirect($link);
        }
    }
}; ?>

@php
    $period = $this->period;
    $subject = $this->assignment;
    $calc = $this->calculation;
    $decisionLabels = ['used' => __('Dipakai'), 'not_applicable' => __('N/A — tidak dihitung'), 'pending' => __('Belum terverifikasi'), 'excluded_late' => __('Terlambat — tidak dihitung')];
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Scorecard').' — '.$subject->subject_name" :description="$subject->subject_type->label().' · '.$subject->subject_code.' · '.$subject->pathLabel().' · '.$period->code">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('scorecards.period', $period)" wire:navigate>{{ __('Kembali ke rekonsiliasi') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['evidence']])

    @if ($calc === null)
        <x-ui.empty-state :title="__('Scorecard belum dihitung')" :description="__('Belum ada perhitungan untuk subjek ini. Perhitungan memerlukan aturan scorecard yang disetujui (DEC-004).')" />
    @else
        @if ($this->stale === true && $calc->id === $this->history->first()?->id)
            <x-ui.alert variant="warning" :title="__('Data atau aturan berubah sejak perhitungan ini')">
                {{ __('Target, aktual, atau aturan yang dipakai sudah berbeda. Hitung ulang untuk memperbarui skor; perhitungan ini tetap tersimpan.') }}
            </x-ui.alert>
        @endif

        @if ($calc->id !== $this->history->first()?->id)
            <x-ui.alert variant="info" :title="__('Anda melihat perhitungan lama (#:seq)', ['seq' => $calc->sequence_number])">
                {{ __('Perhitungan ini sudah digantikan oleh perhitungan yang lebih baru.') }}
            </x-ui.alert>
        @endif

        <x-ui.panel :title="__('Perhitungan #:seq', ['seq' => $calc->sequence_number])" :description="__('Dihitung :at oleh :by. Alasan: :reason', ['at' => $calc->calculated_at->setTimezone($period->timezone)->format('Y-m-d H:i'), 'by' => $calc->calculatedBy?->name ?? __('Sistem'), 'reason' => $calc->reason])">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$calc->status === ScorecardStatus::Complete ? 'success' : 'warning'">{{ $calc->status->label() }}</x-ui.status-badge></dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Skor akhir') }}</dt><dd class="text-lg font-semibold">{{ $calc->final_score ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Rating') }}</dt><dd>{{ $calc->rating_label ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Σ kontribusi') }}</dt><dd>{{ $calc->contribution_total ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Bobot berlaku / dinilai / N/A') }}</dt><dd>{{ $calc->assigned_weight }} / {{ $calc->counted_weight }} / {{ $calc->excluded_weight }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Template') }}</dt><dd class="font-mono text-xs">{{ $calc->inputs['template'] ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Aturan scorecard / mesin') }}</dt><dd class="font-mono text-xs">{{ $calc->policyReference() }} · engine v{{ $calc->engine_version }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Sidik input') }}</dt><dd class="font-mono text-xs" title="{{ $calc->input_hash }}">{{ substr($calc->input_hash, 0, 16) }}…</dd></div>
            </dl>

            @if ($calc->blockers !== [])
                <ul class="mt-4 list-disc ps-5 text-sm text-warning-800 dark:text-warning-200">
                    @foreach ($calc->blockers as $blocker)
                        <li>{{ ScorecardStatus::blockerLabel($blocker) }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>

        <x-ui.table-shell :caption="__('Rincian KPI')" :columns="[__('KPI'), __('Bobot'), __('Target'), __('Aktual periode'), __('Pencapaian'), __('Skor KPI'), __('Kontribusi'), __('Status data'), __('Rincian')]">
            @foreach ($calc->lines as $line)
                <tr wire:key="line-{{ $calc->id }}-{{ $line->line_number }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">
                        {{ $line->kpi_name }}
                        <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $line->kpi_code }}{{ $line->component ? ' · '.$line->component : '' }} · {{ $line->strategy_key.'@'.$line->strategy_version }} · {{ Vocabulary::label(Vocabulary::AGGREGATIONS, $line->aggregation) }}</span>
                    </th>
                    <td class="px-4 py-3">{{ $line->weight }}%</td>
                    <td class="px-4 py-3">{{ $line->target_value ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $line->measured_value ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $line->achievement === null ? '—' : $line->achievement.'%' }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $line->kpi_score ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $line->contribution ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <x-ui.status-badge :status="$line->data_status->tone()">{{ $line->data_status->label() }}</x-ui.status-badge>
                        @if ($line->note)
                            <span class="mt-1 block font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $line->note }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.button variant="ghost" wire:click="showLine({{ $line->line_number }})">{{ $this->detailLine === $line->line_number ? __('Tutup') : __('Sumber') }}<span class="sr-only"> {{ $line->kpi_code }}</span></x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table-shell>

        @if ($this->detail !== null)
            @php($detail = $this->detail)
            <section class="flex flex-col gap-3" aria-labelledby="detail-heading">
                <h2 id="detail-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Sumber perhitungan') }}: {{ $detail->kpi_code }}</h2>
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    {{ __('Nilai eksak — aktual periode: :measured · pencapaian: :achievement · skor sebelum pembulatan: :raw', [
                        'measured' => $detail->details['measured_exact'] ?? '—',
                        'achievement' => $detail->details['achievement_exact'] ?? '—',
                        'raw' => $detail->details['raw_score_exact'] ?? '—',
                    ]) }}
                </p>

                <x-ui.table-shell :caption="__('Entri aktual yang dipertimbangkan')" :columns="[__('Observasi'), __('Nilai'), __('Status'), __('Keputusan'), __('Bukti')]">
                    @foreach ($detail->trace() as $entry)
                        <tr wire:key="trace-{{ $entry['reference'] }}">
                            <td class="px-4 py-3 whitespace-nowrap">{{ $entry['window_start'] === $entry['window_end'] ? $entry['window_start'] : $entry['window_start'].' — '.$entry['window_end'] }}</td>
                            <td class="px-4 py-3">{{ $entry['kind'] === 'not_applicable' ? 'N/A' : $entry['value'] }}</td>
                            <td class="px-4 py-3">{{ $entry['status'] }} · v{{ $entry['version'] }}{{ $entry['late'] ? ' · '.__('terlambat') : '' }}</td>
                            <td class="px-4 py-3">{{ $decisionLabels[$entry['decision']] ?? $entry['decision'] }}</td>
                            <td class="px-4 py-3">
                                @if ($this->evidence === null)
                                    <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Tidak tersedia untuk akun Anda') }}</span>
                                @else
                                    @forelse ($this->evidence[$entry['reference']] ?? [] as $file)
                                        @if ($file->scan_status->isServable())
                                            <x-ui.button variant="ghost" wire:click="openEvidence('{{ $file->public_id }}')">{{ $file->original_name }}</x-ui.button>
                                        @else
                                            <span class="block text-xs">{{ $file->original_name }} ({{ $file->scan_status->label() }})</span>
                                        @endif
                                    @empty
                                        —
                                    @endforelse
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state :title="__('Tidak ada entri aktual')" :description="__('Target ini tidak memiliki entri pada saat perhitungan (missing).')" />
                    </x-slot:empty>
                </x-ui.table-shell>
            </section>
        @endif
    @endif

    @if ($this->history->isNotEmpty())
        <x-ui.table-shell :caption="__('Riwayat perhitungan')" :columns="[__('#'), __('Waktu'), __('Oleh'), __('Alasan'), __('Status'), __('Skor akhir'), __('Aturan'), __('Lihat')]">
            @foreach ($this->history as $item)
                <tr wire:key="history-{{ $item->public_id }}">
                    <td class="px-4 py-3">{{ $item->sequence_number }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $item->calculated_at->setTimezone($period->timezone)->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3">{{ $item->calculatedBy?->name ?? __('Sistem') }}</td>
                    <td class="px-4 py-3">{{ $item->reason }}</td>
                    <td class="px-4 py-3">{{ $item->status->label() }}</td>
                    <td class="px-4 py-3">{{ $item->final_score ?? '—' }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $item->policyReference() }}</td>
                    <td class="px-4 py-3">
                        @if ($calc?->id !== $item->id)
                            <x-ui.button variant="ghost" wire:click="$set('sequence', {{ $item->sequence_number }})">{{ __('Lihat') }}<span class="sr-only"> #{{ $item->sequence_number }}</span></x-ui.button>
                        @else
                            {{ __('Ditampilkan') }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table-shell>
    @endif
</div>
