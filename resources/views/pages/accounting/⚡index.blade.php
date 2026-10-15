<?php

use App\Modules\Accounting\Application\ArAccess;
use App\Modules\Accounting\Application\ArImportService;
use App\Modules\Accounting\Application\ArQuery;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Piutang')] class extends GuardedPage {
    use WithFileUploads;

    #[Url(as: 'snapshot')]
    public string $selected = '';

    public string $unit = '';

    public string $sourceSystem = '';

    public string $asOf = '';

    public string $controlInvoiceTotal = '';

    public string $controlAllocationTotal = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    #[Locked]
    public string $rejectId = '';

    public string $rejectReason = '';

    public function mount(): void
    {
        Gate::authorize('accounting.view');
        $this->asOf = $this->businessToday();
    }

    /**
     * Snapshots of the teams this account may read, newest first.
     *
     * @return Collection<int, ArSnapshot>
     */
    #[Computed]
    public function snapshots(): Collection
    {
        $query = ArSnapshot::query()->with(['unit', 'stagedBy', 'committedBy']);
        app(ArAccess::class)->restrict($query, $this->currentUser());

        // Every staged snapshot stays listed until it is committed or rejected.
        return $query->where(fn ($pending) => $pending->where('status', ArSnapshot::STAGED)->orWhereIn('id', ArSnapshot::query()->where('status', '!=', ArSnapshot::STAGED)->orderByDesc('as_of_date')->orderByDesc('id')->limit(30)->select('id')))
            ->orderByDesc('as_of_date')->orderByDesc('id')->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function manageableUnits(): array
    {
        $scope = app(ArAccess::class)->manageScope($this->currentUser());
        $options = [];

        foreach (app(OrganizationDirectory::class)->snapshot($this->businessToday())->all() as $node) {
            if ($scope->coversUnit($node->id)) {
                $options[$node->code] = "{$node->code} — {$node->name}";
            }
        }

        return $options;
    }

    #[Computed]
    public function current(): ?ArSnapshot
    {
        return $this->snapshots->first(fn (ArSnapshot $snapshot): bool => $snapshot->public_id === $this->selected && $snapshot->status === ArSnapshot::COMMITTED);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        return $this->current === null ? null : ArQuery::summary($this->current);
    }

    public function stage(ArImportService $imports): void
    {
        Gate::authorize('accounting.view');
        abort_unless(array_key_exists($this->unit, $this->manageableUnits), 403);
        $this->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx']], [], ['file' => __('file snapshot')]);

        $file = $this->file;
        abort_unless($file instanceof TemporaryUploadedFile, 422);
        $unitId = collect(app(OrganizationDirectory::class)->snapshot($this->businessToday())->all())->firstWhere('code', $this->unit)?->id;
        abort_if($unitId === null, 403);

        // The upload may sit on a remote temporary disk; the reader needs a
        // local file, which is removed again right after staging.
        $path = (string) tempnam(sys_get_temp_dir(), 'kpi-ar-import-');
        $snapshot = null;

        try {
            file_put_contents($path, (string) $file->get());
            $this->attempt('action', __('File divalidasi dan disimpan sebagai snapshot staged. Belum ada yang berlaku sebelum di-commit.'), function () use ($imports, $file, $path, $unitId, &$snapshot): void {
                $snapshot = $imports->stage(
                    $this->selfActor(), $this->currentUser(), $unitId, $this->sourceSystem, $this->asOf, $file->getClientOriginalName(), $path,
                    strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv', $this->controlInvoiceTotal, $this->controlAllocationTotal,
                );
            });
        } finally {
            @unlink($path);
        }

        if ($snapshot instanceof ArSnapshot && ! $snapshot->wasRecentlyCreated) {
            $this->flash = __('File yang sama sudah diimpor sebagai snapshot versi :version (:status). Tidak ada yang berubah.', ['version' => $snapshot->version, 'status' => $snapshot->status]);
        }

        $file->delete();
        $this->reset('file');
        unset($this->snapshots);
    }

    public function commit(string $snapshotId, ArImportService $imports): void
    {
        Gate::authorize('accounting.view');
        $snapshot = $this->visible($snapshotId);

        $this->attempt('action', __('Snapshot di-commit dan berlaku untuk laporan.'), function () use ($imports, $snapshot): void {
            $imports->commit($this->selfActor(), $this->currentUser(), $snapshot->id);
        });

        unset($this->snapshots);
    }

    public function startReject(string $snapshotId): void
    {
        Gate::authorize('accounting.view');
        $this->visible($snapshotId);
        $this->rejectId = $snapshotId;
        $this->reset('rejectReason');
    }

    public function reject(ArImportService $imports): void
    {
        Gate::authorize('accounting.view');
        $snapshot = $this->visible($this->rejectId);

        $done = $this->attempt('action', __('Snapshot ditolak.'), function () use ($imports, $snapshot): void {
            $imports->reject($this->selfActor(), $this->currentUser(), $snapshot->id, $this->rejectReason);
        });

        if ($done) {
            $this->reset('rejectId', 'rejectReason');
        }

        unset($this->snapshots);
    }

    /**
     * A snapshot outside the account's scope does not exist for it.
     */
    private function visible(string $snapshotId): ArSnapshot
    {
        $query = ArSnapshot::query()->where('public_id', $snapshotId);
        app(ArAccess::class)->restrict($query, $this->currentUser());

        return $query->firstOrFail();
    }
}; ?>

