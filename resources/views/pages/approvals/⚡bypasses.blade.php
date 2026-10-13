<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Workflow\Application\BypassService;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ApprovalStageBypass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Bypass tahap review')] class extends GuardedPage {
    public string $unit = '';

    public string $signoffReference = '';

    public string $reason = '';

    public string $effectiveFrom = '';

    public string $effectiveUntil = '';

    #[Locked]
    public string $revokeId = '';

    public string $revokeReason = '';

    /**
     * @var array<string, int>
     */
    #[Locked]
    public array $locks = [];

    public function mount(): void
    {
        Gate::authorize('approvalBypasses.manage');
        $this->effectiveFrom = $this->businessToday();
        $this->captureLocks();
    }

    /**
     * Teams inside the scope in which this account manages approval routes.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        $scope = $this->access()->unitScope($this->currentUser(), Capability::ApprovalRouteManage);

        return OrganizationUnit::query()
            ->where('unit_type', UnitType::Team->value)
            ->orderBy('code')
            ->get()
            ->filter(fn (OrganizationUnit $unit): bool => $scope->coversUnit($unit->id))
            ->mapWithKeys(fn (OrganizationUnit $unit): array => [$unit->code => $unit->code])
            ->all();
    }

    /**
     * @return Collection<int, ApprovalStageBypass>
     */
    #[Computed]
    public function bypasses(): Collection
    {
        $scope = $this->access()->unitScope($this->currentUser(), Capability::ApprovalRouteManage);

        return ApprovalStageBypass::query()
            ->with(['unit', 'createdBy'])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(fn (ApprovalStageBypass $bypass): bool => $scope->coversUnit($bypass->organization_unit_id))
            ->values();
    }

    public function register(BypassService $bypasses): void
    {
        Gate::authorize('approvalBypasses.manage');
        abort_unless(array_key_exists($this->unit, $this->unitOptions), 422);

        $unit = OrganizationUnit::query()->where('code', $this->unit)->firstOrFail();

        $done = $this->attempt('action', __('Bypass tahap review dicatat.'), function () use ($bypasses, $unit): void {
            $bypasses->register(
                $this->actorFor(Capability::ApprovalRouteManage, $this->access()->unitTarget($unit->id)),
                $this->currentUser(),
                $unit->id,
                $this->signoffReference,
                $this->reason,
                $this->effectiveFrom,
                $this->effectiveUntil,
            );
        });

        if ($done) {
            $this->reset('unit', 'signoffReference', 'reason', 'effectiveUntil');
            unset($this->bypasses);
            $this->captureLocks();
        }
    }

    public function startRevoke(string $bypassId): void
    {
        Gate::authorize('approvalBypasses.manage');
        abort_unless($this->bypasses->contains(fn (ApprovalStageBypass $bypass): bool => $bypass->public_id === $bypassId), 404);
        $this->revokeId = $bypassId;
        $this->reset('revokeReason');
    }

    public function revoke(BypassService $bypasses): void
    {
        Gate::authorize('approvalBypasses.manage');

        $bypass = $this->bypasses->first(fn (ApprovalStageBypass $entry): bool => $entry->public_id === $this->revokeId);
        abort_if($bypass === null, 404);

        $done = $this->attempt('action', __('Bypass dicabut.'), function () use ($bypasses, $bypass): void {
            $bypasses->revoke(
                $this->actorFor(Capability::ApprovalRouteManage, $this->access()->unitTarget($bypass->organization_unit_id)),
                $this->currentUser(),
                $bypass->id,
                $this->locks[$bypass->public_id] ?? 0,
                $this->revokeReason,
            );
        });

        unset($this->bypasses);
        $this->captureLocks();

        if ($done) {
            $this->reset('revokeId', 'revokeReason');
        }
    }

    private function captureLocks(): void
    {
        $this->locks = [];

        foreach ($this->bypasses as $bypass) {
            $this->locks[$bypass->public_id] = $bypass->lock_version;
        }
    }
}; ?>

