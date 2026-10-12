<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Application\KpiCatalogQuery;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Kamus KPI')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'asal')]
    public string $origin = '';

    #[Url(as: 'unit')]
    public string $unit = '';

    public string $newCode = '';
    public string $newName = '';
    public string $newUnit = '';
    public string $newReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', KpiDefinition::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'origin', 'unit'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $unitId = $this->unit === '' ? null : OrganizationUnit::query()->where('public_id', $this->unit)->value('id');

        return app(KpiCatalogQuery::class)->definitions(
            $this->access()->unitScope($this->currentUser(), Capability::KpiDefinitionView),
            $this->search,
            LifecycleStatus::tryFrom($this->status)?->value,
            in_array($this->origin, [KpiDefinition::ORIGIN_AUTHORED, KpiDefinition::ORIGIN_PRD_CANDIDATE], true) ? $this->origin : null,
            $unitId === null ? null : (int) $unitId,
            $this->getPage(),
        );
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        return $this->unitsWhere(Capability::KpiDefinitionView);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function manageableUnits(): array
    {
        return $this->unitsWhere(Capability::KpiDefinitionManage);
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->currentUser()->can('create', KpiDefinition::class);
    }

    public function create(KpiDefinitionService $definitions): void
    {
        $validated = $this->validate([
            'newCode' => ['required', 'string', 'max:60'],
            'newName' => ['required', 'string', 'max:160'],
            'newUnit' => ['required', 'string', 'size:26'],
            'newReason' => ['nullable', 'string', 'max:2000'],
        ]);

        $unit = OrganizationUnit::query()->where('public_id', $validated['newUnit'])->firstOrFail();
        $this->authorize('create', [KpiDefinition::class, $unit]);

        $version = null;

        $created = $this->attempt('action', __('Draft KPI dibuat.'), function () use ($definitions, $validated, $unit, &$version): void {
            $version = $definitions->createDraft(
                $this->actorFor(Capability::KpiDefinitionManage, $this->access()->unitTarget($unit->id)),
                $validated['newCode'],
                $unit->id,
                ['name' => $validated['newName']],
                KpiDefinition::ORIGIN_AUTHORED,
                $validated['newReason'] ?? null,
            );
        });

        if ($created && $version !== null) {
            $this->redirectRoute('kpi.definitions.show', ['definition' => $version->definition->public_id], navigate: true);
        }
    }

    /**
     * @return array<string, string>
     */
    private function unitsWhere(Capability $capability): array
    {
        $snapshot = app(OrganizationDirectory::class)->snapshot($this->businessToday());
        $scope = $this->access()->unitScope($this->currentUser(), $capability);

        return collect($snapshot->all())
            ->filter(fn (UnitNode $unit): bool => $scope->coversUnit($unit->id))
            ->mapWithKeys(fn (UnitNode $unit): array => [$unit->publicId => $unit->type->label().': '.$snapshot->pathLabel($unit->id)])
            ->all();
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Kamus KPI')"
        :description="__('Definisi KPI berversi. Versi published tidak dapat diubah; perubahan selalu membuat versi baru dengan alasan dan tanggal berlaku.')"
    />

    @include('pages.kpi.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-4" role="search" aria-label="{{ __('Filter KPI') }}">
        <x-ui.input name="search" type="search" :label="__('Cari kode atau nama')" wire:model.live.debounce.400ms="search" />
        <x-ui.select name="status" :label="__('Status versi terakhir')" :placeholder="__('Semua status')" :options="collect(LifecycleStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" wire:model.live="status" />
        <x-ui.select name="origin" :label="__('Asal')" :placeholder="__('Semua asal')" :options="['authored' => __('Dibuat HR'), 'prd_candidate' => __('Kandidat PRD')]" wire:model.live="origin" />
        <x-ui.select name="unit" :label="__('Unit pemilik')" :placeholder="__('Semua unit dalam scope')" :options="$this->unitOptions" wire:model.live="unit" />
    </form>

    <x-ui.table-shell :caption="__('Daftar definisi KPI')" :columns="[__('Kode'), __('Nama'), __('Unit pemilik'), __('Versi terakhir'), __('Asal'), __('Detail')]">
        @foreach ($this->rows as $definition)
            <tr wire:key="kpi-{{ $definition->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $definition->code }}</td>
                <td class="px-4 py-3">{{ $definition->getAttribute('latest_name') }}</td>
                <td class="px-4 py-3">{{ $definition->ownerUnit->code }}</td>
                <td class="px-4 py-3">
                    v{{ $definition->getAttribute('latest_version_number') }}
                    @include('pages.kpi.partials.status-badge', ['status' => $definition->getAttribute('latest_status')])
                </td>
                <td class="px-4 py-3">
                    @if ($definition->isCandidate())
                        <x-ui.status-badge status="warning">{{ __('Kandidat PRD') }}</x-ui.status-badge>
                    @else
                        {{ __('Dibuat HR') }}
                    @endif
                </td>
                <td class="px-4 py-3">
                    <a href="{{ route('kpi.definitions.show', $definition) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Buka') }}<span class="sr-only"> {{ $definition->code }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada KPI')" :description="__('Tidak ada definisi KPI untuk filter tersebut dalam scope Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($this->canCreate)
        <x-ui.panel :title="__('Buat draft KPI')" :description="__('Draft dapat dilengkapi bertahap. Publish memerlukan semua field wajib, strategi skor, dan referensi sign-off.')">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="newCode" :label="__('Kode KPI')" required :hint="__('Huruf kapital, angka, titik, garis bawah, tanda hubung.')" wire:model="newCode" />
                <x-ui.input name="newName" :label="__('Nama KPI')" required wire:model="newName" />
                <x-ui.select name="newUnit" :label="__('Unit pemilik')" required :placeholder="__('Pilih unit')" :options="$this->manageableUnits" wire:model="newUnit" />
                <x-ui.textarea name="newReason" :label="__('Catatan/alasan')" wire:model="newReason" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Buat draft') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