@php
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
    $tone = fn (string $status): string => match ($status) { 'committed' => 'success', 'rejected' => 'neutral', default => 'info' };
    $statusLabel = ['staged' => __('Staged'), 'committed' => __('Committed'), 'rejected' => __('Ditolak')];
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Piutang (AR)')"
        :description="__('Snapshot piutang dari sistem akuntansi pada tanggal as-of. Sistem KPI bukan buku besar: data hanya dibaca, tidak pernah mengubah invoice, pembayaran, atau faktur di sistem sumber.')"
    >
        <x-slot:actions>
            @can('reports.view')
                <x-ui.button variant="secondary" :href="route('reports.index', ['laporan' => 'ar_aging'])" wire:navigate>{{ __('Laporan aging') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'file']])

    <x-ui.alert variant="warning" :title="__('Formula KPI keuangan belum disetujui')">
        {{ __('DSO, collection rate, persentase overdue, unapplied payment, forecast, perlakuan credit note dan lebih bayar, aturan tenggat faktur pajak, follow-up penagihan, dan close readiness menunggu keputusan bisnis. Halaman ini hanya menampilkan aging dengan bucket PRD dan rekonsiliasi snapshot.') }}
    </x-ui.alert>

    @if ($this->manageableUnits !== [])
        <x-ui.panel :title="__('Impor snapshot')" :description="__('File CSV atau XLSX sesuai kontrak impor. File yang sama tidak diimpor dua kali; file baru untuk tanggal as-of yang sama menjadi versi berikutnya, versi lama tetap ada.')">
            <form wire:submit="stage" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="unit" :label="__('Team')" :placeholder="__('Pilih team')" :options="$this->manageableUnits" required wire:model="unit" />
                <x-ui.input name="sourceSystem" :label="__('Kode sistem sumber')" :hint="__('Contoh: erp-accounting')" required wire:model="sourceSystem" />
                <x-ui.input name="asOf" type="date" :label="__('Tanggal as-of')" required wire:model="asOf" />
                <x-ui.input name="file" type="file" :label="__('File snapshot')" accept=".csv,.xlsx" required wire:model="file" />
                <x-ui.input name="controlInvoiceTotal" :label="__('Total kontrol invoice dari sumber (opsional)')" inputmode="decimal" wire:model="controlInvoiceTotal" />
                <x-ui.input name="controlAllocationTotal" :label="__('Total kontrol alokasi dari sumber (opsional)')" inputmode="decimal" wire:model="controlAllocationTotal" />
                <div class="sm:col-span-2"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="stage,file">{{ __('Validasi dan simpan sebagai staged') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.table-shell :caption="__('Snapshot piutang dalam scope Anda')" :columns="[__('As-of'), __('Team'), __('Sumber'), __('Versi'), __('Status'), __('Baris'), __('Masalah'), __('Diimpor oleh'), __('Aksi')]">
        @foreach ($this->snapshots as $snapshot)
            <tr wire:key="snapshot-{{ $snapshot->public_id }}">
                <td class="px-4 py-3 whitespace-nowrap">{{ $snapshot->as_of_date }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $snapshot->unit->code }}</td>
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $snapshot->source_system }} <span class="block text-xs font-normal">{{ $snapshot->original_name }}</span></th>
                <td class="px-4 py-3">v{{ $snapshot->version }}</td>
                <td class="px-4 py-3"><x-ui.status-badge :status="$tone($snapshot->status)">{{ $statusLabel[$snapshot->status] ?? $snapshot->status }}</x-ui.status-badge></td>
                <td class="px-4 py-3">{{ $snapshot->row_count }}</td>
                <td class="px-4 py-3">
                    @if ($snapshot->error_count > 0)
                        <details class="text-xs"><summary>{{ __(':n baris bermasalah', ['n' => $snapshot->error_count]) }}</summary>
                            <ul class="mt-1 list-disc ps-4">
                                @foreach ($snapshot->errors ?? [] as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3">{{ $snapshot->stagedBy->name }}</td>
                <td class="px-4 py-3">
                    @if ($snapshot->status === 'committed')
                        <a href="{{ route('accounting.index', ['snapshot' => $snapshot->public_id]) }}" wire:navigate class="{{ $link }}">{{ __('Lihat aging') }}<span class="sr-only"> {{ $snapshot->as_of_date }}</span></a>
                    @elseif ($snapshot->status === 'staged' && app(ArAccess::class)->canManage(auth()->user(), $snapshot->organization_unit_id))
                        <div class="flex flex-wrap gap-2">
                            @if ($snapshot->error_count === 0)
                                <x-ui.button wire:click="commit('{{ $snapshot->public_id }}')">{{ __('Commit') }}<span class="sr-only"> {{ $snapshot->as_of_date }}</span></x-ui.button>
                            @endif
                            <x-ui.button variant="secondary" wire:click="startReject('{{ $snapshot->public_id }}')">{{ __('Tolak') }}<span class="sr-only"> {{ $snapshot->as_of_date }}</span></x-ui.button>
                        </div>
                    @else
                        —
                    @endif
                </td>
            </tr>

            @if ($rejectId === $snapshot->public_id)
                <tr wire:key="reject-{{ $snapshot->public_id }}">
                    <td colspan="9" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                        <form wire:submit="reject" class="grid gap-4">
                            <x-ui.textarea name="rejectReason" :label="__('Alasan penolakan')" :hint="__('Masuk ke audit log. Jangan tulis nama customer atau nomor dokumen.')" required wire:model="rejectReason" />
                            <div><x-ui.button type="submit" variant="danger">{{ __('Tolak snapshot') }}</x-ui.button></div>
                        </form>
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada snapshot')" :description="__('Belum ada koneksi ke sistem akuntansi; snapshot diimpor sebagai file sesuai kontrak.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    @if ($this->summary !== null)
        @php($summary = $this->summary)
        <x-ui.table-shell :caption="__('Aging per :date (bucket :version)', ['date' => $this->current->as_of_date, 'version' => \App\Modules\Accounting\Application\ArQuery::buckets()->version])" :columns="[__('Bucket'), __('Jumlah invoice'), __('Outstanding'), __('Di antaranya dispute')]">
            @foreach ($summary['buckets'] as $key => $bucket)
                <tr wire:key="bucket-{{ $key }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">{{ $bucket['label'] }}</th>
                    <td class="px-4 py-3">{{ $bucket['count'] }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $bucket['outstanding'] }}</td>
                    <td class="px-4 py-3">{{ $bucket['disputed_count'] }} · {{ $bucket['disputed_outstanding'] }}</td>
                </tr>
            @endforeach
        </x-ui.table-shell>

        <p class="text-sm text-zinc-700 dark:text-zinc-300">
            {{ __('Outstanding: :total · Jumlah bucket: :buckets · Lunas: :paid invoice · Kelebihan alokasi: :over invoice (:credit, perlakuan belum diputuskan).', ['total' => $summary['outstanding_total'], 'buckets' => $summary['buckets_total'], 'paid' => $summary['paid'], 'over' => $summary['over_allocated']['count'], 'credit' => $summary['over_allocated']['credit']]) }}
            <x-ui.status-badge :status="$summary['reconciles'] ? 'success' : 'danger'">{{ $summary['reconciles'] ? __('Bucket cocok dengan outstanding') : __('Bucket TIDAK cocok dengan outstanding') }}</x-ui.status-badge>
        </p>
    @endif
</div>
