<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\HomeAssignment;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Reporting\Application\TeamOverviewQuery;
use App\Modules\TeamReview\Application\CoachingNoteService;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Application\TeamReviewQuery;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CoachingNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Halaman team')] class extends GuardedPage {
    #[Url(as: 'team')]
    public string $unit = '';

    /** Employee number of the member whose coaching notes are open. */
    #[Locked]
    public string $memberNumber = '';

    public string $coachingNote = '';

    public function mount(): void
    {
        Gate::authorize('team.view');
    }

    public function updatedUnit(): void
    {
        Gate::authorize('team.view');
        $this->reset('memberNumber', 'coachingNote');
    }

    /**
     * @return list<UnitNode>
     */
    #[Computed]
    public function teams(): array
    {
        return app(TeamOverviewQuery::class)->teams($this->currentUser());
    }

    /**
     * The chosen team when the account manages it, otherwise its first team.
     */
    #[Computed]
    public function team(): ?UnitNode
    {
        return collect($this->teams)->firstWhere('code', $this->unit) ?? ($this->teams[0] ?? null);
    }

    /**
     * @return list<HomeAssignment>
     */
    #[Computed]
    public function members(): array
    {
        return $this->team === null ? [] : app(TeamOverviewQuery::class)->members($this->team->id);
    }

    #[Computed]
    public function period(): ?AssessmentPeriod
    {
        return $this->team === null ? null : app(TeamOverviewQuery::class)->currentPeriod($this->team->id);
    }

    /**
     * @return array<int, array{targets: int, missing: int, draft: int, submitted: int, rejected: int, verified: int}>
     */
    #[Computed]
    public function memberActuals(): array
    {
        return $this->team === null || $this->period === null ? [] : app(TeamOverviewQuery::class)->memberActuals($this->period, $this->team->id);
    }

    /**
     * @return array{calculation: mixed, lines: Collection<int, mixed>}|null
     */
    #[Computed]
    public function scorecard(): ?array
    {
        return $this->team === null || $this->period === null ? null : app(TeamOverviewQuery::class)->teamScorecard($this->period, $this->team->id);
    }

    /**
     * @return Collection<int, EmployeeAssignment>
     */
    #[Computed]
    public function history(): Collection
    {
        return $this->team === null ? new Collection : app(TeamOverviewQuery::class)->memberHistory($this->team->id);
    }

    #[Computed]
    public function canCoach(): bool
    {
        return $this->team !== null && app(TeamReviewAccess::class)->canWriteCoachingNote($this->currentUser(), $this->team->id);
    }

    /**
     * @return Collection<int, CoachingNote>
     */
    #[Computed]
    public function coachingNotes(): Collection
    {
        $employee = $this->openMember();

        return $employee === null
            ? new Collection
            : app(TeamReviewQuery::class)->coachingNotes($this->currentUser(), $employee, app(TeamReviewAccess::class)->coachingScope($this->currentUser()));
    }

    public function openCoaching(string $employeeNumber): void
    {
        Gate::authorize('team.view');
        abort_unless($this->canCoach, 403);
        abort_unless(collect($this->members)->contains(fn (HomeAssignment $member): bool => $member->employeeNumber === $employeeNumber), 404);

        $this->memberNumber = $employeeNumber;
        $this->reset('coachingNote');
        unset($this->coachingNotes);
    }

    public function addCoachingNote(CoachingNoteService $notes): void
    {
        Gate::authorize('team.view');
        $employee = $this->openMember();
        abort_if($employee === null, 404);

        $done = $this->attempt('action', __('Catatan coaching disimpan.'), function () use ($notes, $employee): void {
            $notes->add($this->selfActor(), $this->currentUser(), $employee->id, $this->coachingNote);
        });

        if ($done) {
            $this->reset('coachingNote');
            unset($this->coachingNotes);
        }
    }

    /**
     * The open member, only while they belong to the selected team.
     */
    private function openMember(): ?Employee
    {
        if ($this->memberNumber === '' || ! $this->canCoach) {
            return null;
        }

        $member = collect($this->members)->first(fn (HomeAssignment $candidate): bool => $candidate->employeeNumber === $this->memberNumber);

        return $member === null ? null : Employee::query()->find($member->employeeId);
    }
}; ?>

