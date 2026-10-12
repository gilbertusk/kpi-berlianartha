<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\DelegationService;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Delegasi')] class extends GuardedPage {
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'status')]
    public string $status = 'active';

    public string $source = '';
    public string $delegate = '';
    public string $unit = '';
    public string $from = '';
    public string $until = '';
    public string $reason = '';

    #[Locked]
    public ?string $revoking = null;

    #[Locked]
    public int $revokingVersion = 0;

    public string $revokeReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Delegation::class);
        $this->from = $this->businessToday();
    }

    public function updated(string $property): void
    {
        if ($property === 'status') {
            $this->resetPage();
        }

        if ($property === 'source') {
            $this->unit = '';
        }
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $user = $this->currentUser();
        $today = $this->businessToday();
        $now = CarbonImmutable::now();
        $view = $this->access()->unitScope($user, Capability::DelegationView);
        $manage = $this->access()->unitScope($user, Capability::DelegationManage);
        $all = $view->all || $manage->all;
        $unitIds = array_values(array_unique([...$view->unitIds, ...$manage->unitIds]));

        return Delegation::query()
            ->with(['delegator', 'delegate', 'unit', 'grantedBy', 'revokedBy'])
            ->when(! $all, fn (Builder $query) => $query->where(fn (Builder $visible) => $visible
                ->where('delegator_user_id', $user->id)
                ->orWhere('delegate_user_id', $user->id)
                ->orWhereIn('organization_unit_id', $unitIds === [] ? [0] : $unitIds)))
            ->when($this->status === 'active', fn (Builder $query) => $query->activeAt($today, $now))
            ->when($this->status === 'scheduled', fn (Builder $query) => $query->whereNull('revoked_at')->where('effective_from', '>', $today))
            ->when($this->status === 'expired', fn (Builder $query) => $query->whereNull('revoked_at')->where('effective_until', '<=', $today))
            ->when($this->status === 'revoked', fn (Builder $query) => $query->where('revoked_at', '<=', $now))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->currentUser()->can('create', Delegation::class);
    }

    /**
     * Role assignments the viewer may delegate: their own approval-chain
     * roles, plus (for HR Performance) those inside its manage scope.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function sourceOptions(): array
    {
        $user = $this->currentUser();
        $today = $this->businessToday();
        $now = CarbonImmutable::now();

        return RoleAssignment::query()
            ->with(['user', 'unit'])
            ->whereIn('role', array_map(fn (Role $role): string => $role->value, array_filter(Role::cases(), fn (Role $role): bool => $role->isDelegable())))
            ->activeAt($today, $now)
            ->orderBy('user_id')
            ->get()
            ->filter(fn (RoleAssignment $assignment): bool => $user->can('create', [Delegation::class, $assignment]))
            ->mapWithKeys(fn (RoleAssignment $assignment): array => [
                $assignment->public_id => "{$assignment->user->name} — {$assignment->role->label()} ({$assignment->unit?->code})",
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function delegateOptions(): array
    {
        $source = $this->sourceAssignment();

        return User::query()
            ->where('status', 'active')
            ->whereKeyNot($this->currentUser()->id)
            ->when($source !== null, fn (Builder $query) => $query->whereKeyNot($source->user_id))
            ->orderBy('name')
            ->get(['public_id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [$user->public_id => "{$user->name} <{$user->email}>"])
            ->all();
    }

    /**
     * Units inside the source assignment's scope on the start date.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        $source = $this->sourceAssignment();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->from);

        if ($source === null || $source->organization_unit_id === null || $date === false || $date->format('Y-m-d') !== $this->from) {
            return [];
        }

        $snapshot = app(OrganizationDirectory::class)->snapshot($this->from);
        $root = $snapshot->unit($source->organization_unit_id);

        if ($root === null) {
            return [];
        }

        $ids = $root->type === UnitType::Company ? array_map(fn ($unit) => $unit->id, $snapshot->all()) : $snapshot->subtreeIds($root->id);

        return collect($ids)
            ->map(fn (int $id) => $snapshot->unit($id))
            ->filter()
            ->mapWithKeys(fn ($unit): array => [$unit->publicId => $unit->type->label().': '.$snapshot->pathLabel($unit->id)])
            ->all();
    }

    public function create(DelegationService $delegations): void
    {
        $validated = $this->validate([
            'source' => ['required', 'string', 'size:26'],
            'delegate' => ['required', 'string', 'size:26'],
            'unit' => ['required', 'string', 'size:26'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after:from'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $source = RoleAssignment::query()->where('public_id', $validated['source'])->firstOrFail();
        $this->authorize('create', [Delegation::class, $source]);

        $delegate = User::query()->where('public_id', $validated['delegate'])->firstOrFail();
        $unit = OrganizationUnit::query()->where('public_id', $validated['unit'])->firstOrFail();
        $user = $this->currentUser();

        $actor = $source->user_id === $user->id
            ? $this->selfActor()
            : $this->actorFor(Capability::DelegationManage, $this->access()->unitTarget((int) $source->organization_unit_id));

        $created = $this->attempt('action', __('Delegasi dibuat dan akan berakhir otomatis pada tanggal akhir.'), fn () => $delegations->create(
            $actor,
            $source->id,
            $delegate->id,
            $unit->id,
            $validated['from'],
            $validated['until'],
            $validated['reason'],
        ));

        if ($created) {
            $this->reset('source', 'delegate', 'unit', 'until', 'reason');
            unset($this->rows);
        }
    }

    public function startRevoke(string $publicId): void
    {
        $delegation = Delegation::query()->where('public_id', $publicId)->firstOrFail();
        $this->authorize('revoke', $delegation);

        $this->resetErrorBag();
        $this->revoking = $delegation->public_id;
        $this->revokingVersion = $delegation->lock_version;
        $this->revokeReason = '';
    }

    public function cancelRevoke(): void
    {
        $this->reset('revoking', 'revokingVersion', 'revokeReason');
    }

    public function revoke(DelegationService $delegations): void
    {
        $validated = $this->validate(['revokeReason' => ['required', 'string', 'max:2000']]);
        $delegation = Delegation::query()->where('public_id', (string) $this->revoking)->firstOrFail();
        $this->authorize('revoke', $delegation);

        $actor = $delegation->delegator_user_id === $this->currentUser()->id
            ? $this->selfActor()
            : $this->actorFor(Capability::DelegationManage, $this->access()->unitTarget($delegation->organization_unit_id));

        $revoked = $this->attempt('action', __('Delegasi dicabut.'), fn () => $delegations->revoke(
            $actor,
            $delegation->id,
            $this->revokingVersion,
            $validated['revokeReason'],
        ));

        if ($revoked) {
            $this->cancelRevoke();
            unset($this->rows);
        }
    }

    private function sourceAssignment(): ?RoleAssignment
    {
        if ($this->source === '') {
            return null;
        }

        $assignment = RoleAssignment::query()->where('public_id', $this->source)->first();

        return $assignment !== null && $this->currentUser()->can('create', [Delegation::class, $assignment]) ? $assignment : null;
    }
}; ?>

@php
    $today = app(\App\Modules\IdentityAccess\Application\AccessControl::class)->businessToday();
    $now = \Carbon\CarbonImmutable::now();
    $statusLabels = ['active' => [__('Aktif'), 'success'], 'scheduled' => [__('Terjadwal'), 'info'], 'expired' => [__('Kedaluwarsa'), 'neutral'], 'revoked' => [__('Dicabut'), 'danger']];
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Delegasi')"
        :description="__('Delegasi wajib memiliki alasan dan tanggal akhir, tidak boleh melebihi scope pemberi, dan tidak membuka self-approval.')"
    />

    @include('pages.administration.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter delegasi') }}">
        <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="collect($statusLabels)->map(fn ($label) => $label[0])->all()" wire:model.live="status" />
    </form>

    <x-ui.table-shell :caption="__('Daftar delegasi')" :columns="[__('Pemberi'), __('Penerima'), __('Role & scope'), __('Periode'), __('Alasan'), __('Status'), __('Aksi')]">
        @foreach ($this->rows as $delegation)
            @php($state = $delegation->statusAt($today, $now))
            <tr wire:key="delegation-{{ $delegation->public_id }}">
                <td class="px-4 py-3">{{ $delegation->delegator->name }}</td>
                <td class="px-4 py-3">{{ $delegation->delegate->name }}</td>
                <td class="px-4 py-3">{{ $delegation->role->label() }} <span class="font-mono text-xs">({{ $delegation->unit->code }})</span></td>
                <td class="px-4 py-3">{{ $delegation->effective_from }} → {{ $delegation->effective_until }}</td>
                <td class="px-4 py-3">
                    {{ $delegation->reason }}
                    <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Diizinkan oleh') }}: {{ $delegation->grantedBy?->name ?? __('Sistem') }}</span>
                </td>
                <td class="px-4 py-3">
                    <x-ui.status-badge :status="$statusLabels[$state][1]">{{ $statusLabels[$state][0] }}</x-ui.status-badge>
                    @if ($delegation->revoke_reason)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $delegation->revoke_reason }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if (in_array($state, ['active', 'scheduled'], true) && auth()->user()->can('revoke', $delegation))
                        <x-ui.button variant="secondary" wire:click="startRevoke('{{ $delegation->public_id }}')">{{ __('Cabut') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada delegasi')" :description="__('Tidak ada delegasi untuk filter tersebut dalam scope Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($revoking !== null)
        <x-ui.panel :title="__('Cabut delegasi')">
            <form wire:submit="revoke" class="grid gap-4">
                <x-ui.textarea name="revokeReason" :label="__('Alasan')" required wire:model="revokeReason" />
                <div class="flex gap-2">
                    <x-ui.button type="submit" variant="danger">{{ __('Cabut delegasi') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="cancelRevoke">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->canCreate)
        <x-ui.panel :title="__('Buat delegasi')" :description="__('Penerima memperoleh akses baca dan kewenangan approval dalam scope yang dipilih. Pengelolaan data master tidak ikut didelegasikan.')">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="source" :label="__('Role pemberi delegasi')" required :placeholder="__('Pilih role')" :options="$this->sourceOptions" wire:model.live="source" />
                <x-ui.select name="delegate" :label="__('Penerima delegasi')" required :placeholder="__('Pilih akun aktif')" :options="$this->delegateOptions" wire:model="delegate" />
                <x-ui.input name="from" type="date" :label="__('Mulai')" required wire:model.live="from" />
                <x-ui.input name="until" type="date" :label="__('Berakhir pada (eksklusif)')" required wire:model="until" />
                <x-ui.select name="unit" :label="__('Scope delegasi')" required :placeholder="__('Pilih unit')" :options="$this->unitOptions" wire:model="unit" />
                <x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Buat delegasi') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
