<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Production\Application\ProductionAccess;
use App\Modules\Production\Application\ProductionImportService;
use App\Modules\Production\Application\ProductionQuery;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\LocationCategory;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Produksi')] class extends GuardedPage {
    use WithFileUploads;

    #[Url(as: 'batch')]
    public string $selected = '';

    public string $loadTarget = '';

    public string $kind = '';

    public string $sourceSystem = '';

    public string $coverageDate = '';

    public string $extractedAt = '';

    public string $controlQuantity = '';

    public string $controlReject = '';

    public string $controlRework = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    #[Locked]
    public string $rejectId = '';

    public string $rejectReason = '';

    public function mount(): void
    {
        Gate::authorize('production.view');
        $this->coverageDate = $this->businessToday();
    }

    /**
     * Batches of the teams this account may read, newest first. Every staged
     * batch stays listed until it is committed or rejected.
     *
     * @return Collection<int, ProductionBatch>
     */
    #[Computed]
    public function batches(): Collection
    {
        $query = ProductionBatch::query()->with(['unit', 'stagedBy']);
        app(ProductionAccess::class)->restrict($query, $this->currentUser());
        $recent = ProductionBatch::query()->where('status', '!=', ProductionBatch::STAGED);
        app(ProductionAccess::class)->restrict($recent, $this->currentUser());
        $recent->orderByDesc('coverage_date')->orderByDesc('id')->limit(30)->select('id');

        return $query->where(fn ($pending) => $pending->where('status', ProductionBatch::STAGED)->orWhereIn('id', $recent))
            ->orderByDesc('coverage_date')->orderByDesc('id')->get();
    }

    /**
     * @return list<array{unit: string, kind: BatchKind, batch: ProductionBatch|null, staged: int}>
     */
    #[Computed]
    public function freshness(): array
    {
        return ProductionQuery::freshness($this->currentUser());
    }

    /**
     * Teams and kinds this account loads, as "TEAM|kind" => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function loadable(): array
    {
        $access = app(ProductionAccess::class);
        $options = [];

        foreach (app(OrganizationDirectory::class)->snapshot($this->businessToday())->all() as $node) {
            foreach ($access->kindsFor($node->id) as $kind) {
                if ($access->canManage($this->currentUser(), $node->id, $kind)) {
                    $options["{$node->code}|{$kind->value}"] = "{$node->code} — {$kind->label()}";
                }
            }
        }

        return $options;
    }

    #[Computed]
    public function calendarLabel(): string
    {
        try {
            $calendar = ProductionQuery::calendars()->forDate($this->businessToday());
        } catch (\InvalidArgumentException $exception) {
            return __('Konfigurasi kalender shift tidak valid: :message', ['message' => $exception->getMessage()]);
        }

        return $calendar === null
            ? __('Belum ada kalender shift yang disetujui (DEC-015): aktual produksi dan packing belum dapat diimpor.')
            : __('Kalender shift :version (:label), berlaku sejak :from; shift lewat tengah malam dihitung pada tanggal :anchor.', ['version' => $calendar->version, 'label' => $calendar->label, 'from' => $calendar->effectiveFrom, 'anchor' => $calendar->overnightAnchor === 'start' ? __('mulai') : __('selesai')]);
    }

    #[Computed]
    public function current(): ?ProductionBatch
    {
        return $this->batches->first(fn (ProductionBatch $batch): bool => $batch->public_id === $this->selected && $batch->status === ProductionBatch::COMMITTED);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function reconciliation(): ?array
    {
        $batch = $this->current;

        if ($batch === null) {
            return null;
        }

        return [
            'totals' => ProductionQuery::totals($batch),
            'breakdown' => $batch->kind === BatchKind::Production ? ProductionQuery::breakdown($batch) : null,
            'categories' => $batch->kind === BatchKind::Warehouse ? ProductionQuery::stockByCategory($batch) : null,
        ];
    }

    public function stage(ProductionImportService $imports): void
    {
        Gate::authorize('production.view');
        abort_unless(array_key_exists($this->loadTarget, $this->loadable), 403);
        $this->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx']], [], ['file' => __('file batch')]);

        [$unitCode, $kindValue] = explode('|', $this->loadTarget);
        $kind = BatchKind::from($kindValue);
        $unitId = collect(app(OrganizationDirectory::class)->snapshot($this->businessToday())->all())->firstWhere('code', $unitCode)?->id;
        abort_if($unitId === null, 403);
        $file = $this->file;
        abort_unless($file instanceof TemporaryUploadedFile, 422);

        // The upload may sit on a remote temporary disk; the reader needs a
        // local file, which is removed again right after staging.
        $path = (string) tempnam(sys_get_temp_dir(), 'kpi-production-import-');
        $batch = null;

        try {
            file_put_contents($path, (string) $file->get());
            $this->attempt('action', __('File divalidasi dan disimpan sebagai batch staged. Belum ada yang berlaku sebelum di-commit.'), function () use ($imports, $file, $path, $unitId, $kind, &$batch): void {
                $batch = $imports->stage(
                    $this->selfActor(), $this->currentUser(), $unitId, $kind, $this->sourceSystem, $this->coverageDate, $this->extractedAt, $file->getClientOriginalName(), $path,
                    strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv',
                    ['quantity' => $this->controlQuantity, 'reject' => $this->controlReject, 'rework' => $this->controlRework],
                );
            });
        } finally {
            @unlink($path);
            $file->delete();
            $this->reset('file');
        }

        if ($batch instanceof ProductionBatch && ! $batch->wasRecentlyCreated) {
            $this->flash = __('File yang sama sudah diimpor sebagai batch versi :version (:status). Tidak ada yang berubah.', ['version' => $batch->version, 'status' => $batch->status]);
        }

        unset($this->batches, $this->freshness);
    }

    public function commit(string $batchId, ProductionImportService $imports): void
    {
        Gate::authorize('production.view');
        $batch = $this->visible($batchId);

        $this->attempt('action', __('Batch di-commit dan menjadi versi berlaku untuk tanggalnya.'), function () use ($imports, $batch): void {
            $imports->commit($this->selfActor(), $this->currentUser(), $batch->id);
        });

        unset($this->batches, $this->freshness);
    }

    public function startReject(string $batchId): void
    {
        Gate::authorize('production.view');
        $this->visible($batchId);
        $this->rejectId = $batchId;
        $this->reset('rejectReason');
    }

    public function reject(ProductionImportService $imports): void
    {
        Gate::authorize('production.view');
        $batch = $this->visible($this->rejectId);

        $done = $this->attempt('action', __('Batch ditolak.'), function () use ($imports, $batch): void {
            $imports->reject($this->selfActor(), $this->currentUser(), $batch->id, $this->rejectReason);
        });

        if ($done) {
            $this->reset('rejectId', 'rejectReason');
        }

        unset($this->batches, $this->freshness);
    }

    /**
     * A batch outside the account's scope does not exist for it.
     */
    private function visible(string $batchId): ProductionBatch
    {
        $query = ProductionBatch::query()->where('public_id', $batchId);
        app(ProductionAccess::class)->restrict($query, $this->currentUser());

        return $query->firstOrFail();
    }
}; ?>

