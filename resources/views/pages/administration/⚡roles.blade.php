<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\ScopeKind;
use App\Modules\IdentityAccess\Http\Livewire\AdministrationPage;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Role & scope')] class extends AdministrationPage {
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'role')]
    public string $role = '';

    #[Url(as: 'status')]
    public string $status = 'active';

    #[Url(as: 'cari')]
    public string $search = '';

    public string $grantUser = '';
    public string $grantRole = '';
    public string $grantUnit = '';
    public string $grantFrom = '';
    public string $grantUntil = '';
    public string $grantReason = '';

    #[Locked]
    public ?string $revoking = null;

    #[Locked]
    public int $revokingVersion = 0;

    public string $revokeReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', RoleAssignment::class);
        $this->grantFrom = $this->businessToday();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['role', 'status', 'search'], true)) {
            $this->resetPage();
        }

        if ($property === 'grantRole') {
            $this->grantUnit = '';
        }
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $today = $this->businessToday();
        $now = CarbonImmutable::now();
        $scope = $this->access()->unitScope($this->currentUser(), Capability::RoleView);
        $needle = trim($this->search);

        return RoleAssignment::query()
            ->with(['user', 'unit', 'grantedBy', 'revokedBy'])
            ->when(! $scope->all, fn (Builder $query) => $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds))
            ->when($this->role !== '', fn (Builder $query) => $query->where('role', $this->role))
            ->when($this->status === 'active', fn (Builder $query) => $query->activeAt($today, $now))
            ->when($this->status === 'scheduled', fn (Builder $query) => $query->whereNull('revoked_at')->where('effective_from', '>', $today))
            ->when($this->status === 'ended', fn (Builder $query) => $query->whereNull('revoked_at')->where('effective_until', '<=', $today))
            ->when($this->status === 'revoked', fn (Builder $query) => $query->where('revoked_at', '<=', $now))
            ->when($needle !== '', function (Builder $query) use ($needle): void {
                $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';
                $query->whereHas('user', fn (Builder $user) => $user
                    ->whereRaw('lower(name) like ?', [$pattern])
                    ->orWhereRaw('lower(email) like ?', [$pattern]));
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->currentUser()->can('create', RoleAssignment::class);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function userOptions(): array
    {
        return User::query()
            ->where('status', 'active')
            ->whereKeyNot($this->currentUser()->id)
            ->orderBy('name')
            ->get(['public_id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [$user->public_id => "{$user->name} <{$user->email}>"])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        $role = Role::tryFrom($this->grantRole);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->grantFrom);

        if ($role === null || $role->scopeKind() !== ScopeKind::Organization || $date === false || $date->format('Y-m-d') !== $this->grantFrom) {
            return [];
        }

        $snapshot = app(OrganizationDirectory::class)->snapshot($this->grantFrom);

        return collect($snapshot->all())
            ->filter(fn (UnitNode $unit): bool => in_array($unit->type, $role->allowedUnitTypes(), true))
            ->mapWithKeys(fn (UnitNode $unit): array => [$unit->publicId => $unit->type->label().': '.$snapshot->pathLabel($unit->id)])
            ->all();
    }

    public function grant(RoleAssignmentService $roles): void
    {
        $validated = $this->validate([
            'grantUser' => ['required', 'string', 'size:26'],
            'grantRole' => ['required', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
            'grantUnit' => ['nullable', 'string', 'size:26'],
            'grantFrom' => ['required', 'date_format:Y-m-d'],
            'grantUntil' => ['nullable', 'date_format:Y-m-d', 'after:grantFrom'],
            'grantReason' => ['required', 'string', 'max:2000'],
        ]);

        $recipient = User::query()->where('public_id', $validated['grantUser'])->firstOrFail();
        $this->authorize('create', [RoleAssignment::class, $recipient]);

        $unitId = ($validated['grantUnit'] ?? '') === ''
            ? null
            : (int) OrganizationUnit::query()->where('public_id', $validated['grantUnit'])->valueOrFail('id');

        $granted = $this->attempt('action', __('Role berhasil diberikan.'), fn () => $roles->grant(
            $this->actorFor(Capability::RoleManage),
            $recipient->id,
            Role::from($validated['grantRole']),
            $unitId,
            $validated['grantFrom'],
            ($validated['grantUntil'] ?? '') === '' ? null : $validated['grantUntil'],
            $validated['grantReason'],
        ));

        if ($granted) {
            $this->reset('grantUser', 'grantRole', 'grantUnit', 'grantUntil', 'grantReason');
            unset($this->rows);
        }
    }

    public function startRevoke(string $publicId): void
    {
        $assignment = RoleAssignment::query()->where('public_id', $publicId)->firstOrFail();
        $this->authorize('revoke', $assignment);

        $this->resetErrorBag();
        $this->revoking = $assignment->public_id;
        $this->revokingVersion = $assignment->lock_version;
        $this->revokeReason = '';
    }

    public function cancelRevoke(): void
    {
        $this->reset('revoking', 'revokingVersion', 'revokeReason');
    }

    public function revoke(RoleAssignmentService $roles): void
    {
        $validated = $this->validate(['revokeReason' => ['required', 'string', 'max:2000']]);
        $assignment = RoleAssignment::query()->where('public_id', (string) $this->revoking)->firstOrFail();
        $this->authorize('revoke', $assignment);

        $revoked = $this->attempt('action', __('Role dicabut. Delegasi yang bersumber dari role ini ikut dicabut.'), fn () => $roles->revoke(
            $this->actorFor(Capability::RoleManage),
            $assignment->id,
            $this->revokingVersion,
            $validated['revokeReason'],
        ));

        if ($revoked) {
            $this->cancelRevoke();
            unset($this->rows);
        }
    }
}; ?>

@php
    $today = app(\App\Modules\IdentityAccess\Application\AccessControl::class)->businessToday();
    $now = \Carbon\CarbonImmutable::now();
    $statusLabels = ['active' => [__('Aktif'), 'success'], 'scheduled' => [__('Terjadwal'), 'info'], 'ended' => [__('Berakhir'), 'neutral'], 'revoked' => [__('Dicabut'), 'danger']];
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Role & scope')"
        :description="__('Role menentukan kemampuan; scope organisasi menentukan di mana kemampuan itu berlaku. Tidak ada yang dapat mengubah hak akses akun sendiri.')"
    />

    @include('pages.administration.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter role') }}">
        <x-ui.select name="role" :label="__('Role')" :placeholder="__('Semua role')" :options="collect(Role::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" wire:model.live="role" />
        <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="collect($statusLabels)->map(fn ($label) => $label[0])->all()" wire:model.live="status" />
        <x-ui.input name="search" type="search" :label="__('Cari pengguna')" wire:model.live.debounce.400ms="search" />
    </form>

    <x-ui.table-shell :caption="__('Daftar role assignment')" :columns="[__('Pengguna'), __('Role'), __('Scope'), __('Periode'), __('Diberikan oleh'), __('Status'), __('Aksi')]">
        @foreach ($this->rows as $assignment)
            @php($state = $assignment->statusAt($today, $now))
            <tr wire:key="role-{{ $assignment->public_id }}">
                <td class="px-4 py-3">
                    {{ $assignment->user->name }}
                    <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $assignment->user->email }}</span>
                </td>
                <td class="px-4 py-3">{{ $assignment->role->label() }}</td>
                <td class="px-4 py-3">
                    @if ($assignment->unit)
                        <span class="font-mono text-xs">{{ $assignment->unit->code }}</span>
                    @else
                        {{ $assignment->role->scopeKind() === ScopeKind::Self ? __('Data sendiri') : __('Sistem') }}
                    @endif
                </td>
                <td class="px-4 py-3">{{ $assignment->effective_from }} → {{ $assignment->effective_until ?? __('tanpa batas') }}</td>
                <td class="px-4 py-3">
                    {{ $assignment->grantedBy?->name ?? __('Sistem') }}
                    <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $assignment->reason }}</span>
                </td>
                <td class="px-4 py-3">
                    <x-ui.status-badge :status="$statusLabels[$state][1]">{{ $statusLabels[$state][0] }}</x-ui.status-badge>
                    @if ($assignment->revoke_reason)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $assignment->revoke_reason }} ({{ $assignment->revokedBy?->name ?? __('Sistem') }})</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if ($this->canManage && in_array($state, ['active', 'scheduled'], true) && $assignment->user_id !== auth()->id())
                        <x-ui.button variant="secondary" wire:click="startRevoke('{{ $assignment->public_id }}')">{{ __('Cabut') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada role assignment')" :description="__('Tidak ada data untuk filter tersebut.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($revoking !== null)
        <x-ui.panel :title="__('Cabut role')" :description="__('Akses berakhir saat ini juga. Histori assignment tetap tersimpan.')">
            <form wire:submit="revoke" class="grid gap-4">
                <x-ui.textarea name="revokeReason" :label="__('Alasan')" required wire:model="revokeReason" />
                <div class="flex gap-2">
                    <x-ui.button type="submit" variant="danger">{{ __('Cabut role') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="cancelRevoke">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->canManage)
        <x-ui.panel :title="__('Berikan role')" :description="__('Approver aktual per team mengikuti DEC-002 dan belum diisi otomatis.')">
            <form wire:submit="grant" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="grantUser" :label="__('Pengguna')" required :placeholder="__('Pilih akun aktif')" :options="$this->userOptions" wire:model="grantUser" />
                <x-ui.select name="grantRole" :label="__('Role')" required :placeholder="__('Pilih role')" :options="collect(Role::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" wire:model.live="grantRole" />
                <x-ui.input name="grantFrom" type="date" :label="__('Berlaku mulai')" required wire:model.live="grantFrom" />
                <x-ui.input name="grantUntil" type="date" :label="__('Berakhir pada (opsional, eksklusif)')" wire:model="grantUntil" />
                @if ($this->unitOptions !== [])
                    <x-ui.select name="grantUnit" :label="__('Scope organisasi')" required :placeholder="__('Pilih unit')" :options="$this->unitOptions" wire:model="grantUnit" />
                @endif
                <x-ui.textarea name="grantReason" :label="__('Alasan')" required wire:model="grantReason" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Berikan role') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
