<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Application\KpiCatalogQuery;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Application\VersionComparison;
use App\Modules\KpiGovernance\Domain\ItemApplicability;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\KpiGovernance\Domain\Vocabulary;
use App\Modules\KpiGovernance\Domain\Weight;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Title('Template scorecard')] class extends GuardedPage {
    use WithPagination;

    #[Locked]
    public string $templateId = '';

    #[Locked]
    public int $workingLock = 0;

    public string $notes = '';
    public string $signoffReference = '';
    public string $effectiveFrom = '';

    public string $kpiSearch = '';
    public string $itemDefinition = '';
    public string $itemWeight = '';
    public string $itemComponent = '';
    public bool $itemMandatory = true;
    public string $itemPositions = '';

    #[Locked]
    public ?string $editingItem = null;

    public string $editWeight = '';
    public string $editComponent = '';
    public bool $editMandatory = true;
    public string $editPositions = '';

    public string $newVersionReason = '';
    public string $returnReason = '';
    public string $retireUntil = '';
    public string $retireReason = '';
    public string $withdrawReason = '';
    public string $compareFrom = '';
    public string $compareTo = '';
    public string $viewVersion = '';

    public function mount(KpiTemplate $template): void
    {
        $this->authorize('view', $template);
        $this->templateId = $template->public_id;
        $this->retireUntil = $this->businessToday();
        $this->loadLatest();
    }

    #[Computed]
    public function template(): KpiTemplate
    {
        return KpiTemplate::query()->with('unit')->where('public_id', $this->templateId)->firstOrFail();
    }

    /**
     * @return Collection<int, KpiTemplateVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return KpiTemplateVersion::query()
            ->with(['submittedBy', 'publishedBy'])
            ->where('kpi_template_id', $this->template->id)
            ->orderByDesc('version_number')
            ->get();
    }

    #[Computed]
    public function latest(): KpiTemplateVersion
    {
        return $this->versions->firstOrFail();
    }

    #[Computed]
    public function shown(): KpiTemplateVersion
    {
        return $this->versions->firstWhere('version_number', (int) $this->viewVersion) ?? $this->latest;
    }

    /**
     * @return Collection<int, KpiTemplateItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return $this->shown->items()->with('definitionVersion.definition')->get();
    }

    #[Computed]
    public function totalUnits(): int
    {
        return $this->items->sum(fn (KpiTemplateItem $item): int => Weight::fromString($item->weight)->units);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function publishProblems(): array
    {
        return app(KpiTemplateService::class)->publishProblems($this->latest);
    }

    #[Computed]
    public function today(): string
    {
        return $this->businessToday();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function pinnedWarnings(): array
    {
        return app(KpiTemplateService::class)->pinnedKpiWarnings($this->shown);
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return $this->currentUser()->can('update', $this->template);
    }

    #[Computed]
    public function canPublish(): bool
    {
        return $this->currentUser()->can('publish', $this->template);
    }

    /**
     * KPI versions that may be added: published versions and the working
     * version of each KPI the viewer can see. Search narrows the list.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function definitionOptions(): array
    {
        $scope = $this->access()->unitScope($this->currentUser(), Capability::KpiDefinitionView);
        $needle = trim($this->kpiSearch);

        if (mb_strlen($needle) < 2) {
            return [];
        }

        $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';

        return KpiDefinitionVersion::query()
            ->with('definition')
            ->whereIn('status', [LifecycleStatus::Draft->value, LifecycleStatus::Review->value, LifecycleStatus::Published->value])
            ->whereHas('definition', function (Builder $definition) use ($scope): void {
                if (! $scope->all) {
                    $definition->whereIn('owner_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
                }
            })
            ->where(fn (Builder $match) => $match
                ->whereRaw('lower(name) like ?', [$pattern])
                ->orWhereHas('definition', fn (Builder $definition) => $definition->whereRaw('lower(code) like ?', [$pattern])))
            ->orderBy('kpi_definition_id')
            ->orderByDesc('version_number')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (KpiDefinitionVersion $version): array => [
                $version->public_id => "{$version->definition->code} v{$version->version_number} — {$version->name} ({$version->status->label()})",
            ])
            ->all();
    }

    /**
     * @return array{header: list<array<string, string>>, items: list<array<string, string>>}|null
     */
    #[Computed]
    public function comparison(): ?array
    {
        $from = $this->versions->firstWhere('version_number', (int) $this->compareFrom);
        $to = $this->versions->firstWhere('version_number', (int) $this->compareTo);

        return $from === null || $to === null || $from->id === $to->id ? null : app(VersionComparison::class)->templates($from, $to);
    }

    #[Computed]
    public function audit(): LengthAwarePaginator
    {
        return app(KpiCatalogQuery::class)->auditTrail('kpi_template', $this->templateId, $this->getPage('auditPage'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function actorNames(): array
    {
        $ids = collect($this->audit->items())->pluck('actor_identifier')->filter()->unique()->all();

        return User::query()->whereIn('public_id', $ids)->pluck('name', 'public_id')->all();
    }

    /**
     * Score rule summary per pinned KPI version shown in the preview.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function strategyTexts(): array
    {
        $registry = app(ScoreStrategyRegistry::class);
        $texts = [];

        foreach ($this->items as $item) {
            $version = $item->definitionVersion;
            $strategy = $registry->find($version->score_strategy, $version->score_strategy_version);
            $parameters = $version->score_parameters ?? [];
            $texts[$version->id] = $strategy !== null && $strategy->validate($parameters) === [] ? $strategy->describe($parameters) : '—';
        }

        return $texts;
    }

    public function saveHeader(KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        $this->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'signoffReference' => ['nullable', 'string', 'max:255'],
            'effectiveFrom' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $this->runAndRefresh(__('Header draft disimpan.'), fn () => $templates->updateDraftHeader(
            $this->actor(Capability::KpiTemplateManage),
            $this->latest->id,
            $this->workingLock,
            $this->notes,
            $this->signoffReference,
            $this->effectiveFrom,
        ));
    }

    public function addItem(KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        $this->validate([
            'itemDefinition' => ['required', 'string', 'size:26'],
            'itemWeight' => ['required', 'string', 'max:10'],
            'itemComponent' => ['nullable', 'string', 'max:80'],
            'itemPositions' => ['nullable', 'string', 'max:2000'],
        ]);

        $definitionVersion = KpiDefinitionVersion::query()->with('definition')->where('public_id', $this->itemDefinition)->firstOrFail();
        $this->authorize('view', $definitionVersion->definition);

        $added = $this->attempt('action', __('KPI ditambahkan ke draft.'), fn () => $templates->addItem(
            $this->actor(Capability::KpiTemplateManage),
            $this->latest->id,
            $this->workingLock,
            $definitionVersion->id,
            $this->itemWeight,
            $this->itemComponent,
            $this->itemMandatory,
            $this->applicabilityFrom($this->itemPositions),
        ));

        if ($added) {
            $this->reset('itemDefinition', 'itemWeight', 'itemComponent', 'itemPositions', 'kpiSearch');
            $this->itemMandatory = true;
            $this->refreshState();
        }
    }

    public function startEditItem(string $publicId): void
    {
        $this->authorize('update', $this->template);
        $item = $this->latestItem($publicId);

        $this->editingItem = $item->public_id;
        $this->editWeight = Weight::fromString($item->weight)->format();
        $this->editComponent = (string) $item->component;
        $this->editMandatory = $item->is_mandatory;
        $this->editPositions = implode(', ', ItemApplicability::fromArray($item->applicability)?->positions ?? []);
    }

    public function saveItem(KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        $this->validate([
            'editWeight' => ['required', 'string', 'max:10'],
            'editComponent' => ['nullable', 'string', 'max:80'],
            'editPositions' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = $this->latestItem((string) $this->editingItem);

        $saved = $this->attempt('action', __('Item diperbarui.'), fn () => $templates->updateItem(
            $this->actor(Capability::KpiTemplateManage),
            $item->id,
            $this->workingLock,
            $this->editWeight,
            $this->editComponent,
            $this->editMandatory,
            $this->applicabilityFrom($this->editPositions),
        ));

        if ($saved) {
            $this->editingItem = null;
            $this->refreshState();
        }
    }

    public function removeItem(string $publicId, KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        $item = $this->latestItem($publicId);

        $this->runAndRefresh(__('Item dihapus dari draft.'), fn () => $templates->removeItem($this->actor(Capability::KpiTemplateManage), $item->id, $this->workingLock));
    }

    public function moveItem(string $publicId, int $offset, KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        abort_unless(in_array($offset, [-1, 1], true), 404);
        $item = $this->latestItem($publicId);

        $this->runAndRefresh(__('Urutan diperbarui.'), fn () => $templates->moveItem($this->actor(Capability::KpiTemplateManage), $item->id, $this->workingLock, $offset));
    }

    public function submit(KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        $this->runAndRefresh(__('Template diajukan untuk review.'), fn () => $templates->submitForReview($this->actor(Capability::KpiTemplateManage), $this->latest->id, $this->workingLock));
    }

    public function returnToDraft(KpiTemplateService $templates): void
    {
        $user = $this->currentUser();
        abort_unless($user->can('publish', $this->template) || $user->can('update', $this->template), 403);
        $this->validate(['returnReason' => ['required', 'string', 'max:2000']]);

        $this->runAndRefresh(__('Template dikembalikan ke draft.'), fn () => $templates->returnToDraft(
            $this->actor($user->can('publish', $this->template) ? Capability::KpiTemplatePublish : Capability::KpiTemplateManage),
            $this->latest->id,
            $this->workingLock,
            $this->returnReason,
        ));
    }

    public function publish(KpiTemplateService $templates): void
    {
        $this->authorize('publish', $this->template);
        $this->runAndRefresh(__('Template dipublish.'), fn () => $templates->publish($this->actor(Capability::KpiTemplatePublish), $this->latest->id, $this->workingLock));
    }

    public function retire(KpiTemplateService $templates): void
    {
        $this->authorize('publish', $this->template);
        $this->validate([
            'retireUntil' => ['required', 'date_format:Y-m-d'],
            'retireReason' => ['required', 'string', 'max:2000'],
        ]);

        $this->runAndRefresh(__('Template di-retire.'), fn () => $templates->retire($this->actor(Capability::KpiTemplatePublish), $this->latest->id, $this->workingLock, $this->retireUntil, $this->retireReason));
    }

    public function withdraw(KpiTemplateService $templates): void
    {
        $this->authorize('publish', $this->template);
        $this->validate(['withdrawReason' => ['required', 'string', 'max:2000']]);

        $this->runAndRefresh(__('Versi terjadwal dibatalkan; versi sebelumnya berlaku kembali.'), fn () => $templates->withdrawScheduled($this->actor(Capability::KpiTemplatePublish), $this->latest->id, $this->workingLock, $this->withdrawReason));
    }

    public function startNewVersion(KpiTemplateService $templates): void
    {
        $this->authorize('update', $this->template);
        $this->validate(['newVersionReason' => ['required', 'string', 'max:2000']]);

        $this->runAndRefresh(__('Versi draft baru dibuat dari versi terakhir.'), fn () => $templates->startNewVersion($this->actor(Capability::KpiTemplateManage), $this->template->id, $this->newVersionReason));
    }

    private function runAndRefresh(string $message, Closure $operation): void
    {
        if ($this->attempt('action', $message, $operation)) {
            $this->reset('newVersionReason', 'returnReason', 'retireReason', 'withdrawReason');
            $this->refreshState();
        }
    }

    private function refreshState(): void
    {
        unset($this->template, $this->versions, $this->latest, $this->shown, $this->pinnedWarnings, $this->items, $this->strategyTexts, $this->totalUnits, $this->publishProblems, $this->comparison, $this->audit, $this->actorNames);
        $this->loadLatest();
    }

    private function loadLatest(): void
    {
        $latest = KpiTemplateVersion::query()->where('kpi_template_id', $this->template->id)->orderByDesc('version_number')->firstOrFail();

        $this->workingLock = $latest->lock_version;
        $this->notes = (string) $latest->notes;
        $this->signoffReference = (string) $latest->signoff_reference;
        $this->effectiveFrom = (string) $latest->effective_from;
    }

    private function latestItem(string $publicId): KpiTemplateItem
    {
        return KpiTemplateItem::query()
            ->where('kpi_template_version_id', $this->latest->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function applicabilityFrom(string $positions): ?array
    {
        try {
            return ItemApplicability::fromList($positions)?->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('invalid_item', $exception->getMessage());
        }
    }

    private function actor(Capability $capability): Actor
    {
        return $this->actorFor($capability, $this->access()->unitTarget($this->template->organization_unit_id));
    }
}; ?>

@php
    $latest = $this->latest;
    $editable = $latest->status === LifecycleStatus::Draft && $this->canUpdate;
    $shown = $this->shown;
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$this->template->name" :description="$this->template->code.' · '.$this->template->unit->code.' · '.(KpiTemplate::SUBJECTS[$this->template->subject] ?? $this->template->subject).($this->template->position_title ? ' · '.$this->template->position_title : '')">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('kpi.templates')" wire:navigate>{{ __('Kembali ke template') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback')

    <x-ui.table-shell :caption="__('Versi template')" :columns="[__('Versi'), __('Status'), __('Berlaku'), __('Alasan perubahan'), __('Sign-off'), __('Lihat')]">
        @foreach ($this->versions as $version)
            <tr wire:key="tversion-{{ $version->public_id }}">
                <td class="px-4 py-3">v{{ $version->version_number }}</td>
                <td class="px-4 py-3">@include('pages.kpi.partials.status-badge', ['status' => $version->status])</td>
                <td class="px-4 py-3">{{ $version->effective_from ?? '—' }} → {{ $version->effective_until ?? __('tanpa batas') }}</td>
                <td class="px-4 py-3">{{ $version->change_reason ?? '—' }}</td>
                <td class="px-4 py-3">{{ $version->signoff_reference ?? '—' }}</td>
                <td class="px-4 py-3"><x-ui.button variant="ghost" wire:click="$set('viewVersion', '{{ $version->version_number }}')">v{{ $version->version_number }}</x-ui.button></td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    {{-- Preview of the selected version --}}
    <section class="flex flex-col gap-3" aria-labelledby="preview-heading">
        <h2 id="preview-heading" class="text-lg font-semibold">{{ __('Pratinjau scorecard') }} v{{ $shown->version_number }}
            @include('pages.kpi.partials.status-badge', ['status' => $shown->status])
        </h2>

        @if ($this->totalUnits === Weight::TOTAL_REQUIRED_UNITS)
            <x-ui.alert variant="success" :title="__('Total bobot 100%')" />
        @else
            <x-ui.alert variant="warning" :title="__('Total bobot :total% — wajib tepat 100% sebelum publish', ['total' => Weight::formatUnits($this->totalUnits)])" />
        @endif

        @if ($this->pinnedWarnings !== [])
            <x-ui.alert variant="info" :title="__('Perhatian versi KPI')">
                <ul class="list-disc ps-5">
                    @foreach ($this->pinnedWarnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.table-shell :caption="__('Item template')" :columns="[__('#'), __('Komponen'), __('KPI'), __('Pengukuran'), __('Strategi skor'), __('Bobot'), __('Wajib'), __('Berlaku untuk'), __('Aksi')]">
            @foreach ($this->items as $item)
                @php($definition = $item->definitionVersion)
                <tr wire:key="item-{{ $item->public_id }}">
                    <td class="px-4 py-3">{{ $item->sort_order }}</td>
                    <td class="px-4 py-3">{{ $item->component ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs">{{ $definition->definition->code }} v{{ $definition->version_number }}</span>
                        <span class="block">{{ $definition->name }}</span>
                        @include('pages.kpi.partials.status-badge', ['status' => $definition->status])
                    </td>
                    <td class="px-4 py-3 text-xs">{{ Vocabulary::label(Vocabulary::FREQUENCIES, $definition->frequency) }} · {{ Vocabulary::label(Vocabulary::UNITS, $definition->unit) }} · {{ Vocabulary::label(Vocabulary::DIRECTIONS, $definition->direction) }}</td>
                    <td class="px-4 py-3 text-xs">{{ $this->strategyTexts[$definition->id] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ Weight::fromString($item->weight)->format() }}%</td>
                    <td class="px-4 py-3">{{ $item->is_mandatory ? __('Wajib') : __('Opsional') }}</td>
                    <td class="px-4 py-3 text-xs">{{ ItemApplicability::fromArray($item->applicability)?->describe() ?? __('Semua anggota') }}</td>
                    <td class="px-4 py-3">
                        @if ($editable && $shown->id === $latest->id)
                            <div class="flex flex-wrap gap-1">
                                <x-ui.button variant="ghost" wire:click="moveItem('{{ $item->public_id }}', -1)" aria-label="{{ __('Naikkan') }} {{ $definition->name }}">↑</x-ui.button>
                                <x-ui.button variant="ghost" wire:click="moveItem('{{ $item->public_id }}', 1)" aria-label="{{ __('Turunkan') }} {{ $definition->name }}">↓</x-ui.button>
                                <x-ui.button variant="secondary" wire:click="startEditItem('{{ $item->public_id }}')">{{ __('Ubah') }}</x-ui.button>
                                <x-ui.button variant="ghost" wire:click="removeItem('{{ $item->public_id }}')">{{ __('Hapus') }}</x-ui.button>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Belum ada KPI di versi ini')" />
            </x-slot:empty>
        </x-ui.table-shell>
    </section>

    @if ($editingItem !== null && $editable)
        <x-ui.panel :title="__('Ubah item')">
            <form wire:submit="saveItem" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="editWeight" :label="__('Bobot (%)')" required wire:model="editWeight" />
                <x-ui.input name="editComponent" :label="__('Komponen')" wire:model="editComponent" />
                <x-ui.input name="editPositions" :label="__('Berlaku untuk jabatan (pisahkan koma)')" :hint="__('Kosongkan untuk semua anggota.')" wire:model="editPositions" />
                <label class="flex items-center gap-2 self-end text-sm"><input type="checkbox" wire:model="editMandatory" class="size-4"> {{ __('Wajib') }}</label>
                <div class="flex gap-2 sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Simpan item') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('editingItem', null)">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    @if ($editable)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.panel :title="__('Header draft').' v'.$latest->version_number">
                <form wire:submit="saveHeader" class="grid gap-4">
                    <x-ui.input name="effectiveFrom" type="date" :label="__('Berlaku mulai')" wire:model="effectiveFrom" />
                    <x-ui.input name="signoffReference" :label="__('Referensi sign-off bisnis')" :hint="__('Wajib sebelum review. Bobot PRD masih draft (DEC-003).')" wire:model="signoffReference" />
                    <x-ui.textarea name="notes" :label="__('Catatan')" wire:model="notes" />
                    <div><x-ui.button type="submit">{{ __('Simpan header') }}</x-ui.button></div>
                </form>
            </x-ui.panel>

            <x-ui.panel :title="__('Tambah KPI')">
                <form wire:submit="addItem" class="grid gap-4">
                    <x-ui.input name="kpiSearch" type="search" :label="__('Cari KPI (kode atau nama, min. 2 huruf)')" wire:model.live.debounce.400ms="kpiSearch" />
                    <x-ui.select name="itemDefinition" :label="__('Versi KPI')" required :placeholder="__('Pilih versi KPI')" :options="$this->definitionOptions" wire:model="itemDefinition" />
                    <x-ui.input name="itemWeight" :label="__('Bobot (%)')" required :hint="__('Maksimal 4 desimal, contoh 12.5')" wire:model="itemWeight" />
                    <x-ui.input name="itemComponent" :label="__('Komponen (opsional)')" :hint="__('Contoh: KPI individu, KPI team')" wire:model="itemComponent" />
                    <x-ui.input name="itemPositions" :label="__('Berlaku untuk jabatan (pisahkan koma)')" :hint="__('Kosongkan untuk semua anggota.')" wire:model="itemPositions" />
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="itemMandatory" class="size-4"> {{ __('Wajib') }}</label>
                    <div><x-ui.button type="submit">{{ __('Tambah') }}</x-ui.button></div>
                </form>
            </x-ui.panel>
        </div>
    @endif

    <x-ui.panel :title="__('Tindakan untuk versi terakhir').' (v'.$latest->version_number.')'">
        <div class="flex flex-col gap-4">
            @if (in_array($latest->status, [LifecycleStatus::Draft, LifecycleStatus::Review], true))
                @if ($this->publishProblems === [])
                    <x-ui.alert variant="success" :title="__('Template siap direview.')" />
                @else
                    <x-ui.alert variant="warning" :title="__('Belum dapat direview/dipublish')">
                        <ul class="list-disc ps-5">
                            @foreach ($this->publishProblems as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif
            @endif

            @if ($editable)
                <div><x-ui.button wire:click="submit">{{ __('Ajukan review') }}</x-ui.button></div>
            @endif

            @if ($latest->status === LifecycleStatus::Review)
                @if ($this->canPublish && $latest->submitted_by_user_id !== auth()->id())
                    <div><x-ui.button wire:click="publish">{{ __('Publish versi ini') }}</x-ui.button></div>
                @elseif ($latest->submitted_by_user_id === auth()->id())
                    <x-ui.alert variant="info" :title="__('Menunggu reviewer lain. Pengaju tidak dapat mempublish versinya sendiri.')" />
                @endif

                @if ($this->canPublish || $this->canUpdate)
                    <form wire:submit="returnToDraft" class="grid gap-3 sm:grid-cols-2">
                        <x-ui.textarea name="returnReason" :label="__('Alasan dikembalikan ke draft')" required wire:model="returnReason" />
                        <div class="self-end"><x-ui.button type="submit" variant="secondary">{{ __('Kembalikan ke draft') }}</x-ui.button></div>
                    </form>
                @endif
            @endif

            @if (in_array($latest->status, [LifecycleStatus::Published, LifecycleStatus::Retired], true) && $this->canUpdate)
                <form wire:submit="startNewVersion" class="grid gap-3 sm:grid-cols-2">
                    <x-ui.textarea name="newVersionReason" :label="__('Alasan versi baru')" required wire:model="newVersionReason" />
                    <div class="self-end"><x-ui.button type="submit">{{ __('Buat versi baru') }}</x-ui.button></div>
                </form>
            @endif

            @if ($latest->status === LifecycleStatus::Published && $this->canPublish && (string) $latest->effective_from > $this->today)
                <form wire:submit="withdraw" class="grid gap-3 sm:grid-cols-2">
                    <x-ui.textarea name="withdrawReason" :label="__('Alasan membatalkan versi terjadwal')" :hint="__('Versi ini belum berlaku. Pembatalan membuka kembali versi sebelumnya; tidak ada histori yang berubah.')" required wire:model="withdrawReason" />
                    <div class="self-end"><x-ui.button type="submit" variant="danger">{{ __('Batalkan versi terjadwal') }}</x-ui.button></div>
                </form>
            @endif

            @if ($latest->status === LifecycleStatus::Published && $this->canPublish)
                <form wire:submit="retire" class="grid gap-3 sm:grid-cols-3">
                    <x-ui.input name="retireUntil" type="date" :label="__('Berakhir pada (eksklusif)')" required wire:model="retireUntil" />
                    <x-ui.textarea name="retireReason" :label="__('Alasan retire')" required wire:model="retireReason" />
                    <div class="self-end"><x-ui.button type="submit" variant="danger">{{ __('Retire') }}</x-ui.button></div>
                </form>
            @endif
        </div>
    </x-ui.panel>

    @if ($this->versions->count() > 1)
        <section class="flex flex-col gap-3" aria-labelledby="tcompare-heading">
            <h2 id="tcompare-heading" class="text-lg font-semibold">{{ __('Bandingkan versi') }}</h2>
            @php($versionOptions = $this->versions->mapWithKeys(fn ($version) => [(string) $version->version_number => 'v'.$version->version_number.' ('.$version->status->label().')'])->all())
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="compareFrom" :label="__('Dari versi')" :placeholder="__('Pilih')" :options="$versionOptions" wire:model.live="compareFrom" />
                <x-ui.select name="compareTo" :label="__('Ke versi')" :placeholder="__('Pilih')" :options="$versionOptions" wire:model.live="compareTo" />
            </div>

            @if ($this->comparison !== null)
                @php($newer = $this->versions->firstWhere('version_number', max((int) $compareFrom, (int) $compareTo)))
                <x-ui.alert variant="info" :title="__('Alasan perubahan versi').' v'.$newer?->version_number">{{ $newer?->change_reason ?? '—' }}</x-ui.alert>
                <x-ui.table-shell :caption="__('Perubahan item')" :columns="[__('KPI'), __('Perubahan'), 'v'.$compareFrom, 'v'.$compareTo]">
                    @foreach ($this->comparison['header'] as $change)
                        <tr><th scope="row" class="px-4 py-3 text-start font-medium">{{ $change['label'] }}</th><td class="px-4 py-3">{{ __('diubah') }}</td><td class="px-4 py-3">{{ $change['before'] }}</td><td class="px-4 py-3">{{ $change['after'] }}</td></tr>
                    @endforeach
                    @foreach ($this->comparison['items'] as $change)
                        <tr><th scope="row" class="px-4 py-3 text-start font-medium">{{ $change['kpi'] }}</th><td class="px-4 py-3">{{ $change['change'] }}</td><td class="px-4 py-3">{{ $change['before'] }}</td><td class="px-4 py-3">{{ $change['after'] }}</td></tr>
                    @endforeach
                    <x-slot:empty>
                        <x-ui.empty-state :title="__('Tidak ada perbedaan')" />
                    </x-slot:empty>
                </x-ui.table-shell>
            @endif
        </section>
    @endif

    @include('pages.kpi.partials.audit-trail', ['entries' => $this->audit, 'actorNames' => $this->actorNames])
</div>
