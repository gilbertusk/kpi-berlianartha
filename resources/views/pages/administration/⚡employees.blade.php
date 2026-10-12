<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\EmployeeQuery;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\OrganizationSnapshot;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Domain\AssignmentType;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Pegawai & penugasan')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'tanggal')]
    public string $asOf = '';

    #[Url(as: 'unit')]
    public string $unit = '';

    #[Url(as: 'cari')]
    public string $search = '';

    public string $newNumber = '';
    public string $newName = '';
    public string $newTeam = '';
    public string $newPosition = '';
    public string $newFrom = '';
    public string $newReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Employee::class);

        if (! $this->isDate($this->asOf)) {
            $this->asOf = $this->businessToday();
        }

        $this->newFrom = $this->businessToday();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['asOf', 'unit', 'search'], true)) {
            if (! $this->isDate($this->asOf)) {
                $this->asOf = $this->businessToday();
            }

            $this->resetPage();
        }
    }

    #[Computed]
    public function snapshot(): OrganizationSnapshot
    {
        return app(OrganizationDirectory::class)->snapshot($this->asOf);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $user = $this->currentUser();
        $scope = $this->access()->unitScope($user, Capability::EmployeeView);
        $unitFilter = $this->unit === '' ? null : OrganizationUnit::query()->where('public_id', $this->unit)->value('id');

        return app(EmployeeQuery::class)->paginate(
            allUnits: $scope->all,
            scopeUnitIds: $scope->unitIds,
            selfUserId: $scope->self ? $user->id : null,
            asOf: $this->asOf,
            unitFilter: $unitFilter === null ? null : (int) $unitFilter,
            search: $this->search,
            page: $this->getPage(),
        );
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        $scope = $this->access()->unitScope($this->currentUser(), Capability::EmployeeView);

        return collect($this->snapshot->ofType(UnitType::Team))
            ->filter(fn (UnitNode $team): bool => $scope->coversUnit($team->id))
            ->mapWithKeys(fn (UnitNode $team): array => [$team->publicId => $this->snapshot->pathLabel($team->id)])
            ->all();
    }

    /**
     * Teams where the viewer may register an employee on the chosen date.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function manageableTeams(): array
    {
        if (! $this->isDate($this->newFrom)) {
            return [];
        }

        $user = $this->currentUser();
        $snapshot = app(OrganizationDirectory::class)->snapshot($this->newFrom);

        return collect($snapshot->ofType(UnitType::Team))
            ->filter(fn (UnitNode $team): bool => $this->access()->allows($user, Capability::EmployeeManage, $this->access()->unitTarget($team->id)))
            ->mapWithKeys(fn (UnitNode $team): array => [$team->publicId => $snapshot->pathLabel($team->id)])
            ->all();
    }

    #[Computed]
    public function canRegister(): bool
    {
        return $this->currentUser()->can('create', Employee::class);
    }

    public function register(EmployeeService $employees): void
    {
        $validated = $this->validate([
            'newNumber' => ['required', 'string', 'max:40'],
            'newName' => ['required', 'string', 'max:160'],
            'newTeam' => ['required', 'string', 'size:26'],
            'newPosition' => ['nullable', 'string', 'max:120'],
            'newFrom' => ['required', 'date_format:Y-m-d'],
            'newReason' => ['required', 'string', 'max:2000'],
        ]);

        $team = OrganizationUnit::query()->where('public_id', $validated['newTeam'])->firstOrFail();
        $this->authorize('create', [Employee::class, $team]);

        $registered = $this->attempt('action', __('Pegawai berhasil didaftarkan.'), fn () => $employees->register(
            $this->actorFor(Capability::EmployeeManage, $this->access()->unitTarget($team->id)),
            $validated['newNumber'],
            $validated['newName'],
            $team->id,
            $validated['newFrom'],
            $validated['newPosition'] ?? null,
            $validated['newReason'],
        ));

        if ($registered) {
            $this->reset('newNumber', 'newName', 'newTeam', 'newPosition', 'newReason');
            unset($this->rows);
        }
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Pegawai & penugasan')"
        :description="__('Daftar pegawai sesuai scope Anda. Penugasan bertanggal efektif sehingga perpindahan team tidak mengubah histori.')"
    />

    @include('pages.administration.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter pegawai') }}">
        <x-ui.input name="asOf" type="date" :label="__('Per tanggal')" wire:model.live="asOf" />
        <x-ui.select name="unit" :label="__('Team')" :placeholder="__('Semua team dalam scope')" :options="$this->unitOptions" wire:model.live="unit" />
        <x-ui.input name="search" type="search" :label="__('Cari nama atau NIK')" wire:model.live.debounce.400ms="search" />
    </form>

    <x-ui.table-shell :caption="__('Daftar pegawai')" :columns="[__('NIK'), __('Nama'), __('Home team'), __('Penugasan sementara'), __('Akun'), __('Detail')]">
        @foreach ($this->rows as $employee)
            @php
                $home = $employee->assignments->first(fn ($assignment) => $assignment->assignment_type === AssignmentType::Home);
                $temporary = $employee->assignments->filter(fn ($assignment) => $assignment->assignment_type === AssignmentType::Temporary);
            @endphp
            <tr wire:key="employee-{{ $employee->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $employee->employee_number }}</td>
                <td class="px-4 py-3">{{ $employee->full_name }}</td>
                <td class="px-4 py-3">
                    @if ($home)
                        {{ $this->snapshot->pathLabel($home->organization_unit_id) }}
                        @if ($home->position_title)
                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $home->position_title }}</span>
                        @endif
                    @else
                        <x-ui.status-badge status="warning">{{ __('Belum ada') }}</x-ui.status-badge>
                    @endif
                </td>
                <td class="px-4 py-3">
                    {{ $temporary->isEmpty() ? '—' : $temporary->map(fn ($assignment) => $this->snapshot->pathLabel($assignment->organization_unit_id))->implode(', ') }}
                </td>
                <td class="px-4 py-3">
                    @if ($employee->user)
                        <x-ui.status-badge status="success">{{ __('Terhubung') }}</x-ui.status-badge>
                    @else
                        <x-ui.status-badge>{{ __('Belum') }}</x-ui.status-badge>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <a href="{{ route('administration.employees.show', $employee) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Lihat') }}<span class="sr-only"> {{ $employee->full_name }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada pegawai')" :description="__('Tidak ada pegawai dalam scope Anda untuk filter tersebut.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($this->canRegister)
        <x-ui.panel :title="__('Daftarkan pegawai')" :description="__('Membuat data pegawai sekaligus home team pertama.')">
            <form wire:submit="register" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="newNumber" :label="__('Nomor induk pegawai (NIK)')" required wire:model="newNumber" />
                <x-ui.input name="newName" :label="__('Nama lengkap')" required wire:model="newName" />
                <x-ui.input name="newFrom" type="date" :label="__('Mulai di home team')" required wire:model.live="newFrom" />
                <x-ui.select name="newTeam" :label="__('Home team')" required :placeholder="__('Pilih team')" :options="$this->manageableTeams" wire:model="newTeam" />
                <x-ui.input name="newPosition" :label="__('Jabatan')" wire:model="newPosition" />
                <x-ui.textarea name="newReason" :label="__('Alasan')" required wire:model="newReason" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Daftarkan') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
