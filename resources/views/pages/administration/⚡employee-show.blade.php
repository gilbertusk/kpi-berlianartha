<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Domain\AssignmentType;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Detail pegawai')] class extends GuardedPage {

    #[Locked]
    public string $employeeId = '';

    public string $mode = '';
    public string $unitId = '';
    public string $position = '';
    public string $from = '';
    public string $until = '';
    public string $fullName = '';
    public string $reason = '';
    public ?string $assignmentId = null;

    /** Version the form was opened against; a concurrent change yields 409. */
    #[Locked]
    public int $formVersion = 0;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);
        $this->employeeId = $employee->public_id;
    }

    #[Computed]
    public function employee(): Employee
    {
        return Employee::query()->with('user')->where('public_id', $this->employeeId)->firstOrFail();
    }

    #[Computed]
    public function today(): string
    {
        return $this->businessToday();
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->currentUser()->can('update', $this->employee);
    }

    /**
     * @return Collection<int, EmployeeAssignment>
     */
    #[Computed]
    public function assignments(): Collection
    {
        return EmployeeAssignment::query()
            ->with(['unit', 'createdBy', 'endedBy'])
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Teams in the viewer's manage scope on the chosen start date.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function teamOptions(): array
    {
        $date = $this->from !== '' ? $this->from : $this->businessToday();
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return [];
        }

        $user = $this->currentUser();
        $snapshot = app(OrganizationDirectory::class)->snapshot($date);

        return collect($snapshot->ofType(UnitType::Team))
            ->filter(fn (UnitNode $team): bool => $this->access()->allows($user, Capability::EmployeeManage, $this->access()->unitTarget($team->id)))
            ->mapWithKeys(fn (UnitNode $team): array => [$team->publicId => $snapshot->pathLabel($team->id)])
            ->all();
    }

    public function openForm(string $mode, ?string $assignmentId = null): void
    {
        abort_unless(in_array($mode, ['transfer', 'temporary', 'end', 'rename'], true), 404);
        $this->authorize('update', $this->employee);

        $this->resetErrorBag();
        $this->reset('unitId', 'position', 'until', 'reason');
        $this->mode = $mode;
        $this->assignmentId = $assignmentId;
        $this->from = $this->businessToday();
        $this->fullName = $this->employee->full_name;

        $this->formVersion = $this->employee->lock_version;

        if ($mode === 'end') {
            $assignment = EmployeeAssignment::query()
                ->where('employee_id', $this->employee->id)
                ->where('public_id', (string) $assignmentId)
                ->firstOrFail();

            $this->formVersion = $assignment->lock_version;
            $this->until = $this->businessToday();
        }
    }

    public function closeForm(): void
    {
        $this->reset('mode', 'assignmentId', 'formVersion', 'unitId', 'position', 'from', 'until', 'reason');
    }

    public function transfer(EmployeeService $employees): void
    {
        $validated = $this->validate([
            'unitId' => ['required', 'string', 'size:26'],
            'position' => ['nullable', 'string', 'max:120'],
            'from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $employee = $this->employee;
        $team = OrganizationUnit::query()->where('public_id', $validated['unitId'])->firstOrFail();
        $this->authorize('assign', [$employee, $team]);

        $this->finish($this->attempt('action', __('Perpindahan home team dicatat.'), fn () => $employees->transferHome(
            $this->actorFor(Capability::EmployeeManage, $this->access()->unitTarget($team->id)),
            $employee->id,
            $this->formVersion,
            $team->id,
            $validated['from'],
            $validated['position'] ?? null,
            $validated['reason'],
        )));
    }

    public function addTemporary(EmployeeService $employees): void
    {
        $validated = $this->validate([
            'unitId' => ['required', 'string', 'size:26'],
            'position' => ['nullable', 'string', 'max:120'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after:from'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $employee = $this->employee;
        $team = OrganizationUnit::query()->where('public_id', $validated['unitId'])->firstOrFail();
        $this->authorize('assign', [$employee, $team]);

        $this->finish($this->attempt('action', __('Penugasan sementara dicatat.'), fn () => $employees->addTemporary(
            $this->actorFor(Capability::EmployeeManage, $this->access()->unitTarget($team->id)),
            $employee->id,
            $this->formVersion,
            $team->id,
            $validated['from'],
            $validated['until'],
            $validated['position'] ?? null,
            $validated['reason'],
        )));
    }

    public function endAssignment(EmployeeService $employees): void
    {
        $validated = $this->validate([
            'until' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $employee = $this->employee;
        $this->authorize('update', $employee);

        $assignment = EmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('public_id', (string) $this->assignmentId)
            ->firstOrFail();

        $this->finish($this->attempt('action', __('Penugasan diakhiri.'), fn () => $employees->endAssignment(
            $this->actorFor(Capability::EmployeeManage, $this->access()->employeeTarget($employee)),
            $assignment->id,
            $this->formVersion,
            $validated['until'],
            $validated['reason'],
        )));
    }

    public function rename(EmployeeService $employees): void
    {
        $validated = $this->validate([
            'fullName' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $employee = $this->employee;
        $this->authorize('update', $employee);

        $this->finish($this->attempt('action', __('Nama pegawai diperbarui.'), fn () => $employees->rename(
            $this->actorFor(Capability::EmployeeManage, $this->access()->employeeTarget($employee)),
            $employee->id,
            $this->formVersion,
            $validated['fullName'],
            $validated['reason'],
        )));
    }

    private function finish(bool $succeeded): void
    {
        if ($succeeded) {
            $this->closeForm();
            unset($this->employee, $this->assignments);
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$this->employee->full_name" :description="__('NIK').': '.$this->employee->employee_number">
        <x-slot:actions>
            @can('viewAny', Employee::class)
                <x-ui.button variant="secondary" :href="route('administration.employees')" wire:navigate>{{ __('Kembali ke daftar') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback')

    <dl class="grid gap-4 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-3 dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Nomor induk pegawai') }}</dt>
            <dd class="font-mono text-sm">{{ $this->employee->employee_number }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Akun') }}</dt>
            <dd class="text-sm">{{ $this->employee->user?->email ?? __('Belum terhubung') }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Versi data') }}</dt>
            <dd class="text-sm">{{ $this->employee->lock_version }}</dd>
        </div>
    </dl>

    @if ($this->canManage)
        <div class="flex flex-wrap gap-2">
            <x-ui.button wire:click="openForm('transfer')">{{ __('Pindah home team') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="openForm('temporary')">{{ __('Tambah penugasan sementara') }}</x-ui.button>
            <x-ui.button variant="ghost" wire:click="openForm('rename')">{{ __('Koreksi nama') }}</x-ui.button>
        </div>
    @endif

    @if ($mode === 'transfer' || $mode === 'temporary')
        <x-ui.panel :title="$mode === 'transfer' ? __('Pindah home team') : __('Penugasan sementara')" :description="$mode === 'transfer' ? __('Home team saat ini ditutup pada tanggal mulai dan home team baru dibuka.') : __('Penugasan sementara wajib memiliki tanggal akhir.')">
            <form wire:submit="{{ $mode === 'transfer' ? 'transfer' : 'addTemporary' }}" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="from" type="date" :label="__('Mulai berlaku')" required wire:model.live="from" />
                @if ($mode === 'temporary')
                    <x-ui.input name="until" type="date" :label="__('Berakhir pada (eksklusif)')" required wire:model="until" />
                @endif
                <x-ui.select name="unitId" :label="__('Team tujuan')" required :placeholder="__('Pilih team')" :options="$this->teamOptions" wire:model="unitId" />
                <x-ui.input name="position" :label="__('Jabatan')" wire:model="position" />
                <x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" />
                <div class="flex gap-2 sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Simpan') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="closeForm">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @elseif ($mode === 'end')
        <x-ui.panel :title="__('Akhiri penugasan')" :description="__('Penugasan yang sudah berakhir tidak dapat diubah lagi.')">
            <form wire:submit="endAssignment" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="until" type="date" :label="__('Berakhir pada (eksklusif)')" required wire:model="until" />
                <x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" />
                <div class="flex gap-2 sm:col-span-2">
                    <x-ui.button type="submit" variant="danger">{{ __('Akhiri') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="closeForm">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @elseif ($mode === 'rename')
        <x-ui.panel :title="__('Koreksi nama pegawai')">
            <form wire:submit="rename" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="fullName" :label="__('Nama lengkap')" required wire:model="fullName" />
                <x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" />
                <div class="flex gap-2 sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Simpan') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="closeForm">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.table-shell :caption="__('Riwayat penugasan')" :columns="[__('Jenis'), __('Team'), __('Jabatan'), __('Mulai'), __('Berakhir'), __('Alasan'), __('Aksi')]">
        @foreach ($this->assignments as $assignment)
            <tr wire:key="assignment-{{ $assignment->public_id }}">
                <td class="px-4 py-3">{{ $assignment->assignment_type->label() }}</td>
                <td class="px-4 py-3"><span class="font-mono text-xs">{{ $assignment->unit->code }}</span></td>
                <td class="px-4 py-3">{{ $assignment->position_title ?? '—' }}</td>
                <td class="px-4 py-3">{{ $assignment->effective_from }}</td>
                <td class="px-4 py-3">{{ $assignment->effective_until ?? __('Berjalan') }}</td>
                <td class="px-4 py-3">
                    {{ $assignment->reason ?? '—' }}
                    @if ($assignment->end_reason)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Diakhiri') }}: {{ $assignment->end_reason }} ({{ $assignment->endedBy?->name ?? __('Sistem') }})</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if ($this->canManage && ($assignment->effective_until === null || $assignment->effective_until > $this->today))
                        <x-ui.button variant="ghost" wire:click="openForm('end', '{{ $assignment->public_id }}')">{{ __('Akhiri') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada penugasan')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
