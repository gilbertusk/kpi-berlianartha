<?php

use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Application\ReminderService;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Reporting\Application\DashboardQuery;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScorecardPolicyCatalog;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Application\TeamReviewQuery;
use App\Modules\Workflow\Application\WorkflowQuery;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Dashboard')] class extends GuardedPage {
    #[Url(as: 'periode')]
    public string $periodCode = '';

    /**
     * @return Collection<int, AssessmentPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return app(DashboardQuery::class)->periods($this->currentUser());
    }

    /**
     * The chosen period when the account may see it, otherwise the newest.
     */
    #[Computed]
    public function period(): ?AssessmentPeriod
    {
        return $this->periods->firstWhere('code', $this->periodCode) ?? $this->periods->first();
    }

    /**
     * Which dashboard sections this account gets, from its roles.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function sections(): array
    {
        $grants = $this->access()->grants($this->currentUser());
        $manager = $grants->hasRole(Role::DepartmentHead) || $grants->hasRole(Role::TeamLeader);

        return [
            'management' => $grants->hasRole(Role::Management),
            'hr' => $grants->hasRole(Role::HrPerformance),
            'manager' => $manager,
            'employee' => $this->currentUser()->employee !== null,
            'auditor' => $grants->hasRole(Role::Auditor),
            'admin' => $grants->hasRole(Role::SystemAdmin),
        ];
    }

    #[Computed]
    public function scoreScope(): ScoreAccess
    {
        $scope = app(ScorePeriodAccess::class)->viewScope($this->currentUser());

        // Aggregates never include the account's own scorecard.
        return new ScoreAccess($scope->units, $scope->individuals, null);
    }

    #[Computed]
    public function seesScorecards(): bool
    {
        return $this->period !== null && $this->scoreScope->reachesOthers()
            && ($this->sections['management'] || $this->sections['hr'] || $this->sections['manager'] || $this->sections['auditor']);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function scorecards(): array
    {
        $query = app(DashboardQuery::class);
        $threshold = config('dashboard.red_kpi_score_below');
        $threshold = is_string($threshold) && preg_match('/^\d{1,6}(\.\d{1,4})?$/', $threshold) === 1 ? $threshold : null;

        return [
            'summary' => $query->scorecardSummary($this->period, $this->scoreScope),
            'ratings' => $query->ratingDistribution($this->period, $this->scoreScope),
            'trend' => $query->unitTrend($this->period, $this->scoreScope),
            'threshold' => $threshold,
            'red' => $threshold === null ? null : $query->kpisBelow($this->period, $this->scoreScope, $threshold),
        ];
    }

    /**
     * @return array{targets: int, missing: int, draft: int, submitted: int, rejected: int, verified: int, late: int, entries: int}|null
     */
    #[Computed]
    public function completeness(): ?array
    {
        if ($this->period === null || ! ($this->sections['management'] || $this->sections['hr'] || $this->sections['manager'] || $this->sections['auditor'])) {
            return null;
        }

        $access = app(ActualPeriodAccess::class);

        if (! $access->concerns($this->currentUser(), Capability::ActualView, $this->period)) {
            return null;
        }

        return app(DashboardQuery::class)->completeness($this->period, $access->scope($this->currentUser(), Capability::ActualView));
    }

    /**
     * @return array{inbox: int, resubmit: int, corrections: int}|null
     */
    #[Computed]
    public function approvals(): ?array
    {
        // Counters of decisions to take: not for read-only roles.
        if (! $this->currentUser()->can('approvals.view') || ! ($this->sections['management'] || $this->sections['hr'] || $this->sections['manager'])) {
            return null;
        }

        $query = app(WorkflowQuery::class);

        return [
            'inbox' => $query->inbox($this->currentUser())->count(),
            'resubmit' => $query->toResubmit($this->currentUser())->count(),
            'corrections' => $query->correctionsToDecide($this->currentUser())->count(),
        ];
    }

    /**
     * @return array{next: PeriodStatus|null, blockers: list<string>}|null
     */
    #[Computed]
    public function checklist(): ?array
    {
        return $this->sections['hr'] && $this->period !== null && $this->currentUser()->can('viewWorkflow', $this->period)
            ? app(WorkflowQuery::class)->checklist($this->period)
            : null;
    }

    /**
     * @return array{outstanding: int, overdue: int, to_verify: int}|null
     */
    #[Computed]
    public function actions(): ?array
    {
        // A to-do counter for those who manage a review or own an action;
        // read-only roles reach the same list through the Team Review menu.
        $counts = $this->currentUser()->can('team-review.view')
            ? app(TeamReviewQuery::class)->actionCounts($this->currentUser(), app(TeamReviewAccess::class)->viewScope($this->currentUser()))
            : null;

        if ($counts === null || (! $this->currentUser()->can('team-review.manage') && $counts['outstanding'] === 0)) {
            return null;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function own(): ?array
    {
        $employee = $this->currentUser()->employee;

        if ($employee === null || $this->period === null) {
            return null;
        }

        $query = app(DashboardQuery::class);
        $assignment = $query->ownAssignment($this->period, $employee->id);

        if ($assignment === null) {
            return null;
        }

        $calculation = $query->latestCalculation($assignment);

        return [
            'assignment' => $assignment,
            'kpis' => $query->ownKpis($this->period, $employee->id),
            'calculation' => $calculation?->status === ScorecardStatus::Complete ? $calculation : null,
            'can_enter' => app(ActualPeriodAccess::class)->concerns($this->currentUser(), Capability::ActualEnter, $this->period),
        ];
    }

    /**
     * @return array<string, int>|null
     */
    #[Computed]
    public function audit(): ?array
    {
        return $this->sections['auditor'] && $this->period !== null ? app(DashboardQuery::class)->auditSummary($this->period) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function health(): ?array
    {
        if (! $this->sections['admin']) {
            return null;
        }

        try {
            $policies = count(app(ScorecardPolicyCatalog::class)->all());
            $rules = count(app(ReminderService::class)->rules());
        } catch (InvalidArgumentException) {
            $policies = $rules = null;
        }

        return [
            ...app(DashboardQuery::class)->health(),
            'email_enabled' => app(NotificationService::class)->emailEnabled(),
            'policies' => $policies,
            'rules' => $rules,
        ];
    }
}; ?>

@php
    $period = $this->period;
    $sections = $this->sections;
    $periodOptions = $this->periods->mapWithKeys(fn ($item) => [$item->code => $item->code.' — '.$item->name.' ('.$item->status->label().')'])->all();
    $stateLabels = ['missing' => __('Belum diisi'), 'draft' => __('Draft'), 'submitted' => __('Menunggu verifikasi'), 'rejected' => __('Ditolak'), 'verified' => __('Terverifikasi')];
    $stateTones = ['missing' => 'warning', 'draft' => 'neutral', 'submitted' => 'info', 'rejected' => 'danger', 'verified' => 'success'];
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Dashboard')" :description="__('Ringkasan sesuai peran dan scope akses Anda. Setiap angka adalah hitungan record tersimpan dan dapat ditelusuri lewat tautan di sampingnya.')" />

    @if ($period === null)
        <x-ui.alert variant="info" :title="__('Belum ada periode yang dapat ditampilkan')">
            {{ __('Dashboard menampilkan data setelah ada periode pada tahap In progress atau sesudahnya di dalam scope Anda. Tidak ada angka contoh yang ditampilkan.') }}
        </x-ui.alert>
    @else
        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-2" aria-label="{{ __('Pilih periode') }}">
            <x-ui.select name="periodCode" :label="__('Periode')" :options="$periodOptions" wire:model.live="periodCode" :placeholder="$period->code.' — '.$period->name.' ('.$period->status->label().')'" />
        </form>
    @endif

    {{-- Tasks: what waits for this account. --}}
    @if ($this->approvals !== null || $this->actions !== null)
        <section class="flex flex-col gap-3" aria-labelledby="tasks-heading">
            <h2 id="tasks-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Menunggu tindakan Anda') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if ($this->approvals !== null)
                    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Scorecard menunggu keputusan') }}</dt>
                        <dd class="text-2xl font-semibold">{{ $this->approvals['inbox'] }}</dd>
                        <a href="{{ route('approvals.index') }}" wire:navigate class="{{ $link }} text-sm">{{ __('Buka inbox approval') }}</a>
                    </div>
                    @if ($this->approvals['resubmit'] > 0 || $sections['hr'])
                        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Scorecard menunggu pengajuan ulang') }}</dt>
                            <dd class="text-2xl font-semibold">{{ $this->approvals['resubmit'] }}</dd>
                            <a href="{{ route('approvals.index') }}" wire:navigate class="{{ $link }} text-sm">{{ __('Buka approval') }}</a>
                        </div>
                    @endif
                    @if ($this->approvals['corrections'] > 0 || $sections['management'])
                        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Koreksi menunggu keputusan') }}</dt>
                            <dd class="text-2xl font-semibold">{{ $this->approvals['corrections'] }}</dd>
                            <a href="{{ route('approvals.corrections') }}" wire:navigate class="{{ $link }} text-sm">{{ __('Buka antrean koreksi') }}</a>
                        </div>
                    @endif
                @endif
                @if ($this->actions !== null)
                    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Corrective action belum selesai / lewat tenggat / menunggu verifikasi') }}</dt>
                        <dd class="text-2xl font-semibold">{{ $this->actions['outstanding'] }} / {{ $this->actions['overdue'] }} / {{ $this->actions['to_verify'] }}</dd>
                        <a href="{{ route('team-review.index', ['tindakan_status' => 'overdue']) }}" wire:navigate class="{{ $link }} text-sm">{{ __('Buka team review') }}</a>
                    </div>
                @endif
            </dl>
        </section>
    @endif

    {{-- Scorecards in scope: Management, HR, managers, auditors. --}}
    @if ($this->seesScorecards)
        @php($cards = $this->scorecards)
        <section class="flex flex-col gap-3" aria-labelledby="scorecards-heading">
            <h2 id="scorecards-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Scorecard dalam scope Anda — :period', ['period' => $period->code]) }}</h2>
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('Subjek: :subjects · Tercakup template: :covered · Belum dihitung: :not_calculated · Lengkap: :complete · Belum lengkap: :incomplete', $cards['summary']) }}
                · <a href="{{ route('scorecards.period', $period) }}" wire:navigate class="{{ $link }}">{{ __('Lihat rekonsiliasi') }}</a>
            </p>

            <x-ui.table-shell :caption="__('Distribusi rating hasil perhitungan (sebelum kalibrasi)')" :columns="[__('Rating'), __('Jumlah scorecard')]">
                @foreach ($cards['ratings'] as $rating)
                    <tr wire:key="rating-{{ $loop->index }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $rating['label'] }}</th>
                        <td class="px-4 py-3">{{ $rating['count'] }}</td>
                    </tr>
                @endforeach
                <x-slot:empty>
                    <x-ui.empty-state :title="__('Belum ada rating')" :description="__('Rating muncul setelah scorecard dihitung lengkap dengan aturan scorecard yang disetujui (DEC-004).')" />
                </x-slot:empty>
            </x-ui.table-shell>

            <x-ui.table-shell :caption="__('Skor scorecard unit: periode ini dan periode sebelumnya')" :columns="[__('Unit'), __('Skor periode ini'), __('Skor periode sebelumnya'), __('Perubahan'), __('Detail')]">
                @foreach ($cards['trend'] as $row)
                    <tr wire:key="trend-{{ $row['assignment']->public_id }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $row['assignment']->subject_name }} <span class="font-mono text-xs font-normal">{{ $row['assignment']->subject_code }}</span></th>
                        <td class="px-4 py-3 font-semibold">{{ $row['current'] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $row['previous'] ?? '—' }}@if ($row['previous_period']) <span class="text-xs">({{ $row['previous_period'] }})</span>@endif</td>
                        <td class="px-4 py-3">{{ $row['change'] ?? '—' }}</td>
                        <td class="px-4 py-3"><a href="{{ route('scorecards.show', [$period, $row['assignment']->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Buka') }}<span class="sr-only"> {{ $row['assignment']->subject_code }}</span></a></td>
                    </tr>
                @endforeach
                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada scorecard unit')" :description="__('Tidak ada scorecard unit dalam scope Anda pada periode ini.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            @if ($cards['threshold'] === null)
                <x-ui.alert variant="info" :title="__('KPI merah belum dapat ditampilkan')">
                    {{ __('Ambang skor yang menandai KPI sebagai merah belum disetujui (DEC-004). Sistem tidak memakai ambang bawaan.') }}
                </x-ui.alert>
            @else
                <x-ui.table-shell :caption="__('KPI dengan skor di bawah :threshold', ['threshold' => $cards['threshold']])" :columns="[__('Subjek'), __('KPI'), __('Target'), __('Nilai terukur'), __('Skor KPI'), __('Detail')]">
                    @foreach ($cards['red'] as $line)
                        <tr wire:key="red-{{ $line->id }}">
                            <th scope="row" class="px-4 py-3 text-start font-medium">{{ $line->calculation->assignment->subject_name }} <span class="font-mono text-xs font-normal">{{ $line->calculation->assignment->subject_code }}</span></th>
                            <td class="px-4 py-3">{{ $line->kpi_name }} <span class="font-mono text-xs">{{ $line->kpi_code }}</span></td>
                            <td class="px-4 py-3">{{ $line->target_value ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $line->measured_value ?? '—' }}</td>
                            <td class="px-4 py-3"><x-ui.status-badge status="danger">{{ $line->kpi_score }}</x-ui.status-badge></td>
                            <td class="px-4 py-3"><a href="{{ route('scorecards.show', [$period, $line->calculation->assignment->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Buka') }}<span class="sr-only"> {{ $line->kpi_code }}</span></a></td>
                        </tr>
                    @endforeach
                    <x-slot:empty>
                        <x-ui.empty-state :title="__('Tidak ada KPI di bawah ambang')" :description="__('Tidak ada KPI dalam scope Anda dengan skor di bawah ambang pada perhitungan terakhir.')" />
                    </x-slot:empty>
                </x-ui.table-shell>
            @endif
        </section>
    @endif

    {{-- Data completeness. --}}
    @if ($this->completeness !== null)
        <section class="flex flex-col gap-2" aria-labelledby="completeness-heading">
            <h2 id="completeness-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Kelengkapan aktual dalam scope Anda — :period', ['period' => $period->code]) }}</h2>
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('Target wajib: :targets · Belum diisi: :missing · Draft: :draft · Menunggu verifikasi: :submitted · Ditolak: :rejected · Terverifikasi: :verified · Entri terlambat: :late', $this->completeness) }}
                · <a href="{{ route('actuals.entry', $period) }}" wire:navigate class="{{ $link }}">{{ __('Buka aktual') }}</a>
            </p>
        </section>
    @endif

    {{-- HR: lock readiness. --}}
    @if ($this->checklist !== null)
        <x-ui.panel :title="$this->checklist['next'] === null ? __('Kesiapan periode') : __('Kesiapan menuju :status', ['status' => $this->checklist['next']->label()])">
            @if ($this->checklist['next'] === null)
                <p class="text-sm">{{ __('Periode tidak memiliki langkah berikutnya.') }}</p>
            @elseif ($this->checklist['blockers'] === [])
                <x-ui.status-badge status="success">{{ __('Semua pemeriksaan lulus') }}</x-ui.status-badge>
            @else
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($this->checklist['blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3"><a href="{{ route('approvals.period', $period) }}" wire:navigate class="{{ $link }} text-sm">{{ __('Buka alur approval periode') }}</a></p>
        </x-ui.panel>
    @endif

    {{-- Managers: link to the team page. --}}
    @can('team.view')
        <x-ui.alert variant="info" :title="__('Halaman team')">
            <a href="{{ route('team.index') }}" wire:navigate class="font-medium underline hover:no-underline">{{ __('Anggota, status aktual per anggota, target versus aktual team, coaching notes, dan histori anggota') }}</a>
        </x-ui.alert>
    @endcan

    {{-- Employee: own KPIs. --}}
    @if ($this->own !== null)
        @php($own = $this->own)
        <section class="flex flex-col gap-3" aria-labelledby="own-heading">
            <h2 id="own-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('KPI saya — :period', ['period' => $period->code]) }}</h2>

            @if ($period->deadlines->isNotEmpty())
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    {{ __('Tenggat:') }}
                    @foreach ($period->deadlines->sortBy('due_on') as $deadline)
                        {{ $deadline->stage->label() }} {{ $deadline->due_on }}@if (! $loop->last) · @endif
                    @endforeach
                </p>
            @endif

            <x-ui.table-shell :caption="__('KPI saya dan status aktualnya')" :columns="[__('KPI'), __('Status aktual'), __('Umpan balik')]">
                @foreach ($own['kpis'] as $row)
                    <tr wire:key="own-{{ $row['target']->public_id }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $row['target']->kpi_name }} <span class="font-mono text-xs font-normal">{{ $row['target']->kpi_code }}</span></th>
                        <td class="px-4 py-3"><x-ui.status-badge :status="$stateTones[$row['state']]">{{ $stateLabels[$row['state']] }}</x-ui.status-badge></td>
                        <td class="px-4 py-3">{{ $row['feedback'] ?? '—' }}</td>
                    </tr>
                @endforeach
                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada KPI pribadi')" :description="__('Tidak ada target KPI atas nama Anda pada periode ini.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                @if ($own['calculation'])
                    {{ __('Skor terakhir: :score (:rating).', ['score' => $own['calculation']->final_score, 'rating' => $own['calculation']->rating_label]) }}
                    <a href="{{ route('scorecards.show', [$period, $own['assignment']->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Lihat scorecard saya') }}</a>
                @else
                    {{ __('Skor belum tersedia: scorecard Anda belum dihitung lengkap.') }}
                @endif
            </p>

            @php($todo = collect($own['kpis'])->whereIn('state', ['missing', 'draft', 'rejected'])->count())
            @if ($todo > 0 && $own['can_enter'])
                <x-ui.alert variant="warning" :title="__('Tindakan berikutnya: :count KPI perlu diisi, diajukan, atau diperbaiki', ['count' => $todo])">
                    <a href="{{ route('actuals.entry', $period) }}" wire:navigate class="font-medium underline hover:no-underline">{{ __('Buka pengisian aktual') }}</a>
                </x-ui.alert>
            @endif
        </section>
    @endif

    {{-- Auditor: read-only reconciliation. --}}
    @if ($this->audit !== null)
        <x-ui.panel :title="__('Audit dan rekonsiliasi — :period (hanya baca)', ['period' => $period->code])">
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('Perhitungan tersimpan: :calculations · Hitung ulang: :recalculations · Keputusan approval: :decisions · Kalibrasi dengan penyesuaian: :calibrations_adjusted · Koreksi disetujui: :corrections · Scorecard final: :reviews_final', $this->audit) }}
            </p>
            <ul class="mt-3 list-disc ps-5 text-sm">
                <li><a href="{{ route('scorecards.period', $period) }}" wire:navigate class="{{ $link }}">{{ __('Rekonsiliasi scorecard dan riwayat perhitungan') }}</a></li>
                <li><a href="{{ route('approvals.period', $period) }}" wire:navigate class="{{ $link }}">{{ __('Rantai approval dan riwayat keputusan') }}</a></li>
                <li><a href="{{ route('periods.show', $period) }}" wire:navigate class="{{ $link }}">{{ __('Snapshot periode dan jejak audit') }}</a></li>
            </ul>
        </x-ui.panel>
    @endif

    {{-- System admin: technical health, no KPI values. --}}
    @if ($this->health !== null)
        @php($health = $this->health)
        <x-ui.panel :title="__('Kesehatan sistem dan konfigurasi')" :description="__('Tampilan teknis. Tidak menampilkan atau mengubah nilai KPI.')">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Outbox menunggu / gagal') }}</dt><dd>{{ $health['outbox_pending'] }} / {{ $health['outbox_failed'] }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Job antrean gagal') }}</dt><dd>{{ $health['failed_jobs'] }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Email notifikasi menunggu / gagal') }}</dt><dd>{{ $health['email_pending'] }} / {{ $health['email_failed'] }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Channel email') }}</dt><dd>{{ $health['email_enabled'] ? __('Aktif') : __('Nonaktif (DEC-014 belum diputuskan)') }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Aturan scorecard disetujui') }}</dt><dd>{{ $health['policies'] === null ? __('Konfigurasi tidak valid') : ($health['policies'] === 0 ? __('Belum ada (DEC-004)') : $health['policies']) }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Aturan pengingat/eskalasi disetujui') }}</dt><dd>{{ $health['rules'] === null ? __('Konfigurasi tidak valid') : ($health['rules'] === 0 ? __('Belum ada (DEC-009)') : $health['rules']) }}</dd></div>
            </dl>
        </x-ui.panel>
    @endif

    @if ($period === null && ! $sections['admin'] && $this->approvals === null && $this->actions === null)
        <x-ui.empty-state :title="__('Belum ada data untuk akun Anda')" :description="__('Dashboard terisi setelah akun Anda memiliki peran atau KPI pada sebuah periode.')" />
    @endif
</div>