@php
    $today = $this->businessToday();
    $statusLabels = ['active' => __('Berlaku'), 'scheduled' => __('Terjadwal'), 'expired' => __('Berakhir'), 'revoked' => __('Dicabut')];
    $statusTones = ['active' => 'success', 'scheduled' => 'info', 'expired' => 'neutral', 'revoked' => 'danger'];
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Bypass tahap review')"
        :description="__('Izin melewati tahap review untuk team yang tidak memiliki reviewer. Bypass hanya berlaku bila snapshot periode menunjukkan tahap review team itu kosong, dan selalu merujuk persetujuan tertulisnya. Sistem tidak pernah melewati tahap tanpa catatan ini.')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('approvals.index')" wire:navigate>{{ __('Kembali ke approval') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <x-ui.panel :title="__('Catat bypass yang sudah disetujui')" :description="__('Isi dan tanggal bypass tidak dapat diubah setelah dicatat; bypass hanya dapat dicabut.')">
        <form wire:submit="register" class="grid gap-4 sm:grid-cols-2">
            <x-ui.select name="unit" :label="__('Team')" :placeholder="__('Pilih team')" :options="$this->unitOptions" required wire:model="unit" />
            <x-ui.input name="signoffReference" :label="__('Referensi sign-off')" :hint="__('Nomor memo, notulen, atau dokumen persetujuan.')" required wire:model="signoffReference" />
            <x-ui.input name="effectiveFrom" type="date" :label="__('Berlaku mulai')" required wire:model="effectiveFrom" />
            <x-ui.input name="effectiveUntil" type="date" :label="__('Berlaku sampai (tidak termasuk; opsional)')" wire:model="effectiveUntil" />
            <div class="sm:col-span-2"><x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" /></div>
            <div class="sm:col-span-2"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="register">{{ __('Catat bypass') }}</x-ui.button></div>
        </form>
    </x-ui.panel>

    <x-ui.table-shell :caption="__('Bypass tahap review')" :columns="[__('Team'), __('Referensi sign-off'), __('Berlaku'), __('Status'), __('Dicatat oleh'), __('Alasan'), __('Aksi')]">
        @foreach ($this->bypasses as $bypass)
            @php($status = $bypass->statusOn($today))
            <tr wire:key="bypass-{{ $bypass->public_id }}">
                <th scope="row" class="px-4 py-3 text-start font-mono text-xs font-medium">{{ $bypass->unit->code }}</th>
                <td class="px-4 py-3">{{ $bypass->signoff_reference }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $bypass->effective_from }} — {{ $bypass->effective_until ?? __('seterusnya') }}</td>
                <td class="px-4 py-3"><x-ui.status-badge :status="$statusTones[$status]">{{ $statusLabels[$status] }}</x-ui.status-badge></td>
                <td class="px-4 py-3">{{ $bypass->createdBy->name }}</td>
                <td class="px-4 py-3">{{ $bypass->reason }}@if ($bypass->revoke_reason) <span class="block text-xs">{{ __('Dicabut: :reason', ['reason' => $bypass->revoke_reason]) }}</span>@endif</td>
                <td class="px-4 py-3">
                    @if ($bypass->revoked_at === null)
                        <x-ui.button variant="secondary" wire:click="startRevoke('{{ $bypass->public_id }}')">{{ __('Cabut') }}<span class="sr-only"> {{ $bypass->unit->code }}</span></x-ui.button>
                    @else
                        —
                    @endif
                </td>
            </tr>

            @if ($revokeId === $bypass->public_id)
                <tr wire:key="bypass-revoke-{{ $bypass->public_id }}">
                    <td colspan="7" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                        <form wire:submit="revoke" class="grid gap-4">
                            <x-ui.textarea name="revokeReason" :label="__('Alasan pencabutan')" required wire:model="revokeReason" />
                            <div><x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="revoke">{{ __('Cabut bypass') }}</x-ui.button></div>
                        </form>
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada bypass')" :description="__('Tahap review tanpa approver tetap menunggu sampai approver dikonfigurasi atau bypass yang disetujui dicatat di sini.')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
