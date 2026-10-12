<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\OrganizationStructureService;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnitVersion;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Struktur organisasi')] class extends GuardedPage {
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'tanggal')]
    public string $asOf = '';

    #[Url(as: 'tipe')]
    public string $type = '';

    #[Url(as: 'cari')]
    public string $search = '';

    public string $newType = 'team';
    public string $newCode = '';
    public string $newName = '';
    public string $newParent = '';
    public string $newFrom = '';
    public string $newReason = '';

    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public int $editingVersion = 0;

    public string $reviseName = '';
    public string $reviseParent = '';
    public string $reviseFrom = '';
    public string $reviseReason = '';
    public string $closeUntil = '';
    public string $closeReason = '';

    public ?string $historyFor = null;

    public function mount(): void
    {
        $this->authorize('viewAny', OrganizationUnit::class);

        if (! $this->isDate($this->asOf)) {
            $this->asOf = $this->businessToday();
        }

        $this->newFrom = $this->businessToday();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['asOf', 'type', 'search'], true)) {
            if (! $this->isDate($this->asOf)) {
                $this->asOf = $this->businessToday();
            }

            $this->resetPage();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->currentUser()->can('create', OrganizationUnit::class);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $snapshot = app(OrganizationDirectory::class)->snapshot($this->asOf);
        $scope = $this->access()->unitScope($this->currentUser(), Capability::OrganizationView);
        $needle = mb_strtolower(trim($this->search));

        $items = collect($snapshot->all())
            ->filter(fn (UnitNode $unit): bool => $scope->coversUnit($unit->id))
            ->filter(fn (UnitNode $unit): bool => $this->type === '' || $unit->type->value === $this->type)
            ->filter(fn (UnitNode $unit): bool => $needle === ''
                || str_contains(mb_strtolower($unit->name), $needle)
                || str_contains(mb_strtolower($unit->code), $needle))
            ->map(fn (UnitNode $unit): array => [
                'unit' => $unit,
                'parent' => $unit->parentId === null ? null : $snapshot->unit($unit->parentId)?->name,
            ])
            ->values();

        $page = $this->getPage();

        return new LengthAwarePaginator($items->forPage($page, self::PER_PAGE)->values(), $items->count(), self::PER_PAGE, $page);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function parentOptions(): array
    {
        return $this->parentOptionsFor($this->newType === UnitType::Department->value ? UnitType::Company : UnitType::Department, $this->newFrom);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function reviseParentOptions(): array
    {
        $unit = $this->editing === null ? null : OrganizationUnit::query()->where('public_id', $this->editing)->first();
        $parentType = $unit?->unit_type->requiredParentType();

        return $parentType === null ? [] : $this->parentOptionsFor($parentType, $this->reviseFrom);
    }

    /**
     * @return Collection<int, OrganizationUnitVersion>
     */
    #[Computed]
    public function history(): Collection
    {
        if ($this->historyFor === null) {
            return collect();
        }

        $unit = OrganizationUnit::query()->where('public_id', $this->historyFor)->firstOrFail();
        $this->authorize('view', $unit);

        return OrganizationUnitVersion::query()
            ->with(['parent', 'createdBy'])
            ->where('organization_unit_id', $unit->id)
            ->orderByDesc('effective_from')
            ->get();
    }

    public function showHistory(string $publicId): void
    {
        $this->historyFor = $this->historyFor === $publicId ? null : $publicId;
        unset($this->history);
    }

    public function createUnit(OrganizationStructureService $structure): void
    {
        $validated = $this->validate([
            'newType' => ['required', 'in:department,team'],
            'newCode' => ['required', 'string', 'max:40'],
            'newName' => ['required', 'string', 'max:120'],
            'newParent' => ['required', 'string', 'size:26'],
            'newFrom' => ['required', 'date_format:Y-m-d'],
            'newReason' => ['required', 'string', 'max:2000'],
        ]);

        $parent = OrganizationUnit::query()->where('public_id', $validated['newParent'])->firstOrFail();
        $this->authorize('create', [OrganizationUnit::class, $parent]);

        $created = $this->attempt('action', __('Unit berhasil dibuat.'), fn () => $structure->createUnit(
            $this->actorFor(Capability::OrganizationManage, $this->access()->unitTarget($parent->id)),
            UnitType::from($validated['newType']),
            $validated['newCode'],
            $validated['newName'],
            $parent->id,
            $validated['newFrom'],
            $validated['newReason'],
        ));

        if ($created) {
            $this->reset('newCode', 'newName', 'newParent', 'newReason');
            unset($this->rows);
        }
    }

    public function startEdit(string $publicId): void
    {
        $unit = OrganizationUnit::query()->where('public_id', $publicId)->firstOrFail();
        $this->authorize('update', $unit);

        $current = $unit->versions()->reorder()->orderByDesc('effective_from')->firstOrFail();

        $this->editing = $unit->public_id;
        $this->editingVersion = $unit->lock_version;
        $this->reviseName = $current->name;
        $this->reviseParent = $current->parent?->public_id ?? '';
        $this->reviseFrom = $this->businessToday();
        $this->reviseReason = '';
        $this->closeUntil = $this->businessToday();
        $this->closeReason = '';
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editingVersion', 'reviseName', 'reviseParent', 'reviseFrom', 'reviseReason', 'closeUntil', 'closeReason');
    }

    public function reviseUnit(OrganizationStructureService $structure): void
    {
        $validated = $this->validate([
            'reviseName' => ['required', 'string', 'max:120'],
            'reviseParent' => ['nullable', 'string', 'size:26'],
            'reviseFrom' => ['required', 'date_format:Y-m-d'],
            'reviseReason' => ['required', 'string', 'max:2000'],
        ]);

        $unit = $this->editingUnit();
        $this->authorize('update', $unit);

        $parent = ($validated['reviseParent'] ?? '') === ''
            ? null
            : OrganizationUnit::query()->where('public_id', $validated['reviseParent'])->firstOrFail();

        if ($parent !== null) {
            $this->authorize('create', [OrganizationUnit::class, $parent]);
        }

        $revised = $this->attempt('action', __('Perubahan unit disimpan sebagai versi baru.'), fn () => $structure->reviseUnit(
            $this->actorFor(Capability::OrganizationManage, $this->access()->unitTarget($unit->id)),
            $unit->id,
            $this->editingVersion,
            $validated['reviseName'],
            $parent?->id,
            $validated['reviseFrom'],
            $validated['reviseReason'],
        ));

        if ($revised) {
            $this->cancelEdit();
            unset($this->rows, $this->history);
        }
    }

    public function closeUnit(OrganizationStructureService $structure): void
    {
        $validated = $this->validate([
            'closeUntil' => ['required', 'date_format:Y-m-d'],
            'closeReason' => ['required', 'string', 'max:2000'],
        ]);

        $unit = $this->editingUnit();
        $this->authorize('update', $unit);

        $closed = $this->attempt('action', __('Unit ditutup.'), fn () => $structure->closeUnit(
            $this->actorFor(Capability::OrganizationManage, $this->access()->unitTarget($unit->id)),
            $unit->id,
            $this->editingVersion,
            $validated['closeUntil'],
            $validated['closeReason'],
        ));

        if ($closed) {
            $this->cancelEdit();
            unset($this->rows, $this->history);
        }
    }

    private function editingUnit(): OrganizationUnit
    {
        abort_if($this->editing === null, 404);

        return OrganizationUnit::query()->where('public_id', $this->editing)->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function parentOptionsFor(UnitType $type, string $date): array
    {
        if (! $this->isDate($date)) {
            return [];
        }

        $user = $this->currentUser();

        return collect(app(OrganizationDirectory::class)->snapshot($date)->ofType($type))
            ->filter(fn (UnitNode $unit): bool => $this->access()->allows($user, Capability::OrganizationManage, $this->access()->unitTarget($unit->id)))
            ->mapWithKeys(fn (UnitNode $unit): array => [$unit->publicId => "{$unit->name} ({$unit->code})"])
            ->all();
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Struktur organisasi')"
        :description="__('Company → Group/Department → Team dengan tanggal berlaku. Perubahan nama atau induk membuat versi baru tanpa mengubah histori.')"
    />

    @include('pages.administration.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter unit') }}">
        <x-ui.input name="asOf" type="date" :label="__('Per tanggal')" wire:model.live="asOf" />
        <x-ui.select name="type" :label="__('Tipe unit')" :placeholder="__('Semua tipe')" :options="collect(UnitType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" wire:model.live="type" />
        <x-ui.input name="search" type="search" :label="__('Cari kode atau nama')" wire:model.live.debounce.400ms="search" />
    </form>

    <x-ui.table-shell :caption="__('Daftar unit organisasi')" :columns="[__('Kode'), __('Nama'), __('Tipe'), __('Induk'), __('Aksi')]">
        @foreach ($this->rows as $row)
            <tr wire:key="unit-{{ $row['unit']->publicId }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $row['unit']->code }}</td>
                <td class="px-4 py-3">{{ $row['unit']->name }}</td>
                <td class="px-4 py-3">{{ $row['unit']->type->label() }}</td>
                <td class="px-4 py-3">{{ $row['parent'] ?? '—' }}</td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="ghost" wire:click="showHistory('{{ $row['unit']->publicId }}')">{{ __('Riwayat') }}</x-ui.button>
                        @if ($this->canManage && $row['unit']->type !== UnitType::Company)
                            <x-ui.button variant="secondary" wire:click="startEdit('{{ $row['unit']->publicId }}')">{{ __('Ubah/Tutup') }}</x-ui.button>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada unit')" :description="__('Tidak ada unit pada tanggal dan filter tersebut, atau unit berada di luar scope Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($historyFor !== null)
        <x-ui.table-shell :caption="__('Riwayat versi unit')" :columns="[__('Berlaku mulai'), __('Berakhir'), __('Nama'), __('Induk'), __('Alasan'), __('Dicatat oleh')]">
            @foreach ($this->history as $version)
                <tr wire:key="version-{{ $version->id }}">
                    <td class="px-4 py-3">{{ $version->effective_from }}</td>
                    <td class="px-4 py-3">{{ $version->effective_until ?? __('Berjalan') }}</td>
                    <td class="px-4 py-3">{{ $version->name }}</td>
                    <td class="px-4 py-3">{{ $version->parent?->code ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $version->reason ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $version->createdBy?->name ?? __('Sistem') }}</td>
                </tr>
            @endforeach
        </x-ui.table-shell>
    @endif

    @if ($editing !== null)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.panel :title="__('Ubah nama atau induk')" :description="__('Versi lama ditutup pada tanggal berlaku, lalu versi baru dibuat.')">
                <form wire:submit="reviseUnit" class="grid gap-4">
                    <x-ui.input name="reviseName" :label="__('Nama unit')" required wire:model="reviseName" />
                    <x-ui.select name="reviseParent" :label="__('Unit induk')" :options="$this->reviseParentOptions" wire:model="reviseParent" />
                    <x-ui.input name="reviseFrom" type="date" :label="__('Berlaku mulai')" required wire:model.live="reviseFrom" />
                    <x-ui.textarea name="reviseReason" :label="__('Alasan')" required wire:model="reviseReason" />
                    <div class="flex gap-2">
                        <x-ui.button type="submit">{{ __('Simpan versi baru') }}</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="cancelEdit">{{ __('Batal') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.panel>

            <x-ui.panel :title="__('Tutup unit')" :description="__('Unit hanya dapat ditutup bila tidak ada unit turunan dan penugasan pegawai yang masih aktif.')">
                <form wire:submit="closeUnit" class="grid gap-4">
                    <x-ui.input name="closeUntil" type="date" :label="__('Berakhir pada (eksklusif)')" required wire:model="closeUntil" />
                    <x-ui.textarea name="closeReason" :label="__('Alasan')" required wire:model="closeReason" />
                    <div>
                        <x-ui.button type="submit" variant="danger">{{ __('Tutup unit') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.panel>
        </div>
    @endif

    @if ($this->canManage)
        <x-ui.panel :title="__('Tambah unit')" :description="__('Department berada di bawah company; team berada di bawah department.')">
            <form wire:submit="createUnit" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="newType" :label="__('Tipe unit')" required :options="['department' => UnitType::Department->label(), 'team' => UnitType::Team->label()]" wire:model.live="newType" />
                <x-ui.select name="newParent" :label="__('Unit induk')" required :placeholder="__('Pilih unit induk')" :options="$this->parentOptions" wire:model="newParent" />
                <x-ui.input name="newCode" :label="__('Kode unit')" required :hint="__('Huruf kapital, angka, dan tanda hubung. Contoh: PROD-WOVEN')" wire:model="newCode" />
                <x-ui.input name="newName" :label="__('Nama unit')" required wire:model="newName" />
                <x-ui.input name="newFrom" type="date" :label="__('Berlaku mulai')" required wire:model.live="newFrom" />
                <x-ui.textarea name="newReason" :label="__('Alasan')" required wire:model="newReason" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Tambah unit') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
