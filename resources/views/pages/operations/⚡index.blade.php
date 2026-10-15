<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Operations\Application\OperationalAccess;
use App\Modules\Operations\Application\OperationalQuery;
use App\Modules\Operations\Application\OperationalRecordService;
use App\Modules\Operations\Application\RecordTypeRegistry;
use App\Modules\Operations\Domain\FieldSpec;
use App\Modules\Operations\Domain\RecordType;
use App\Modules\Operations\Infrastructure\Persistence\Models\OperationalRecord;
use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Catatan operasional')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'jenis')]
    public string $type = '';

    #[Url(as: 'team')]
    public string $unit = '';

    public bool $showVoided = false;

    public string $recordUnit = '';

    public string $occurredOn = '';

    public string $kpiCode = '';

    /** @var array<string, string> */
    public array $values = [];

    #[Locked]
    public string $voidId = '';

    /** Lock version of the record whose void form is open. */
    #[Locked]
    public int $voidLock = 0;

    public string $voidReason = '';

    public function mount(): void
    {
        Gate::authorize('operations.view');
        $this->occurredOn = $this->businessToday();
        $this->type = $this->type !== '' && $this->definition() !== null ? $this->type : ($this->types[0]?->key() ?? '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'unit', 'showVoided'], true)) {
            Gate::authorize('operations.view');
            $this->resetPage('halaman');
            $this->reset('values', 'voidId', 'voidReason');
            unset($this->records);
        }
    }

    /**
     * Record types this account may read or keep.
     *
     * @return list<RecordType>
     */
    #[Computed]
    public function types(): array
    {
        $access = app(OperationalAccess::class);
        $manages = ! $access->manageScope($this->currentUser())->isEmpty();

        return array_values(array_filter(
            app(RecordTypeRegistry::class)->all(),
            fn (RecordType $type): bool => $manages || ! $access->readScope($this->currentUser(), $type)->isEmpty(),
        ));
    }

    protected function definition(): ?RecordType
    {
        foreach ($this->types as $candidate) {
            if ($candidate->key() === $this->type) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Units whose records this account keeps.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function manageableUnits(): array
    {
        $scope = app(OperationalAccess::class)->manageScope($this->currentUser());
        $options = [];

        foreach (app(OrganizationDirectory::class)->snapshot($this->businessToday())->all() as $node) {
            if ($scope->coversUnit($node->id)) {
                $options[$node->code] = "{$node->code} — {$node->name}";
            }
        }

        return $options;
    }

    /**
     * @return LengthAwarePaginator<int, OperationalRecord>|null
     */
    #[Computed]
    public function records(): ?LengthAwarePaginator
    {
        $type = $this->definition();

        if ($type === null) {
            return null;
        }

        $unitIds = null;

        if ($this->unit !== '') {
            $node = collect(app(OrganizationDirectory::class)->snapshot($this->businessToday())->all())->firstWhere('code', strtoupper($this->unit));
            $unitIds = $node === null ? [0] : app(OrganizationDirectory::class)->snapshot($this->businessToday())->subtreeIds($node->id);
        }

        return app(OperationalQuery::class)->page($this->currentUser(), $type, $unitIds, $this->showVoided, $this->getPage('halaman'));
    }

    public function record(OperationalRecordService $records): void
    {
        Gate::authorize('operations.view');
        $type = $this->definition();
        abort_if($type === null, 422);
        abort_unless(array_key_exists($this->recordUnit, $this->manageableUnits), 403);

        $done = $this->attempt('action', __('Catatan disimpan.'), function () use ($records, $type): void {
            $records->record($this->selfActor(), $this->currentUser(), $type->key(), $this->recordUnit, $this->occurredOn, $this->values, $this->kpiCode);
        });

        if ($done) {
            $this->reset('values', 'kpiCode');
            unset($this->records);
        }
    }

    public function startVoid(string $recordId): void
    {
        Gate::authorize('operations.view');
        $record = $this->visibleRecord($recordId);
        abort_unless(app(OperationalAccess::class)->canManage($this->currentUser(), $record->organization_unit_id), 403);

        $this->voidId = $recordId;
        $this->voidLock = $record->lock_version;
        $this->reset('voidReason');
    }

    public function void(OperationalRecordService $records): void
    {
        Gate::authorize('operations.view');
        $record = $this->visibleRecord($this->voidId);

        $done = $this->attempt('action', __('Catatan dibatalkan.'), function () use ($records, $record): void {
            $records->void($this->selfActor(), $this->currentUser(), $record->id, $this->voidLock, $this->voidReason);
        });

        if ($done) {
            $this->reset('voidId', 'voidReason');
            unset($this->records);
        }
    }

    /**
     * A record outside what this account may read does not exist for it.
     */
    private function visibleRecord(string $recordId): OperationalRecord
    {
        $type = $this->definition();
        abort_if($type === null, 404);

        return app(OperationalQuery::class)->visible($this->currentUser(), $type)->where('public_id', $recordId)->firstOrFail();
    }
}; ?>

@php
    $type = $this->definition();
    $typeOptions = collect($this->types)->mapWithKeys(fn ($item) => [$item->key() => $item->label()])->all();
    $canRecord = $type !== null && $this->manageableUnits !== [];
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Catatan operasional')"
        :description="__('Catatan yang dipegang team sebagai sumber KPI. Catatan tidak diubah atau dihapus; kesalahan dibatalkan dengan alasan lalu dicatat ulang, sehingga setiap hitungan dapat diaudit.')"
    >
        <x-slot:actions>
            @can('reports.view')
                <x-ui.button variant="secondary" :href="route('reports.index')" wire:navigate>{{ __('Laporan') }}</x-ui.button>
            @endcan
            @can('scorecards.view')
                <x-ui.button variant="secondary" :href="route('scorecards.index')" wire:navigate>{{ __('Scorecard') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Pilih jenis catatan') }}">
        <x-ui.select name="type" :label="__('Jenis catatan')" :options="$typeOptions" wire:model.live="type" />
        <x-ui.input name="unit" :label="__('Kode team (opsional)')" wire:model.live.debounce.500ms="unit" />
        <label class="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" wire:model.live="showVoided" class="size-4"> {{ __('Tampilkan yang dibatalkan') }}</label>
    </form>

    @if ($type)
        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $type->description() }}</p>

        @if ($canRecord)
            <x-ui.panel :title="__('Catat :type', ['type' => $type->label()])">
                <form wire:submit="record" class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="recordUnit" :label="__('Team')" :placeholder="__('Pilih team')" :options="$this->manageableUnits" required wire:model="recordUnit" />
                    <x-ui.input name="occurredOn" type="date" :label="__('Tanggal kejadian')" required wire:model="occurredOn" />
                    @foreach ($type->fields() as $field)
                        @if ($field->kind === FieldSpec::CHOICE)
                            <x-ui.select :name="'values.'.$field->name" :id="'field-'.$field->name" :label="$field->label" :placeholder="__('Pilih')" :options="$field->options" :required="$field->required" wire:model="values.{{ $field->name }}" />
                        @elseif ($field->kind === FieldSpec::LONG_TEXT)
                            <div class="sm:col-span-2"><x-ui.textarea :name="'values.'.$field->name" :id="'field-'.$field->name" :label="$field->label" :required="$field->required" wire:model="values.{{ $field->name }}" /></div>
                        @else
                            <x-ui.input :name="'values.'.$field->name" :id="'field-'.$field->name" :label="$field->label" :hint="$field->hint" :required="$field->required" :inputmode="in_array($field->kind, [FieldSpec::DECIMAL, FieldSpec::INTEGER], true) ? 'decimal' : null" wire:model="values.{{ $field->name }}" />
                        @endif
                    @endforeach
                    <x-ui.input name="kpiCode" :label="__('Kode KPI terkait (opsional)')" wire:model="kpiCode" />
                    <div class="sm:col-span-2"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="record">{{ __('Simpan catatan') }}</x-ui.button></div>
                </form>
            </x-ui.panel>
        @endif

        @if ($this->records)
            <x-ui.table-shell :caption="__('Catatan :type dalam scope Anda', ['type' => $type->label()])" :columns="[__('Tanggal'), __('Team'), __('Isi'), __('Sumber'), __('Dicatat oleh'), __('Status'), __('Aksi')]">
                @foreach ($this->records as $item)
                    <tr wire:key="record-{{ $item->public_id }}">
                        <td class="px-4 py-3 whitespace-nowrap">{{ $item->occurred_on }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $item->unit->code }}</td>
                        <th scope="row" class="px-4 py-3 text-start font-normal">
                            <dl class="grid gap-x-3 gap-y-1 text-xs sm:grid-cols-2">
                                @foreach ($type->fields() as $field)
                                    @php($value = $item->values()[$field->name] ?? null)
                                    @if ($value !== null && $value !== '')
                                        <div><dt class="inline text-zinc-600 dark:text-zinc-400">{{ $field->label }}:</dt> <dd class="inline">{{ $field->kind === FieldSpec::CHOICE ? ($field->options[$value] ?? $value) : $value }}</dd></div>
                                    @endif
                                @endforeach
                                @if ($item->kpiDefinition)
                                    <div><dt class="inline text-zinc-600 dark:text-zinc-400">{{ __('KPI') }}:</dt> <dd class="inline font-mono">{{ $item->kpiDefinition->code }}</dd></div>
                                @endif
                            </dl>
                        </th>
                        <td class="px-4 py-3 text-xs">{{ $item->source_system }}<span class="block font-mono">{{ $item->source_reference }}</span></td>
                        <td class="px-4 py-3">{{ $item->recordedBy->name }}</td>
                        <td class="px-4 py-3">
                            @if ($item->voided_at)
                                <x-ui.status-badge status="neutral">{{ __('Dibatalkan') }}</x-ui.status-badge>
                                <span class="mt-1 block text-xs">{{ $item->void_reason }}</span>
                            @else
                                <x-ui.status-badge status="success">{{ __('Berlaku') }}</x-ui.status-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if (! $item->voided_at && app(OperationalAccess::class)->canManage(auth()->user(), $item->organization_unit_id))
                                <x-ui.button variant="secondary" wire:click="startVoid('{{ $item->public_id }}')">{{ __('Batalkan') }}<span class="sr-only"> {{ $item->source_reference }}</span></x-ui.button>
                            @else
                                —
                            @endif
                        </td>
                    </tr>

                    @if ($voidId === $item->public_id)
                        <tr wire:key="void-{{ $item->public_id }}">
                            <td colspan="7" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                                <form wire:submit="void" class="grid gap-4">
                                    <x-ui.textarea name="voidReason" :label="__('Alasan pembatalan')" :hint="__('Alasan tercatat di audit. Jangan cantumkan angka biaya atau data pribadi.')" required wire:model="voidReason" />
                                    <div><x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="void">{{ __('Batalkan catatan') }}</x-ui.button></div>
                                </form>
                            </td>
                        </tr>
                    @endif
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Belum ada catatan')" :description="__('Tidak ada catatan jenis ini dalam scope Anda.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            {{ $this->records->links() }}
        @endif
    @else
        <x-ui.empty-state :title="__('Tidak ada jenis catatan untuk akun Anda')" :description="__('Jenis catatan tersedia bagi team yang memakainya.')" />
    @endif
</div>