@php
    $team = $this->team;
    $period = $this->period;
    $teamOptions = collect($this->teams)->mapWithKeys(fn ($item) => [$item->code => $item->code.' — '.$item->name])->all();
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
    $leaveUrl = config('team_review.hris_leave_url');
    $leaveUrl = is_string($leaveUrl) && str_starts_with($leaveUrl, 'https://') ? $leaveUrl : null;
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Halaman team')" :description="__('Anggota, status aktual per anggota, target versus aktual team, masalah dan tindakan, coaching notes, serta histori anggota.')" />

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    @if ($team === null)
        <x-ui.empty-state :title="__('Tidak ada team dalam scope Anda')" :description="__('Halaman ini menampilkan team yang review-nya Anda kelola.')" />
    @else
        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-2" aria-label="{{ __('Pilih team') }}">
            <x-ui.select name="unit" :label="__('Team')" :options="$teamOptions" :placeholder="$team->code.' — '.$team->name" wire:model.live="unit" />
        </form>

        @if ($period === null)
            <x-ui.alert variant="info" :title="__('Belum ada periode berjalan untuk team ini')">
                {{ __('Status aktual dan target versus aktual muncul setelah team memiliki target pada periode In progress atau Review.') }}
            </x-ui.alert>
        @else
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('Periode berjalan: :code (:status).', ['code' => $period->code, 'status' => $period->status->label()]) }}
                <a href="{{ route('actuals.entry', $period) }}" wire:navigate class="{{ $link }}">{{ __('Verifikasi atau minta revisi aktual (tolak dengan alasan)') }}</a>
            </p>
        @endif

        <x-ui.table-shell :caption="__('Anggota :team dan status aktual KPI wajibnya', ['team' => $team->code])" :columns="[__('Anggota'), __('Jabatan'), __('Target'), __('Belum diisi'), __('Draft'), __('Menunggu verifikasi'), __('Ditolak'), __('Terverifikasi'), __('Coaching')]">
            @foreach ($this->members as $member)
                @php($status = $this->memberActuals[$member->employeeId] ?? null)
                <tr wire:key="member-{{ $member->employeeNumber }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">{{ $member->fullName }} <span class="block font-mono text-xs font-normal">{{ $member->employeeNumber }}</span></th>
                    <td class="px-4 py-3">{{ $member->positionTitle ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $status['targets'] ?? '—' }}</td>
                    <td class="px-4 py-3">@if (($status['missing'] ?? 0) > 0)<x-ui.status-badge status="warning">{{ $status['missing'] }}</x-ui.status-badge>@else{{ $status['missing'] ?? '—' }}@endif</td>
                    <td class="px-4 py-3">{{ $status['draft'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $status['submitted'] ?? '—' }}</td>
                    <td class="px-4 py-3">@if (($status['rejected'] ?? 0) > 0)<x-ui.status-badge status="danger">{{ $status['rejected'] }}</x-ui.status-badge>@else{{ $status['rejected'] ?? '—' }}@endif</td>
                    <td class="px-4 py-3">{{ $status['verified'] ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($this->canCoach && $member->userId !== auth()->id())
                            <x-ui.button variant="secondary" wire:click="openCoaching('{{ $member->employeeNumber }}')">{{ __('Catatan') }}<span class="sr-only"> {{ $member->employeeNumber }}</span></x-ui.button>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Tidak ada anggota')" :description="__('Tidak ada pegawai dengan home team ini pada hari ini.')" />
            </x-slot:empty>
        </x-ui.table-shell>

        @if ($memberNumber !== '' && $this->canCoach)
            <x-ui.panel :title="__('Coaching notes — :number', ['number' => $memberNumber])" :description="__('Data terbatas (DEC-011 belum disetujui): hanya penulis dan Team Leader team ini yang dapat membaca. Catatan tidak dapat diubah atau dihapus.')">
                <form wire:submit="addCoachingNote" class="grid gap-4">
                    <x-ui.textarea name="coachingNote" :label="__('Catatan baru')" required wire:model="coachingNote" />
                    <div><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="addCoachingNote">{{ __('Simpan catatan') }}</x-ui.button></div>
                </form>

                <ul class="mt-4 flex flex-col gap-3">
                    @forelse ($this->coachingNotes as $entry)
                        <li wire:key="note-{{ $entry->public_id }}" class="rounded-md border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                            <p>{{ $entry->note }}</p>
                            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{{ $entry->noted_on }} · {{ $entry->author->name }}</p>
                        </li>
                    @empty
                        <li class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Belum ada catatan yang dapat Anda baca.') }}</li>
                    @endforelse
                </ul>
            </x-ui.panel>
        @endif

        @if ($this->scorecard !== null)
            <x-ui.table-shell :caption="__('Target versus aktual team (perhitungan terakhir scorecard team)')" :columns="[__('KPI'), __('Bobot'), __('Target'), __('Nilai terukur'), __('Status data'), __('Skor KPI')]">
                @foreach ($this->scorecard['lines'] as $line)
                    <tr wire:key="line-{{ $line->id }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $line->kpi_name }} <span class="block font-mono text-xs font-normal">{{ $line->kpi_code }}</span></th>
                        <td class="px-4 py-3">{{ $line->weight }}</td>
                        <td class="px-4 py-3">{{ $line->target_value ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $line->measured_value ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $line->data_status->label() }}</td>
                        <td class="px-4 py-3">{{ $line->kpi_score ?? '—' }}</td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Scorecard team belum dihitung')" :description="__('Target versus aktual diambil dari perhitungan scorecard team. Skor resmi memerlukan aturan scorecard yang disetujui (DEC-004).')" />
                </x-slot:empty>
            </x-ui.table-shell>
        @endif

        <x-ui.panel :title="__('Masalah, tindakan, dan administrasi team')">
            <ul class="list-disc ps-5 text-sm">
                <li><a href="{{ route('team-review.index') }}" wire:navigate class="{{ $link }}">{{ __('Daily team review dan corrective action') }}</a></li>
                @can('viewAny', \App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation::class)
                    <li><a href="{{ route('administration.delegations') }}" wire:navigate class="{{ $link }}">{{ __('Delegasi penilaian') }}</a></li>
                @else
                    <li>{{ __('Delegasi penilaian dicatat oleh HR Performance pada menu Administration.') }}</li>
                @endcan
                <li>
                    @if ($leaveUrl)
                        <a href="{{ $leaveUrl }}" rel="noopener noreferrer" class="{{ $link }}">{{ __('Approval cuti (dibuka di sistem HR)') }}</a>
                    @else
                        {{ __('Approval cuti: belum ada sistem HR yang ditetapkan sebagai sumber data, sehingga tidak ada tautan dan tidak ada modul cuti di sistem ini.') }}
                    @endif
                </li>
            </ul>
        </x-ui.panel>

        <x-ui.table-shell :caption="__('Histori perubahan anggota :team', ['team' => $team->code])" :columns="[__('Pegawai'), __('Jenis'), __('Jabatan'), __('Mulai'), __('Sampai')]">
            @foreach ($this->history as $assignment)
                <tr wire:key="history-{{ $assignment->public_id }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">{{ $assignment->employee->full_name }} <span class="block font-mono text-xs font-normal">{{ $assignment->employee->employee_number }}</span></th>
                    <td class="px-4 py-3">{{ $assignment->assignment_type->value }}</td>
                    <td class="px-4 py-3">{{ $assignment->position_title ?? '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $assignment->effective_from }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $assignment->effective_until ?? __('masih aktif') }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Belum ada histori')" :description="__('Penempatan pegawai pada team ini muncul di sini.')" />
            </x-slot:empty>
        </x-ui.table-shell>
    @endif
</div>
