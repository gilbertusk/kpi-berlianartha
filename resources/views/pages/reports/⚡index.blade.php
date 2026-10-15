<?php

use App\Modules\Accounting\Application\ArAccess;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Reporting\Application\ExportService;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\ExportStatus;
use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportExport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Laporan')] class extends GuardedPage {
    #[Url(as: 'laporan')]
    public string $report = '';

    public string $period = '';

    public string $snapshot = '';

    public string $unit = '';

    public string $level = '';

    public string $employee = '';

    public string $kpi = '';

    public string $status = '';

    public string $source = '';

    public string $entity = '';

    public string $from = '';

    public string $to = '';

    public bool $redOnly = false;

    /** Set once the account asked for a preview of the current filters. */
    public bool $previewing = false;

    public function mount(): void
    {
        Gate::authorize('reports.view');
    }

    public function updated(string $property): void
    {
        // A changed report or filter invalidates what is on screen.
        if ($property !== 'previewing') {
            $this->previewing = false;
            $this->resetErrorBag();
            unset($this->preview);
        }
    }

    /**
     * @return list<ReportDefinition>
     */
    #[Computed]
    public function reports(): array
    {
        return app(ReportCatalog::class)->availableTo($this->currentUser());
    }

    #[Computed]
    public function definition(): ?ReportDefinition
    {
        foreach ($this->reports as $definition) {
            if ($definition->key() === $this->report) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Periods past target setting; whether one is inside the account's scope
     * for the chosen report is decided when the report runs.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function periodOptions(): array
    {
        return AssessmentPeriod::query()
            ->whereNotIn('status', [PeriodStatus::Draft->value, PeriodStatus::Open->value])
            ->orderByDesc('starts_on')
            ->limit(36)
            ->get()
            ->filter(fn (AssessmentPeriod $period): bool => $this->currentUser()->can('view', $period) || $this->currentUser()->can('viewScorecards', $period))
            ->mapWithKeys(fn (AssessmentPeriod $period): array => [$period->public_id => "{$period->code} — {$period->name} ({$period->status->label()})"])
            ->all();
    }

    /**
     * First rows of the report with the current filters, or null.
     *
     * @return array{rows: list<list<string|null>>, more: bool}|null
     */
    #[Computed]
    public function preview(): ?array
    {
        if (! $this->previewing || $this->definition === null) {
            return null;
        }

        [$definition, $context] = app(ReportCatalog::class)->authorize($this->currentUser(), $this->report, ReportFilters::fromArray($this->filterInput()));
        $limit = (int) config('reports.preview_rows', 50);
        $rows = [];
        $more = false;

        foreach ($definition->rows($context) as $row) {
            if (count($rows) >= $limit) {
                $more = true;

                break;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'more' => $more];
    }

    /**
     * Committed receivables snapshots the account may read.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function snapshotOptions(): array
    {
        $query = ArSnapshot::query()->with('unit')->where('status', ArSnapshot::COMMITTED);
        app(ArAccess::class)->restrict($query, $this->currentUser());

        return $query->orderByDesc('as_of_date')->orderByDesc('id')->limit(36)->get()
            ->mapWithKeys(fn (ArSnapshot $snapshot): array => [$snapshot->public_id => "{$snapshot->unit->code} · {$snapshot->as_of_date} · {$snapshot->source_system} v{$snapshot->version}"])
            ->all();
    }

    /**
     * @return Collection<int, ReportExport>
     */
    #[Computed]
    public function exports(): Collection
    {
        return ReportExport::query()->where('user_id', $this->currentUser()->id)->orderByDesc('id')->limit(15)->get();
    }

    public function show(): void
    {
        Gate::authorize('reports.view');
        $this->previewing = false;
        unset($this->preview);

        $this->attempt('action', '', function (): void {
            app(ReportCatalog::class)->authorize($this->currentUser(), $this->report, ReportFilters::fromArray($this->filterInput()));
            $this->previewing = true;
        });
    }

    public function export(string $format, ExportService $exports): void
    {
        Gate::authorize('reports.view');

        $done = $this->attempt('action', __('Ekspor masuk antrean. Statusnya tampil pada daftar ekspor di bawah.'), function () use ($exports, $format): void {
            $exports->request($this->selfActor(), $this->currentUser(), $this->report, $format, $this->filterInput());
        });

        if ($done) {
            unset($this->exports);
        }
    }

    public function retry(string $exportId, ExportService $exports): void
    {
        Gate::authorize('reports.view');
        $export = $this->own($exportId);

        $this->attempt('action', __('Ekspor dimasukkan lagi ke antrean.'), function () use ($exports, $export): void {
            $exports->retry($this->selfActor(), $this->currentUser(), $export->id);
        });

        unset($this->exports);
    }

    public function download(string $exportId, ExportService $exports): void
    {
        Gate::authorize('reports.view');
        $export = $this->own($exportId);
        $link = null;

        $done = $this->attempt('action', '', function () use ($exports, $export, &$link): void {
            $link = $exports->downloadLink($this->currentUser(), $export);
        });

        if ($done && is_string($link)) {
            $this->redirect($link);
        }
    }

    public function refreshExports(): void
    {
        Gate::authorize('reports.view');
        unset($this->exports);
    }

    /**
     * Only the filters the chosen report offers are sent on.
     *
     * @return array<string, bool|string>
     */
    private function filterInput(): array
    {
        $offered = $this->definition?->filters() ?? [];
        $input = [];

        foreach (['period', 'snapshot', 'unit', 'level', 'employee', 'kpi', 'status', 'source', 'entity', 'from', 'to'] as $field) {
            if (in_array($field, $offered, true) && $this->{$field} !== '') {
                $input[$field] = $this->{$field};
            }
        }

        if (in_array('red_only', $offered, true) && $this->redOnly) {
            $input['red_only'] = true;
        }

        return $input;
    }

    /**
     * An export of another account does not exist for this one.
     */
    private function own(string $exportId): ReportExport
    {
        return ReportExport::query()->where('public_id', $exportId)->where('user_id', $this->currentUser()->id)->firstOrFail();
    }
}; ?>

@php
    $definition = $this->definition;
    $offered = $definition?->filters() ?? [];
    $reportOptions = collect($this->reports)->mapWithKeys(fn ($item) => [$item->key() => $item->title()])->all();
    $inFlight = $this->exports->contains(fn ($export) => $export->status->isInFlight());
    $tone = fn (ExportStatus $status): string => match ($status) {
        ExportStatus::Completed => 'success',
        ExportStatus::Failed => 'danger',
        ExportStatus::Expired => 'neutral',
        default => 'info',
    };
    $zone = config('app.business_timezone', 'Asia/Jakarta');
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Laporan')" :description="__('Laporan dibaca dalam scope akses Anda. Ekspor memuat baris yang sama dengan tampilan, dibuat di latar belakang, dan diunduh lewat tautan sementara yang hanya berlaku untuk akun Anda.')" />

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <form wire:submit="show" class="flex flex-col gap-4" aria-label="{{ __('Pilih laporan dan filter') }}">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select name="report" :label="__('Laporan')" :placeholder="__('Pilih laporan')" :options="$reportOptions" required wire:model.live="report" />
        </div>

        @if ($definition)
            <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $definition->description() }} <span class="font-medium">{{ __('Klasifikasi: :label.', ['label' => $definition->classification()->label()]) }}</span></p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @if (in_array('period', $offered, true))
                    <x-ui.select name="period" :label="__('Periode')" :placeholder="__('Pilih periode')" :options="$this->periodOptions" required wire:model="period" />
                @endif
                @if (in_array('snapshot', $offered, true))
                    <x-ui.select name="snapshot" :label="__('Snapshot piutang')" :placeholder="__('Pilih snapshot')" :options="$this->snapshotOptions" required wire:model="snapshot" />
                @endif
                @if (in_array('unit', $offered, true))
                    <x-ui.input name="unit" :label="__('Kode unit (termasuk unit di bawahnya)')" wire:model="unit" />
                @endif
                @if (in_array('level', $offered, true))
                    <x-ui.select name="level" :label="__('Tingkat scorecard')" :placeholder="__('Semua tingkat')" :options="ReportFilters::LEVELS" wire:model="level" />
                @endif
                @if (in_array('employee', $offered, true))
                    <x-ui.input name="employee" :label="__('NIK karyawan')" wire:model="employee" />
                @endif
                @if (in_array('kpi', $offered, true))
                    <x-ui.input name="kpi" :label="__('Kode KPI')" wire:model="kpi" />
                @endif
                @if (in_array('status', $offered, true))
                    <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="$definition->statuses()" wire:model="status" />
                @endif
                @if (in_array('source', $offered, true))
                    <x-ui.select name="source" :label="__('Sumber data')" :placeholder="__('Semua sumber')" :options="['manual' => __('Manual'), 'import' => __('Import'), 'correction' => __('Koreksi')]" wire:model="source" />
                @endif
                @if (in_array('entity', $offered, true))
                    <x-ui.input name="entity" :label="__('Jenis record (opsional)')" :hint="__('Contoh: scorecard_review, correction_request')" wire:model="entity" />
                @endif
                @if (in_array('from', $offered, true))
                    <x-ui.input name="from" type="date" :label="__('Dari tanggal')" wire:model="from" />
                    <x-ui.input name="to" type="date" :label="__('Sampai tanggal')" wire:model="to" />
                @endif
                @if (in_array('red_only', $offered, true))
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input type="checkbox" wire:model="redOnly" class="size-4" @disabled(ReportSupport::redThreshold() === null)>
                        {{ ReportSupport::redThreshold() === null ? __('Hanya KPI merah (ambang belum disetujui, DEC-004)') : __('Hanya KPI di bawah ambang :n', ['n' => ReportSupport::redThreshold()]) }}
                    </label>
                @endif
            </div>

            <div class="flex flex-wrap gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="show">{{ __('Tampilkan') }}</x-ui.button>
                @foreach (ReportFormat::cases() as $format)
                    <x-ui.button variant="secondary" wire:click="export('{{ $format->value }}')" wire:loading.attr="disabled" wire:target="export">{{ __('Ekspor :format', ['format' => $format->label()]) }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </form>

    @if ($definition && $this->preview !== null)
        <x-ui.table-shell :caption="__('Pratinjau :report', ['report' => $definition->title()])" :columns="$definition->columns()">
            @foreach ($this->preview['rows'] as $row)
                <tr wire:key="row-{{ $loop->index }}">
                    @foreach ($row as $cell)
                        <td class="px-4 py-2 align-top">{{ $cell ?? '—' }}</td>
                    @endforeach
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Tidak ada baris')" :description="__('Tidak ada data yang cocok dengan filter dalam scope Anda.')" />
            </x-slot:empty>
        </x-ui.table-shell>

        @if ($this->preview['more'])
            <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Pratinjau menampilkan :n baris pertama. Ekspor memuat seluruh baris.', ['n' => count($this->preview['rows'])]) }}</p>
        @endif
    @endif

    <section class="flex flex-col gap-3" aria-labelledby="exports-heading" @if ($inFlight) wire:poll.5s="refreshExports" @endif>
        <h2 id="exports-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Ekspor saya') }}</h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('File ekspor disimpan :hours jam lalu dihapus. Ini batas teknis, bukan kebijakan retensi data (DEC-010). Setiap unduhan tercatat di audit.', ['hours' => config('reports.retention_hours')]) }}
        </p>

        <x-ui.table-shell :caption="__('Ekspor yang Anda minta')" :columns="[__('Diminta'), __('Laporan'), __('Format'), __('Filter'), __('Status'), __('Baris'), __('Berlaku sampai'), __('Aksi')]">
            @foreach ($this->exports as $export)
                <tr wire:key="export-{{ $export->public_id }}">
                    <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $export->created_at->setTimezone($zone)->format('Y-m-d H:i') }}</td>
                    <th scope="row" class="px-4 py-3 text-start font-medium">{{ $reportOptions[$export->report_key] ?? $export->report_key }}</th>
                    <td class="px-4 py-3">{{ $export->format->label() }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ collect($export->filters)->except('period')->map(fn ($value, $name) => $name.'='.$value)->implode('; ') ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <x-ui.status-badge :status="$tone($export->status)">{{ $export->status->label() }}</x-ui.status-badge>
                        @if ($export->failureMessage())
                            <span class="mt-1 block text-xs">{{ $export->failureMessage() }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $export->rows_written }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $export->expires_at?->setTimezone($zone)->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($export->isDownloadableAt(now()->toImmutable()))
                            <x-ui.button variant="secondary" wire:click="download('{{ $export->public_id }}')">{{ __('Unduh') }}<span class="sr-only"> {{ $export->file_name }}</span></x-ui.button>
                        @elseif ($export->status === ExportStatus::Failed)
                            <x-ui.button variant="secondary" wire:click="retry('{{ $export->public_id }}')">{{ __('Coba ulang') }}<span class="sr-only"> {{ $export->report_key }}</span></x-ui.button>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Belum ada ekspor')" :description="__('Pilih laporan, atur filter, lalu ekspor ke Excel atau PDF.')" />
            </x-slot:empty>
        </x-ui.table-shell>
    </section>
</div>