@php
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
    $tone = fn (string $status): string => match ($status) { 'committed' => 'success', 'rejected' => 'neutral', default => 'info' };
    $statusLabel = ['staged' => __('Staged'), 'committed' => __('Committed'), 'rejected' => __('Ditolak')];
    $local = fn ($moment): string => $moment === null ? '—' : app(\App\Modules\Shared\Application\Time\BusinessTime::class)->toBusiness($moment)->format('Y-m-d H:i');
    $access = app(ProductionAccess::class);
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Produksi, Warehouse, dan Logistik')"
        :description="__('Batch data dari sistem sumber per team: aktual produksi, packing, pengiriman, stok warehouse, dan work order PPIC. Sistem KPI bukan MES, WMS, atau ERP: data hanya dibaca dan tidak pernah mengubah sistem sumber.')"
    >
        <x-slot:actions>
            @can('ppic.view')
                <x-ui.button variant="secondary" :href="route('ppic.index')" wire:navigate>{{ __('Board PPIC') }}</x-ui.button>
            @endcan
            <x-ui.button variant="secondary" :href="route('scorecards.index')" wire:navigate>{{ __('Scorecard') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'file']])

    <x-ui.alert variant="warning" :title="__('Formula KPI produksi belum disetujui')">
        {{ __('Output per jam, yield, first pass yield, reject rate, utilization, OEE, downtime rate, setup/changeover, schedule attainment, dan produktivitas menunggu formula, denominator, planned downtime, dan konversi satuan yang disetujui. Halaman ini hanya menampilkan jumlah per satuan dan rekonsiliasi. Operator tidak dinilai individual.') }}
    </x-ui.alert>

    <x-ui.alert variant="info" :title="__('Kalender shift')">{{ $this->calendarLabel }}</x-ui.alert>

    <x-ui.table-shell :caption="__('Kesegaran data per team dan jenis data')" :columns="[__('Team'), __('Jenis data'), __('Data berlaku'), __('Diekstrak dari sumber'), __('Di-commit'), __('Reason code kosong'), __('Menunggu commit')]">
        @foreach ($this->freshness as $row)
            <tr wire:key="fresh-{{ md5($row['unit'].$row['kind']->value) }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $row['unit'] }}</th>
                <td class="px-4 py-3">{{ $row['kind']->label() }}</td>
                @if ($row['batch'] === null)
                    <td class="px-4 py-3" colspan="4"><x-ui.status-badge status="warning">{{ __('Belum ada data') }}</x-ui.status-badge></td>
                @else
                    <td class="px-4 py-3 whitespace-nowrap">{{ $row['kind']->coverageLabel() }} {{ $row['batch']->coverage_date }} · v{{ $row['batch']->version }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $local($row['batch']->source_extracted_at) }} <span class="block text-xs">{{ $row['batch']->source_extracted_at->diffForHumans() }}</span></td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $local($row['batch']->committed_at) }}</td>
                    <td class="px-4 py-3">{{ $row['batch']->missing_reason_count }}</td>
                @endif
                <td class="px-4 py-3">{{ $row['staged'] }}</td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada team produksi dalam scope Anda')" />
        </x-slot:empty>
    </x-ui.table-shell>

    @if ($this->loadable !== [])
        <x-ui.panel :title="__('Impor batch')" :description="__('File CSV atau XLSX sesuai kontrak jenis datanya. Waktu dalam zona waktu bisnis; shift dan tanggal operasional ditentukan kalender shift. File baru untuk tanggal yang sama menjadi versi berikutnya; versi lama tetap ada.')">
            <form wire:submit="stage" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="loadTarget" :label="__('Team dan jenis data')" :placeholder="__('Pilih')" :options="$this->loadable" required wire:model="loadTarget" />
                <x-ui.input name="sourceSystem" :label="__('Kode sistem sumber')" :hint="__('Contoh: mes-woven')" required wire:model="sourceSystem" />
                <x-ui.input name="coverageDate" type="date" :label="__('Tanggal operasional, kirim, atau as-of')" required wire:model="coverageDate" />
                <x-ui.input name="extractedAt" type="datetime-local" :label="__('Waktu ekstraksi dari sistem sumber')" required wire:model="extractedAt" />
                <x-ui.input name="file" type="file" :label="__('File batch')" accept=".csv,.xlsx" required wire:model="file" />
                <x-ui.input name="controlQuantity" :label="__('Total kontrol kuantitas utama (opsional)')" inputmode="decimal" wire:model="controlQuantity" />
                <x-ui.input name="controlReject" :label="__('Total kontrol reject (opsional, produksi)')" inputmode="decimal" wire:model="controlReject" />
                <x-ui.input name="controlRework" :label="__('Total kontrol rework (opsional, produksi)')" inputmode="decimal" wire:model="controlRework" />
                <div class="sm:col-span-2"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="stage,file">{{ __('Validasi dan simpan sebagai staged') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.table-shell :caption="__('Batch dalam scope Anda')" :columns="[__('Tanggal'), __('Team'), __('Jenis'), __('Sumber'), __('Versi'), __('Status'), __('Baris'), __('Masalah'), __('Aksi')]">
        @foreach ($this->batches as $batch)
            <tr wire:key="batch-{{ $batch->public_id }}">
                <td class="px-4 py-3 whitespace-nowrap">{{ $batch->coverage_date }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $batch->unit->code }}</td>
                <td class="px-4 py-3">{{ $batch->kind->label() }}</td>
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $batch->source_system }} <span class="block text-xs font-normal">{{ $batch->original_name }} · {{ __('diekstrak') }} {{ $local($batch->source_extracted_at) }}</span></th>
                <td class="px-4 py-3">v{{ $batch->version }}</td>
                <td class="px-4 py-3"><x-ui.status-badge :status="$tone($batch->status)">{{ $statusLabel[$batch->status] ?? $batch->status }}</x-ui.status-badge></td>
                <td class="px-4 py-3">{{ $batch->row_count }}</td>
                <td class="px-4 py-3">
                    @if ($batch->error_count > 0)
                        <details class="text-xs"><summary>{{ __(':n baris bermasalah', ['n' => $batch->error_count]) }}</summary>
                            <ul class="mt-1 list-disc ps-4">
                                @foreach ($batch->errors ?? [] as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @elseif ($batch->missing_reason_count > 0)
                        <span class="text-xs">{{ __(':n baris tanpa reason code', ['n' => $batch->missing_reason_count]) }}</span>
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if ($batch->status === 'committed')
                        <a href="{{ route('production.index', ['batch' => $batch->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Rekonsiliasi') }}<span class="sr-only"> {{ $batch->coverage_date }}</span></a>
                    @elseif ($batch->status === 'staged' && $access->canManage(auth()->user(), $batch->organization_unit_id, $batch->kind))
                        <div class="flex flex-wrap gap-2">
                            @if ($batch->error_count === 0)
                                <x-ui.button wire:click="commit('{{ $batch->public_id }}')">{{ __('Commit') }}<span class="sr-only"> {{ $batch->coverage_date }}</span></x-ui.button>
                            @endif
                            <x-ui.button variant="secondary" wire:click="startReject('{{ $batch->public_id }}')">{{ __('Tolak') }}<span class="sr-only"> {{ $batch->coverage_date }}</span></x-ui.button>
                        </div>
                    @else
                        —
                    @endif
                </td>
            </tr>

            @if ($rejectId === $batch->public_id)
                <tr wire:key="reject-{{ $batch->public_id }}">
                    <td colspan="9" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                        <form wire:submit="reject" class="grid gap-4">
                            <x-ui.textarea name="rejectReason" :label="__('Alasan penolakan')" :hint="__('Masuk ke audit log. Jangan tulis nama orang atau data customer.')" required wire:model="rejectReason" />
                            <div><x-ui.button type="submit" variant="danger">{{ __('Tolak batch') }}</x-ui.button></div>
                        </form>
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada batch')" :description="__('Belum ada konektor ke MES, WMS, atau ERP; batch diimpor sebagai file sesuai kontrak.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    @if ($this->current !== null)
        @php
            $batch = $this->current;
            $totals = $this->reconciliation['totals'];
            $breakdown = $this->reconciliation['breakdown'];
        @endphp

        <x-ui.panel :title="__('Rekonsiliasi :kind :team :date v:version', ['kind' => $batch->kind->label(), 'team' => $batch->unit->code, 'date' => $batch->coverage_date, 'version' => $batch->version])" :description="__('Jumlah per satuan dari baris batch. Satuan berbeda tidak pernah dijumlahkan.')">
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                @foreach (['quantity' => __('Kuantitas utama'), 'reject' => __('Reject'), 'rework' => __('Rework / repack'), 'waste' => __('Waste')] as $key => $label)
                    @if ($totals[$key] !== [])
                        <div><dt class="font-medium">{{ $label }}</dt><dd>@foreach ($totals[$key] as $unit => $value){{ $value }} {{ $unit }}@if (! $loop->last) · @endif @endforeach</dd></div>
                    @endif
                @endforeach
                @if ($totals['downtime_minutes'] > 0)
                    <div><dt class="font-medium">{{ __('Downtime') }}</dt><dd>{{ __(':n menit', ['n' => $totals['downtime_minutes']]) }}</dd></div>
                @endif
                @if ($batch->control_quantity_total !== null)
                    <div><dt class="font-medium">{{ __('Total kontrol sumber') }}</dt><dd>{{ $batch->control_quantity_total }} · {{ $batch->control_reject_total ?? '—' }} · {{ $batch->control_rework_total ?? '—' }} <x-ui.status-badge status="success">{{ __('Cocok saat commit') }}</x-ui.status-badge></dd></div>
                @endif
            </dl>
        </x-ui.panel>

        @if ($breakdown !== null)
            <x-ui.table-shell :caption="__('Output per shift, mesin, dan satuan')" :columns="[__('Shift'), __('Mesin'), __('Satuan'), __('Good'), __('Reject'), __('Rework'), __('Downtime (menit)')]">
                @foreach ($breakdown['rows'] as $row)
                    <tr wire:key="breakdown-{{ md5($row['shift'].$row['machine'].$row['unit']) }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $row['shift'] ?: '—' }}</th>
                        <td class="px-4 py-3">{{ $row['machine'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $row['unit'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $row['good'] }}</td>
                        <td class="px-4 py-3">{{ $row['reject'] }}</td>
                        <td class="px-4 py-3">{{ $row['rework'] }}</td>
                        <td class="px-4 py-3">{{ $row['downtime_minutes'] }}</td>
                    </tr>
                @endforeach
            </x-ui.table-shell>
            <p class="text-sm"><x-ui.status-badge :status="$breakdown['reconciles'] ? 'success' : 'danger'">{{ $breakdown['reconciles'] ? __('Rincian cocok dengan total batch') : __('Rincian TIDAK cocok dengan total batch') }}</x-ui.status-badge></p>
        @endif

        @if ($this->reconciliation['categories'] !== null)
            <x-ui.table-shell :caption="__('Stok per kategori lokasi (referensi, bukan ledger)')" :columns="[__('Kategori'), __('Baris'), __('Jumlah per satuan')]">
                @foreach (LocationCategory::cases() as $category)
                    @php($stock = $this->reconciliation['categories'][$category->value] ?? ['rows' => 0, 'quantity' => []])
                    <tr wire:key="category-{{ $category->value }}">
                        <th scope="row" class="px-4 py-3 text-start font-medium">{{ $category->label() }}</th>
                        <td class="px-4 py-3">{{ $stock['rows'] }}</td>
                        <td class="px-4 py-3">@foreach ($stock['quantity'] as $unit => $value){{ $value }} {{ $unit }}@if (! $loop->last) · @endif @endforeach</td>
                    </tr>
                @endforeach
            </x-ui.table-shell>
        @endif
    @endif
</div>
