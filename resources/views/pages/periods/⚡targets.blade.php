<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Domain\Vocabulary;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Target\Application\TargetEntry;
use App\Modules\Target\Application\TargetQuery;
use App\Modules\Target\Application\TargetRow;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Domain\Exceptions\TargetEntriesRejected;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Domain\TargetValueRules;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Target')] class extends GuardedPage {
    use WithPagination;

    #[Locked]
    public string $periodId = '';

    #[Url(as: 'unit')]
    public string $unit = '';

    #[Url(as: 'status')]
    public string $state = '';

    #[Url(as: 'cari')]
    public string $search = '';

    /** @var array<string, string> target public id => value typed in the matrix */
    public array $values = [];

    /** @var array<string, int> target public id => draft lock version first shown (0 = no draft) */
    #[Locked]
    public array $locks = [];

    /** @var array<string, list<string>> target public id => messages of the last rejected save */
    #[Locked]
    public array $rowErrors = [];

    public string $reason = '';

    #[Locked]
    public ?string $revising = null;

    public string $reviseValue = '';
    public string $reviseReason = '';

    #[Locked]
    public ?string $returning = null;

    #[Locked]
    public int $returningLock = 0;

    public string $returnReason = '';

    #[Locked]
    public ?string $historyOf = null;

    public function mount(AssessmentPeriod $period): void
    {
        $this->authorize('viewTargets', $period);
        $this->periodId = $period->public_id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['unit', 'state', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->with(['scopeUnit', 'deadlines'])->where('public_id', $this->periodId)->firstOrFail();
    }

    /**
     * @return LengthAwarePaginator<int, TargetRow>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $rows = app(TargetQuery::class)->matrix(
            $this->period,
            $this->scopeFor(Capability::TargetView),
            $this->unit === '' ? null : $this->unit,
            array_key_exists($this->state, TargetQuery::STATES) ? $this->state : null,
            $this->search,
            $this->getPage(),
        );

        // Shows the current draft value in the input until the user types, and
        // remembers which draft the user saw so a save cannot silently
        // overwrite a newer one.
        foreach ($rows as $row) {
            $id = $row->target->public_id;
            $isDraft = $row->working?->status === TargetStatus::Draft;

            if (! isset($this->locks[$id])) {
                $this->locks[$id] = $isDraft ? (int) $row->working?->lock_version : 0;

                if ($isDraft) {
                    $this->values[$id] ??= TargetValueRules::display($row->working?->target_value);
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function completeness(): array
    {
        return app(TargetQuery::class)->completeness($this->period, $this->scopeFor(Capability::TargetView));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        return app(TargetQuery::class)->unitOptions($this->period, $this->scopeFor(Capability::TargetView));
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->period->status->acceptsTargetChanges() && $this->currentUser()->can('manageTargets', $this->period);
    }

    #[Computed]
    public function canApprove(): bool
    {
        return $this->period->status->acceptsTargetChanges() && $this->currentUser()->can('approveTargets', $this->period);
    }

    #[Computed]
    public function afterCutoff(): bool
    {
        return app(TargetService::class)->isAfterCutoff($this->period);
    }

    #[Computed]
    public function historyTarget(): ?PeriodTarget
    {
        return $this->historyOf === null ? null : $this->findTarget($this->historyOf);
    }

    /**
     * @return Collection<int, PeriodTargetVersion>|null
     */
    #[Computed]
    public function history(): ?Collection
    {
        return $this->historyTarget === null ? null : app(TargetQuery::class)->history($this->historyTarget);
    }

    #[Computed]
    public function audit(): ?LengthAwarePaginator
    {
        return $this->historyTarget === null ? null : app(TargetQuery::class)->auditTrail($this->historyTarget, $this->getPage('auditPage'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function actorNames(): array
    {
        $ids = collect($this->audit?->items() ?? [])->pluck('actor_identifier')->filter()->unique()->all();

        return User::query()->whereIn('public_id', $ids)->pluck('name', 'public_id')->all();
    }

    public function saveDrafts(TargetService $targets): void
    {
        $this->authorize('manageTargets', $this->period);
        $this->validate(['values' => ['array'], 'values.*' => ['nullable', 'string', 'max:40'], 'reason' => ['nullable', 'string', 'max:2000']]);

        $entries = [];

        foreach ($this->rows as $row) {
            $typed = trim((string) ($this->values[$row->target->public_id] ?? ''));

            if ($typed !== '' && $row->acceptsDirectEntry()) {
                $entries[] = new TargetEntry($row->target->public_id, $row->target->id, $typed, (string) $row->target->definitionVersion->unit, null, $this->locks[$row->target->public_id] ?? null);
            }
        }

        $result = null;

        $saved = $this->attempt('action', __('Target disimpan sebagai draft.'), function () use ($targets, $entries, &$result): void {
            $result = $this->guarded(fn () => $targets->saveDrafts($this->actorFor(Capability::TargetManage), $this->period->id, $this->scopeFor(Capability::TargetManage), $entries, $this->reason));
        });

        if ($saved && is_array($result)) {
            $this->flash = __('Draft target disimpan: :created baru, :updated diperbarui, :unchanged tidak berubah.', $result);
            $this->refreshState();
        }
    }

    public function submitDrafts(TargetService $targets): void
    {
        $this->authorize('manageTargets', $this->period);
        $count = 0;

        $done = $this->attempt('action', __('Target diajukan.'), function () use ($targets, &$count): void {
            $count = $targets->submit($this->actorFor(Capability::TargetManage), $this->period->id, $this->scopeFor(Capability::TargetManage), $this->filteredTargetIds());
        });

        if ($done) {
            $this->flash = __(':count target diajukan untuk persetujuan.', ['count' => $count]);
            $this->refreshState();
        }
    }

    public function approveSubmitted(TargetService $targets): void
    {
        $this->authorize('approveTargets', $this->period);
        $result = null;

        $done = $this->attempt('action', __('Target disetujui.'), function () use ($targets, &$result): void {
            $result = $targets->approve($this->actorFor(Capability::TargetApprove), $this->period->id, $this->scopeFor(Capability::TargetApprove), $this->filteredTargetIds());
        });

        if ($done && is_array($result)) {
            $this->flash = __(':approved target menjadi final. :skipped dilewati karena Anda pengajunya atau target milik Anda sendiri.', $result);
            $this->refreshState();
        }
    }

    public function startRevision(string $publicId): void
    {
        $target = $this->findTarget($publicId);
        $this->authorize('manage', $target);

        $approved = PeriodTargetVersion::query()->where('period_target_id', $target->id)->where('status', TargetStatus::Approved->value)->orderByDesc('version_number')->first();

        $this->revising = $target->public_id;
        $this->reviseValue = $approved === null ? '' : TargetValueRules::display($approved->target_value);
        $this->reviseReason = '';
        $this->resetErrorBag();
    }

    public function saveRevision(TargetService $targets): void
    {
        $target = $this->findTarget((string) $this->revising);
        $this->authorize('manage', $target);
        $this->validate(['reviseValue' => ['required', 'string', 'max:40'], 'reviseReason' => ['required', 'string', 'max:2000']]);

        $saved = $this->attempt('revision', __('Revisi disimpan sebagai draft. Ajukan dan setujui agar berlaku.'), fn () => $this->guarded(fn () => $targets->saveDrafts(
            $this->actorFor(Capability::TargetManage, $this->access()->unitTarget($target->organization_unit_id)),
            $this->period->id,
            $this->scopeFor(Capability::TargetManage),
            [new TargetEntry($target->public_id, $target->id, $this->reviseValue, (string) $target->definitionVersion->unit)],
            $this->reviseReason,
            true,
        )));

        if ($saved) {
            $this->revising = null;
            $this->refreshState();
        }
    }

    public function cancelRevision(): void
    {
        $this->revising = null;
        $this->rowErrors = [];
        $this->resetErrorBag();
    }

    public function startReturn(string $publicId): void
    {
        $target = $this->findTarget($publicId);
        $this->authorizeReturn($target);

        $this->returning = $target->public_id;
        $this->returningLock = (int) PeriodTargetVersion::query()->where('period_target_id', $target->id)->orderByDesc('version_number')->value('lock_version');
        $this->returnReason = '';
    }

    public function confirmReturn(TargetService $targets): void
    {
        $target = $this->findTarget((string) $this->returning);
        $this->authorizeReturn($target);
        $this->validate(['returnReason' => ['required', 'string', 'max:2000']]);

        $version = PeriodTargetVersion::query()->where('period_target_id', $target->id)->orderByDesc('version_number')->firstOrFail();

        $done = $this->attempt('action', __('Target dikembalikan ke draft.'), fn () => $targets->returnToDraft(
            $this->actorFor($this->currentUser()->can('approve', $target) ? Capability::TargetApprove : Capability::TargetManage, $this->access()->unitTarget($target->organization_unit_id)),
            $version->id,
            $this->returningLock,
            $this->returnReason,
        ));

        if ($done) {
            $this->returning = null;
            $this->refreshState();
        }
    }

    public function showHistory(string $publicId): void
    {
        $target = $this->findTarget($publicId);
        $this->authorize('view', $target);

        $this->historyOf = $target->public_id;
        unset($this->historyTarget, $this->history, $this->audit, $this->actorNames);
    }

    private function findTarget(string $publicId): PeriodTarget
    {
        return PeriodTarget::query()
            ->with('definitionVersion')
            ->where('assessment_period_id', $this->period->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function authorizeReturn(PeriodTarget $target): void
    {
        abort_unless($this->currentUser()->can('approve', $target) || $this->currentUser()->can('manage', $target), 403);
    }

    private function scopeFor(Capability $capability): UnitScope
    {
        return $this->access()->unitScope($this->currentUser(), $capability);
    }

    /**
     * Bulk actions follow the unit filter when one is selected.
     *
     * @return list<int>|null
     */
    private function filteredTargetIds(): ?array
    {
        if ($this->unit === '') {
            return null;
        }

        return array_values(array_map('intval', PeriodTarget::query()
            ->where('assessment_period_id', $this->period->id)
            ->where('unit_code', $this->unit)
            ->pluck('id')
            ->all()));
    }

    /**
     * Keeps the row errors of a rejected batch so the matrix can show them.
     */
    private function guarded(\Closure $operation): mixed
    {
        $this->rowErrors = [];

        try {
            return $operation();
        } catch (TargetEntriesRejected $rejected) {
            $this->rowErrors = $rejected->rowErrors;

            throw $rejected;
        }
    }

    private function refreshState(): void
    {
        $this->reset('values', 'reason', 'locks');
        unset($this->rows, $this->completeness, $this->history, $this->audit, $this->actorNames);
    }
}; ?>

@php
    $period = $this->period;
    $completeness = $this->completeness;
    $unitLabel = fn (?string $unit): string => Vocabulary::label(Vocabulary::UNITS, $unit);
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Target').' — '.$period->name" :description="$period->code.' · '.$period->starts_on.' — '.$period->ends_on.' · '.$period->status->label()">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('periods.index')" wire:navigate>{{ __('Kembali ke daftar periode') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.periods.partials.nav', ['period' => $period])

    @include('pages.administration.partials.feedback')

    @if ($period->status === PeriodStatus::Draft)
        <x-ui.alert variant="info" :title="__('Periode masih draft')">
            {{ __('Daftar target dibuat dari snapshot penugasan saat periode dibuka.') }}
        </x-ui.alert>
    @else
        <section class="flex flex-col gap-3" aria-labelledby="completeness-heading">
            <h2 id="completeness-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Kelengkapan target') }}</h2>
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __(':final dari :required target wajib sudah final.', ['final' => $completeness['final'], 'required' => $completeness['required']]) }}
                {{ __('Menunggu persetujuan: :submitted · Draft: :draft · Belum diisi: :missing · Revisi terbuka: :revisions · Target opsional final: :optional_final dari :optional', ['submitted' => $completeness['submitted'], 'draft' => $completeness['draft'], 'missing' => $completeness['missing'], 'revisions' => $completeness['revisions_open'], 'optional_final' => $completeness['optional_final'], 'optional' => $completeness['optional']]) }}
            </p>

            <x-ui.table-shell :caption="__('Kelengkapan target wajib per unit')" :columns="[__('Unit'), __('Target wajib'), __('Final'), __('Belum final')]">
                @foreach ($completeness['units'] as $unitRow)
                    <tr wire:key="complete-{{ $unitRow['code'] }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $unitRow['code'] }} — {{ $unitRow['name'] }}</th>
                        <td class="px-4 py-3">{{ $unitRow['required'] }}</td>
                        <td class="px-4 py-3">{{ $unitRow['final'] }}</td>
                        <td class="px-4 py-3">{{ $unitRow['required'] - $unitRow['final'] }}</td>
                    </tr>
                @endforeach
                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada target wajib dalam scope Anda')" />
                </x-slot:empty>
            </x-ui.table-shell>
        </section>

        @if (! $period->status->acceptsTargetChanges())
            <x-ui.alert variant="warning" :title="__('Target periode ini tidak dapat diubah lagi')">
                {{ __('Perubahan setelah tahap pengisian aktual memerlukan proses koreksi.') }}
            </x-ui.alert>
        @elseif ($this->afterCutoff)
            <x-ui.alert variant="warning" :title="__('Batas waktu penetapan target sudah lewat')">
                {{ __('Setiap perubahan wajib disertai alasan dan ditandai sebagai perubahan setelah cut-off.') }}
            </x-ui.alert>
        @endif

        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter target') }}">
            <x-ui.input name="search" type="search" :label="__('Cari KPI, NIK, atau nama')" wire:model.live.debounce.400ms="search" />
            <x-ui.select name="unit" :label="__('Unit')" :placeholder="__('Semua unit')" :options="$this->unitOptions" wire:model.live="unit" />
            <x-ui.select name="state" :label="__('Status target')" :placeholder="__('Semua status')" :options="TargetQuery::STATES" wire:model.live="state" />
        </form>

        <form wire:submit="saveDrafts" class="flex flex-col gap-4">
            <x-ui.table-shell :caption="__('Matriks target')" :columns="[__('Subjek'), __('KPI'), __('Satuan'), __('Target final'), __('Nilai yang dikerjakan'), __('Status'), __('Tindakan')]">
                @foreach ($this->rows as $row)
                    @php($id = $row->target->public_id)
                    <tr wire:key="target-{{ $id }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">
                            {{ $row->target->subject_name }}
                            <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $row->target->subject_type->label() }} · {{ $row->target->subject_code }} · {{ $row->target->unit_code }}</span>
                        </th>
                        <td class="px-4 py-3">
                            {{ $row->target->kpi_name }}
                            <span class="block font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $row->target->kpi_code }} v{{ $row->target->definitionVersion->version_number }}{{ $row->target->is_required ? '' : ' · '.__('opsional') }}</span>
                            @if ($row->target->definitionVersion->target_rule)
                                <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Aturan target') }}: {{ $row->target->definitionVersion->target_rule }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $unitLabel($row->target->definitionVersion->unit) }}</td>
                        <td class="px-4 py-3">
                            {{ $row->approved ? TargetValueRules::display($row->approved->target_value).' (v'.$row->approved->version_number.')' : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($this->canManage && $row->acceptsDirectEntry())
                                <label class="sr-only" for="value-{{ $id }}">{{ __('Target :kpi untuk :subject', ['kpi' => $row->target->kpi_code, 'subject' => $row->target->subject_code]) }}</label>
                                <input
                                    id="value-{{ $id }}"
                                    type="text"
                                    inputmode="decimal"
                                    wire:model="values.{{ $id }}"
                                    @if (isset($rowErrors[$id])) aria-invalid="true" aria-describedby="value-{{ $id }}-error" @endif
                                    class="w-32 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                >
                            @else
                                {{ $row->working ? TargetValueRules::display($row->working->target_value).' (v'.$row->working->version_number.')' : '—' }}
                            @endif

                            @if (isset($rowErrors[$id]))
                                <p id="value-{{ $id }}-error" class="mt-1 text-xs text-danger-700 dark:text-danger-300">{{ implode(' ', $rowErrors[$id]) }}</p>
                            @endif

                            @if ($row->working?->change_reason)
                                <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Alasan') }}: {{ $row->working->change_reason }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @include('pages.periods.partials.target-state', ['state' => $row->state()])
                            @if ($row->working?->after_cutoff)
                                <x-ui.status-badge status="warning">{{ __('Setelah cut-off') }}</x-ui.status-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                @if ($this->canManage && $row->approved !== null && $row->working === null)
                                    <x-ui.button variant="ghost" wire:click="startRevision('{{ $id }}')">{{ __('Revisi') }}<span class="sr-only"> {{ $row->target->kpi_code }} {{ $row->target->subject_code }}</span></x-ui.button>
                                @endif
                                @if (($this->canManage || $this->canApprove) && $row->working?->status === TargetStatus::Submitted)
                                    <x-ui.button variant="ghost" wire:click="startReturn('{{ $id }}')">{{ __('Kembalikan') }}<span class="sr-only"> {{ $row->target->kpi_code }} {{ $row->target->subject_code }}</span></x-ui.button>
                                @endif
                                <x-ui.button variant="ghost" wire:click="showHistory('{{ $id }}')">{{ __('Riwayat') }}<span class="sr-only"> {{ $row->target->kpi_code }} {{ $row->target->subject_code }}</span></x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada target')" :description="__('Tidak ada target yang cocok dengan filter dalam scope Anda.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            {{ $this->rows->links() }}

            @if ($this->canManage)
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.textarea name="reason" :label="$this->afterCutoff ? __('Alasan perubahan (wajib setelah cut-off)') : __('Alasan perubahan (opsional)')" :required="$this->afterCutoff" wire:model="reason" />
                    <div class="flex flex-wrap items-end gap-2">
                        <x-ui.button type="submit">{{ __('Simpan draft halaman ini') }}</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="submitDrafts" wire:confirm="{{ __('Ajukan semua target draft dalam scope (atau unit yang difilter) untuk persetujuan?') }}">{{ __('Ajukan draft') }}</x-ui.button>
                    </div>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Satu kali simpan berlaku untuk seluruh baris di halaman ini: bila satu nilai ditolak, tidak ada nilai yang disimpan.') }}</p>
            @endif
        </form>

        @if ($this->canApprove)
            <x-ui.panel :title="__('Persetujuan target')" :description="__('Target yang Anda ajukan sendiri, dan target milik Anda sebagai pegawai, harus disetujui orang lain. Jalur persetujuan target per team menunggu DEC-002.')">
                <x-ui.button wire:click="approveSubmitted" wire:confirm="{{ __('Setujui semua target yang menunggu persetujuan dalam scope (atau unit yang difilter)? Target final tidak dapat ditimpa.') }}">{{ __('Setujui target yang diajukan') }}</x-ui.button>
            </x-ui.panel>
        @endif

        @if ($revising !== null)
            <x-ui.panel :title="__('Revisi target final')" :description="__('Target final tidak diubah. Revisi membuat versi baru yang harus diajukan dan disetujui lagi; versi lama tetap tersimpan.')">
                @include('pages.administration.partials.feedback', ['flash' => null, 'keys' => ['revision']])
                @if (isset($rowErrors[$revising]))
                    <x-ui.alert variant="danger" :title="implode(' ', $rowErrors[$revising])" />
                @endif
                <form wire:submit="saveRevision" class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="reviseValue" :label="__('Nilai target baru')" required wire:model="reviseValue" />
                    <x-ui.textarea name="reviseReason" :label="__('Alasan revisi')" required wire:model="reviseReason" />
                    <div class="flex gap-2 sm:col-span-2">
                        <x-ui.button type="submit">{{ __('Simpan revisi') }}</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="cancelRevision">{{ __('Batal') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.panel>
        @endif

        @if ($returning !== null)
            <x-ui.panel :title="__('Kembalikan target ke draft')">
                <form wire:submit="confirmReturn" class="grid gap-4 sm:grid-cols-2">
                    <x-ui.textarea name="returnReason" :label="__('Alasan pengembalian')" required wire:model="returnReason" />
                    <div class="self-end"><x-ui.button type="submit" variant="secondary">{{ __('Kembalikan ke draft') }}</x-ui.button></div>
                </form>
            </x-ui.panel>
        @endif

        @if ($this->history !== null && $this->historyTarget !== null)
            <section class="flex flex-col gap-4" aria-labelledby="history-heading">
                <h2 id="history-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ __('Riwayat target') }}: {{ $this->historyTarget->kpi_code }} · {{ $this->historyTarget->subject_code }}
                </h2>

                <x-ui.table-shell :caption="__('Versi target')" :columns="[__('Versi'), __('Nilai'), __('Status'), __('Sumber'), __('Dibuat'), __('Diajukan'), __('Disetujui'), __('Alasan')]">
                    @foreach ($this->history as $version)
                        <tr wire:key="tversion-{{ $version->public_id }}">
                            <td class="px-4 py-3">v{{ $version->version_number }}</td>
                            <td class="px-4 py-3">{{ TargetValueRules::display($version->target_value) }} {{ $unitLabel($version->unit) }}</td>
                            <td class="px-4 py-3">
                                {{ $version->status->label() }}
                                @if ($version->after_cutoff)
                                    <span class="block text-xs">{{ __('Setelah cut-off') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $version->source === 'import' ? __('Import') : __('Manual') }}{{ $version->source_note ? ' · '.$version->source_note : '' }}</td>
                            <td class="px-4 py-3">{{ $version->createdBy?->name ?? __('Sistem') }}</td>
                            <td class="px-4 py-3">{{ $version->submittedBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $version->approvedBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $version->change_reason ?? $version->return_reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                    <x-slot:empty>
                        <x-ui.empty-state :title="__('Belum ada nilai target')" />
                    </x-slot:empty>
                </x-ui.table-shell>

                @if ($this->audit !== null)
                    @include('pages.kpi.partials.audit-trail', ['entries' => $this->audit, 'actorNames' => $this->actorNames])
                @endif
            </section>
        @endif
    @endif
</div>
