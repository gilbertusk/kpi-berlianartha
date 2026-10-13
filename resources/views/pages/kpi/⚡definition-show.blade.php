<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Application\KpiCatalogQuery;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\VersionComparison;
use App\Modules\KpiGovernance\Domain\DefinitionContent;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Title('Definisi KPI')] class extends GuardedPage {
    use WithPagination;

    private const TEXT_FIELDS = [
        'name', 'purpose', 'description', 'process_owner', 'data_owner', 'evaluation_subject', 'unit', 'value_type',
        'direction', 'frequency', 'aggregation', 'decimal_places', 'min_valid_value', 'max_valid_value',
        'formula_description', 'numerator_definition', 'denominator_definition', 'score_strategy', 'data_source',
        'input_method', 'evidence_requirement', 'evidence_description', 'exception_policy', 'target_rule', 'approval_chain', 'signoff_reference', 'effective_from',
    ];

    private const PARAM_FIELDS = [
        'missing_data_rule', 'rounding_mode', 'rounding_scale', 'score_floor', 'score_cap', 'zero_target_rule',
        'zero_actual_rule', 'out_of_band_score', 'achieved_score', 'not_achieved_score',
    ];

    #[Locked]
    public string $definitionId = '';

    /** Lock version of the latest version when the page loaded it. */
    #[Locked]
    public int $workingLock = 0;

    /** @var array<string, string> */
    public array $form = [];

    /** @var array<string, string> */
    public array $params = [];

    public string $bandsText = '';
    public string $stagesText = '';
    public string $newVersionReason = '';
    public string $returnReason = '';
    public string $retireUntil = '';
    public string $retireReason = '';
    public string $withdrawReason = '';
    public string $compareFrom = '';
    public string $compareTo = '';
    public string $viewVersion = '';

    public function mount(KpiDefinition $definition): void
    {
        $this->authorize('view', $definition);
        $this->definitionId = $definition->public_id;
        $this->loadLatest();
        $this->retireUntil = $this->businessToday();
    }

    #[Computed]
    public function definition(): KpiDefinition
    {
        return KpiDefinition::query()->with('ownerUnit')->where('public_id', $this->definitionId)->firstOrFail();
    }

    /**
     * @return Collection<int, KpiDefinitionVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return KpiDefinitionVersion::query()
            ->with(['submittedBy', 'publishedBy', 'returnedBy', 'retiredBy'])
            ->where('kpi_definition_id', $this->definition->id)
            ->orderByDesc('version_number')
            ->get();
    }

    #[Computed]
    public function latest(): KpiDefinitionVersion
    {
        return $this->versions->firstOrFail();
    }

    #[Computed]
    public function shown(): KpiDefinitionVersion
    {
        return $this->versions->firstWhere('version_number', (int) $this->viewVersion) ?? $this->latest;
    }

    #[Computed]
    public function today(): string
    {
        return $this->businessToday();
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function pinnedBy(): array
    {
        $scope = $this->access()->unitScope($this->currentUser(), Capability::KpiTemplateView);

        return app(KpiCatalogQuery::class)->templatesPinning($scope, $this->shown->id);
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return $this->currentUser()->can('update', $this->definition);
    }

    #[Computed]
    public function canPublish(): bool
    {
        return $this->currentUser()->can('publish', $this->definition);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function publishProblems(): array
    {
        return $this->latest->content()->publishErrors(app(ScoreStrategyRegistry::class));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function strategies(): array
    {
        return collect(app(ScoreStrategyRegistry::class)->current())->map(fn ($strategy) => $strategy->label())->all();
    }

    #[Computed]
    public function strategyText(): string
    {
        $version = $this->shown;
        $strategy = app(ScoreStrategyRegistry::class)->find($version->score_strategy, $version->score_strategy_version);
        $parameters = $version->score_parameters ?? [];

        if ($strategy === null) {
            return '—';
        }

        return $strategy->validate($parameters) === [] ? $strategy->describe($parameters) : __('Parameter belum lengkap');
    }

    /**
     * @return list<array{field: string, label: string, before: string, after: string}>|null
     */
    #[Computed]
    public function comparison(): ?array
    {
        $from = $this->versions->firstWhere('version_number', (int) $this->compareFrom);
        $to = $this->versions->firstWhere('version_number', (int) $this->compareTo);

        return $from === null || $to === null || $from->id === $to->id ? null : app(VersionComparison::class)->definitions($from, $to);
    }

    #[Computed]
    public function audit(): LengthAwarePaginator
    {
        return app(KpiCatalogQuery::class)->auditTrail('kpi_definition', $this->definitionId, $this->getPage('auditPage'));
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

    public function saveDraft(KpiDefinitionService $definitions): void
    {
        $this->authorize('update', $this->definition);

        $this->validate([
            'form.name' => ['required', 'string', 'max:160'],
            'form.effective_from' => ['nullable', 'date_format:Y-m-d'],
            'form.signoff_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $saved = $this->attempt('action', __('Draft disimpan.'), fn () => $definitions->updateDraft(
            $this->actor(Capability::KpiDefinitionManage),
            $this->latest->id,
            $this->workingLock,
            $this->contentFromForm(),
        ));

        if ($saved) {
            $this->refreshState();
        }
    }

    public function submit(KpiDefinitionService $definitions): void
    {
        $this->authorize('update', $this->definition);

        $this->runAndRefresh(__('Versi diajukan untuk review.'), fn () => $definitions->submitForReview(
            $this->actor(Capability::KpiDefinitionManage),
            $this->latest->id,
            $this->workingLock,
        ));
    }

    public function returnToDraft(KpiDefinitionService $definitions): void
    {
        $user = $this->currentUser();
        abort_unless($user->can('publish', $this->definition) || $user->can('update', $this->definition), 403);
        $this->validate(['returnReason' => ['required', 'string', 'max:2000']]);

        $this->runAndRefresh(__('Versi dikembalikan ke draft.'), fn () => $definitions->returnToDraft(
            $this->actor($user->can('publish', $this->definition) ? Capability::KpiDefinitionPublish : Capability::KpiDefinitionManage),
            $this->latest->id,
            $this->workingLock,
            $this->returnReason,
        ));
    }

    public function publish(KpiDefinitionService $definitions): void
    {
        $this->authorize('publish', $this->definition);

        $this->runAndRefresh(__('Versi dipublish.'), fn () => $definitions->publish(
            $this->actor(Capability::KpiDefinitionPublish),
            $this->latest->id,
            $this->workingLock,
        ));
    }

    public function retire(KpiDefinitionService $definitions): void
    {
        $this->authorize('publish', $this->definition);
        $this->validate([
            'retireUntil' => ['required', 'date_format:Y-m-d'],
            'retireReason' => ['required', 'string', 'max:2000'],
        ]);

        $this->runAndRefresh(__('Versi di-retire.'), fn () => $definitions->retire(
            $this->actor(Capability::KpiDefinitionPublish),
            $this->latest->id,
            $this->workingLock,
            $this->retireUntil,
            $this->retireReason,
        ));
    }

    public function withdraw(KpiDefinitionService $definitions): void
    {
        $this->authorize('publish', $this->definition);
        $this->validate(['withdrawReason' => ['required', 'string', 'max:2000']]);

        $this->runAndRefresh(__('Versi terjadwal dibatalkan; versi sebelumnya berlaku kembali.'), fn () => $definitions->withdrawScheduled(
            $this->actor(Capability::KpiDefinitionPublish),
            $this->latest->id,
            $this->workingLock,
            $this->withdrawReason,
        ));
    }

    public function startNewVersion(KpiDefinitionService $definitions): void
    {
        $this->authorize('update', $this->definition);
        $this->validate(['newVersionReason' => ['required', 'string', 'max:2000']]);

        $this->runAndRefresh(__('Versi draft baru dibuat dari versi terakhir.'), fn () => $definitions->startNewVersion(
            $this->actor(Capability::KpiDefinitionManage),
            $this->definition->id,
            $this->newVersionReason,
        ));
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
        unset($this->definition, $this->versions, $this->latest, $this->shown, $this->pinnedBy, $this->publishProblems, $this->comparison, $this->audit, $this->actorNames, $this->strategyText);
        $this->loadLatest();
    }

    private function actor(Capability $capability): Actor
    {
        return $this->actorFor($capability, $this->access()->unitTarget($this->definition->owner_unit_id));
    }

    private function loadLatest(): void
    {
        $latest = KpiDefinitionVersion::query()
            ->where('kpi_definition_id', $this->definition->id)
            ->orderByDesc('version_number')
            ->firstOrFail();

        $this->workingLock = $latest->lock_version;
        $this->form = [];

        foreach (self::TEXT_FIELDS as $field) {
            $this->form[$field] = (string) ($latest->{$field} ?? '');
        }

        $parameters = $latest->score_parameters ?? [];
        $this->params = [];

        foreach (self::PARAM_FIELDS as $field) {
            $value = $parameters[$field] ?? '';
            $this->params[$field] = is_scalar($value) ? (string) $value : '';
        }

        $this->bandsText = collect(is_array($parameters['bands'] ?? null) ? $parameters['bands'] : [])
            ->map(fn ($band) => is_array($band) ? implode(';', [$band['min'] ?? '', $band['max'] ?? '', $band['score'] ?? '']) : '')
            ->implode("\n");
        $this->stagesText = collect(is_array($parameters['stages'] ?? null) ? $parameters['stages'] : [])
            ->map(fn ($stage) => is_array($stage) ? implode(';', [$stage['label'] ?? '', $stage['score'] ?? '']) : '')
            ->implode("\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function contentFromForm(): array
    {
        $content = [];

        foreach (self::TEXT_FIELDS as $field) {
            $value = $this->form[$field] ?? '';
            $content[$field] = is_string($value) ? $value : '';
        }

        $strategyKey = $content['score_strategy'] !== '' ? $content['score_strategy'] : null;
        $strategy = $strategyKey === null ? null : (app(ScoreStrategyRegistry::class)->current()[$strategyKey] ?? null);

        $content['score_strategy'] = $strategy?->key();
        $content['score_strategy_version'] = $strategy?->version();
        $content['score_parameters'] = $strategy === null ? null : $this->parametersFor($strategy->key());

        return array_intersect_key($content, DefinitionContent::FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    private function parametersFor(string $strategy): array
    {
        $pick = fn (array $keys): array => array_intersect_key($this->params, array_flip($keys));
        $common = $pick(['missing_data_rule', 'rounding_mode', 'rounding_scale']);

        return match ($strategy) {
            'ratio_to_target' => [...$common, ...$pick(['score_floor', 'score_cap', 'zero_target_rule'])],
            'inverse_ratio_to_target' => [...$common, ...$pick(['score_floor', 'score_cap', 'zero_target_rule', 'zero_actual_rule'])],
            'range_band' => [...$common, 'bands' => $this->rows($this->bandsText, ['min', 'max', 'score']), ...$pick(['out_of_band_score'])],
            'binary' => [...$common, ...$pick(['achieved_score', 'not_achieved_score'])],
            'milestone_stage' => [...$common, 'stages' => $this->rows($this->stagesText, ['label', 'score'])],
            default => $common,
        };
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private function rows(string $text, array $keys): array
    {
        $rows = [];

        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = array_map('trim', explode(';', $line));
            $rows[] = array_combine($keys, array_pad(array_slice($parts, 0, count($keys)), count($keys), ''));
        }

        return $rows;
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$this->latest->name" :description="$this->definition->code.' · '.__('Unit pemilik').': '.$this->definition->ownerUnit->code">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('kpi.definitions')" wire:navigate>{{ __('Kembali ke kamus') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->definition->isCandidate())
        <x-ui.alert variant="warning" :title="__('Kandidat dari lampiran PRD')">
            {{ __('KPI ini berasal dari daftar kandidat PRD dan bukan KPI resmi. Hanya dapat dipublish setelah seluruh definisi lengkap dan disetujui pemilik proses serta HR.') }}
        </x-ui.alert>
    @endif

    @include('pages.administration.partials.feedback')

    <x-ui.table-shell :caption="__('Versi definisi')" :columns="[__('Versi'), __('Status'), __('Berlaku'), __('Alasan perubahan'), __('Diajukan / dipublish'), __('Lihat')]">
        @foreach ($this->versions as $version)
            <tr wire:key="version-{{ $version->public_id }}">
                <td class="px-4 py-3">v{{ $version->version_number }}</td>
                <td class="px-4 py-3">@include('pages.kpi.partials.status-badge', ['status' => $version->status])</td>
                <td class="px-4 py-3">{{ $version->effective_from ?? '—' }} → {{ $version->effective_until ?? __('tanpa batas') }}</td>
                <td class="px-4 py-3">
                    {{ $version->change_reason ?? '—' }}
                    @if ($version->return_reason)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Dikembalikan') }}: {{ $version->return_reason }}</span>
                    @endif
                    @if ($version->retire_reason)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Retire') }}: {{ $version->retire_reason }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs">
                    {{ $version->submittedBy?->name ?? '—' }} / {{ $version->publishedBy?->name ?? '—' }}
                </td>
                <td class="px-4 py-3">
                    <x-ui.button variant="ghost" wire:click="$set('viewVersion', '{{ $version->version_number }}')">v{{ $version->version_number }}</x-ui.button>
                </td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    {{-- Lifecycle actions for the latest version --}}
    @php($latest = $this->latest)
    <x-ui.panel :title="__('Tindakan untuk versi terakhir').' (v'.$latest->version_number.')'">
        <div class="flex flex-col gap-4">
            @if ($latest->status === LifecycleStatus::Draft || $latest->status === LifecycleStatus::Review)
                @if ($this->publishProblems === [])
                    <x-ui.alert variant="success" :title="__('Definisi lengkap dan siap direview.')" />
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

            @if ($latest->status === LifecycleStatus::Draft && $this->canUpdate)
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

    @if ($latest->status === LifecycleStatus::Draft && $this->canUpdate)
        <x-ui.panel :title="__('Edit draft').' v'.$latest->version_number">
            @include('pages.kpi.partials.definition-editor', ['strategy' => $form['score_strategy'] ?? '', 'strategies' => $this->strategies])
        </x-ui.panel>
    @endif

    <section class="flex flex-col gap-3" aria-labelledby="content-heading">
        <h2 id="content-heading" class="text-lg font-semibold">{{ __('Isi versi') }} v{{ $this->shown->version_number }}
            @include('pages.kpi.partials.status-badge', ['status' => $this->shown->status])
        </h2>
        @include('pages.kpi.partials.definition-fields', ['version' => $this->shown, 'strategyText' => $this->strategyText])

        @if ($this->pinnedBy !== [])
            <x-ui.alert variant="info" :title="__('Versi ini dipakai oleh template')">
                <ul class="list-disc ps-5">
                    @foreach ($this->pinnedBy as $usage)
                        <li>{{ $usage['code'] }} v{{ $usage['version'] }} ({{ $usage['status'] }}, {{ $usage['effective_from'] ?? '—' }} → {{ $usage['effective_until'] ?? __('tanpa batas') }})</li>
                    @endforeach
                </ul>
                <p class="mt-1">{{ __('Template tetap merujuk versi ini sampai dibuat versi template baru.') }}</p>
            </x-ui.alert>
        @endif
    </section>

    @if ($this->versions->count() > 1)
        <section class="flex flex-col gap-3" aria-labelledby="compare-heading">
            <h2 id="compare-heading" class="text-lg font-semibold">{{ __('Bandingkan versi') }}</h2>
            @php($versionOptions = $this->versions->mapWithKeys(fn ($version) => [(string) $version->version_number => 'v'.$version->version_number.' ('.$version->status->label().')'])->all())
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="compareFrom" :label="__('Dari versi')" :placeholder="__('Pilih')" :options="$versionOptions" wire:model.live="compareFrom" />
                <x-ui.select name="compareTo" :label="__('Ke versi')" :placeholder="__('Pilih')" :options="$versionOptions" wire:model.live="compareTo" />
            </div>

            @if ($this->comparison !== null)
                @php($newer = $this->versions->firstWhere('version_number', max((int) $compareFrom, (int) $compareTo)))
                <x-ui.alert variant="info" :title="__('Alasan perubahan versi').' v'.$newer?->version_number">{{ $newer?->change_reason ?? '—' }}</x-ui.alert>
                <x-ui.table-shell :caption="__('Field yang berubah')" :columns="[__('Field'), 'v'.$compareFrom, 'v'.$compareTo]">
                    @foreach ($this->comparison as $change)
                        <tr wire:key="change-{{ $change['field'] }}">
                            <th scope="row" class="px-4 py-3 text-start font-medium">{{ $change['label'] }}</th>
                            <td class="px-4 py-3 break-words">{{ $change['before'] }}</td>
                            <td class="px-4 py-3 break-words">{{ $change['after'] }}</td>
                        </tr>
                    @endforeach
                    <x-slot:empty>
                        <x-ui.empty-state :title="__('Tidak ada perbedaan isi')" />
                    </x-slot:empty>
                </x-ui.table-shell>
            @endif
        </section>
    @endif

    @include('pages.kpi.partials.audit-trail', ['entries' => $this->audit, 'actorNames' => $this->actorNames])
</div>
