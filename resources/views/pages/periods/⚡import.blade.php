<?php

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Target\Application\TargetCsv;
use App\Modules\Target\Application\TargetImportService;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Domain\TargetValueRules;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportBatch;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Import target')] class extends GuardedPage {
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $periodId = '';

    /** Batch whose validation result is shown. */
    #[Locked]
    public ?string $batchId = null;

    /** Lock version of that batch when its result was shown. */
    #[Locked]
    public int $batchLock = 0;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $rowFilter = '';
    public string $reason = '';

    public function mount(AssessmentPeriod $period): void
    {
        $this->authorize('manageTargets', $period);
        $this->periodId = $period->public_id;
    }

    public function updatedRowFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function period(): AssessmentPeriod
    {
        return AssessmentPeriod::query()->with(['scopeUnit', 'deadlines'])->where('public_id', $this->periodId)->firstOrFail();
    }

    /**
     * @return Collection<int, TargetImportBatch>
     */
    #[Computed]
    public function batches(): Collection
    {
        return $this->batchQuery()
            ->with('uploadedBy')
            ->orderByDesc('id')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function batch(): ?TargetImportBatch
    {
        return $this->batchId === null ? null : $this->batchQuery()->where('public_id', $this->batchId)->first();
    }

    #[Computed]
    public function rows(): ?LengthAwarePaginator
    {
        if ($this->batch === null) {
            return null;
        }

        $query = TargetImportRow::query()->where('target_import_batch_id', $this->batch->id)->orderBy('row_number');

        if (array_key_exists($this->rowFilter, TargetImportRow::STATUS_LABELS)) {
            $query->where('status', $this->rowFilter);
        }

        return $query->paginate(50, ['*'], 'page', $this->getPage());
    }

    #[Computed]
    public function afterCutoff(): bool
    {
        return app(TargetService::class)->isAfterCutoff($this->period);
    }

    public function stage(TargetImportService $imports): void
    {
        $this->authorize('manageTargets', $this->period);
        $this->validate(['file' => ['required', 'file', 'max:1024', 'extensions:csv,txt']], [], ['file' => __('file CSV')]);

        $file = $this->file;
        abort_unless($file instanceof TemporaryUploadedFile, 422);

        $batch = null;

        $staged = $this->attempt('action', __('File divalidasi. Belum ada target yang berubah.'), function () use ($imports, $file, &$batch): void {
            $batch = $imports->stage(
                $this->actorFor(Capability::TargetManage),
                $this->period,
                $this->scope(),
                $file->getClientOriginalName(),
                (string) $file->get(),
            );
        });

        $file->delete();
        $this->reset('file');

        if ($staged && $batch instanceof TargetImportBatch) {
            $this->show($batch->public_id);
        }
    }

    public function show(string $publicId): void
    {
        $this->authorize('manageTargets', $this->period);

        $shown = $this->batchQuery()->where('public_id', $publicId)->firstOrFail();
        $this->batchId = $shown->public_id;
        $this->batchLock = $shown->lock_version;

        $this->reset('rowFilter', 'reason');
        $this->resetPage();
        unset($this->batch, $this->rows, $this->batches);
    }

    public function commit(TargetImportService $imports): void
    {
        $this->authorize('manageTargets', $this->period);
        $this->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $batch = $this->batch;
        abort_if($batch === null, 404);

        $done = $this->attempt('action', __('Import di-commit. Target tersimpan sebagai draft dan masih harus diajukan serta disetujui.'), fn () => $imports->commit(
            $this->actorFor(Capability::TargetManage),
            $batch->id,
            $this->batchLock,
            $this->scope(),
            $this->reason,
        ));

        if ($done) {
            $this->reset('reason');
        }

        unset($this->batch, $this->rows, $this->batches);
    }

    public function discard(TargetImportService $imports): void
    {
        $this->authorize('manageTargets', $this->period);

        $batch = $this->batch;
        abort_if($batch === null, 404);

        $this->attempt('action', __('Import dibatalkan. Tidak ada target yang berubah.'), fn () => $imports->discard(
            $this->actorFor(Capability::TargetManage),
            $batch->id,
            $this->batchLock,
        ));

        unset($this->batch, $this->rows, $this->batches);
    }

    /**
     * A batch holds the rows of the uploader's scope. Only the uploader, or
     * an account that manages the whole period, may see or commit it.
     *
     * @return Builder<TargetImportBatch>
     */
    private function batchQuery(): Builder
    {
        $query = TargetImportBatch::query()->where('assessment_period_id', $this->period->id);

        if (! $this->currentUser()->can('update', $this->period)) {
            $query->where('uploaded_by_user_id', $this->currentUser()->id);
        }

        return $query;
    }

    private function scope(): UnitScope
    {
        return $this->access()->unitScope($this->currentUser(), Capability::TargetManage);
    }
}; ?>

@php
    $period = $this->period;
    $batch = $this->batch;
    $accepting = $period->status->acceptsTargetChanges();
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Import target').' — '.$period->name" :description="$period->code.' · '.$period->starts_on.' — '.$period->ends_on.' · '.$period->status->label()">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('periods.targets', $period)" wire:navigate>{{ __('Kembali ke matriks target') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.periods.partials.nav', ['period' => $period])

    @include('pages.administration.partials.feedback')

    @if (! $accepting)
        <x-ui.alert variant="warning" :title="__('Periode ini tidak menerima target')">
            {{ __('Import hanya tersedia pada periode Open atau In progress.') }}
        </x-ui.alert>
    @else
        <x-ui.panel :title="__('1. Unggah file')" :description="__('File divalidasi dan disimpan sebagai staging lebih dahulu. Target baru berubah setelah Anda melakukan commit, dan hanya bila seluruh baris valid.')">
            <div class="flex flex-col gap-4">
                <ul class="list-disc ps-5 text-sm text-zinc-700 dark:text-zinc-300">
                    <li>{{ __('Format: CSV UTF-8, pemisah koma atau titik koma, maksimal 1 MB dan :rows baris.', ['rows' => TargetImportService::MAX_ROWS]) }}</li>
                    <li>{{ __('Kolom wajib: :columns. Kolom lain diabaikan.', ['columns' => implode(', ', TargetCsv::REQUIRED_COLUMNS)]) }}</li>
                    <li>{{ __('Angka memakai titik sebagai pemisah desimal, tanpa pemisah ribuan. Baris dengan target_value kosong dilewati.') }}</li>
                    <li>{{ __('File tidak mengubah target final. Revisi target final dilakukan per target dengan alasan.') }}</li>
                </ul>

                <div>
                    <x-ui.button variant="secondary" :href="route('periods.targets.template', $period)">{{ __('Unduh file isian (CSV)') }}</x-ui.button>
                </div>

                <form wire:submit="stage" class="flex flex-col gap-3">
                    <div class="grid gap-1.5">
                        <x-ui.label for="file" :required="true">{{ __('File CSV') }}</x-ui.label>
                        <input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" wire:model="file" class="text-sm" @error('file') aria-invalid="true" aria-describedby="file-error" @enderror>
                        @error('file')
                            <p id="file-error" class="text-sm text-danger-700 dark:text-danger-300">{{ $message }}</p>
                        @enderror
                        <p wire:loading wire:target="file" role="status" class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Mengunggah…') }}</p>
                    </div>
                    <div><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="file,stage">{{ __('Validasi file') }}</x-ui.button></div>
                </form>
            </div>
        </x-ui.panel>
    @endif

    @if ($batch !== null)
        @php($summary = $batch->summary ?? [])
        <section class="flex flex-col gap-4" aria-labelledby="result-heading">
            <h2 id="result-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('2. Hasil validasi') }}: {{ $batch->file_name }}</h2>

            <dl class="grid gap-x-6 gap-y-3 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</dt><dd class="text-sm font-medium">{{ TargetImportBatch::STATUS_LABELS[$batch->status] ?? $batch->status }}</dd></div>
                <div><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Baris data') }}</dt><dd class="text-sm">{{ $batch->row_count }}</dd></div>
                <div><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Valid (akan menjadi draft)') }}</dt><dd class="text-sm">{{ $batch->valid_count }} ({{ __(':count target baru', ['count' => $summary['new_targets'] ?? 0]) }})</dd></div>
                <div><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Error') }}</dt><dd class="text-sm">{{ $batch->error_count }}</dd></div>
                <div><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Tidak berubah') }}</dt><dd class="text-sm">{{ $batch->unchanged_count }}</dd></div>
                <div><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Dilewati (nilai kosong)') }}</dt><dd class="text-sm">{{ $summary['skipped'] ?? 0 }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('SHA-256 file') }}</dt><dd class="font-mono text-xs break-all">{{ $batch->file_checksum }}</dd></div>
            </dl>

            @if ($batch->status === TargetImportBatch::STATUS_STAGED)
                @if ($batch->error_count > 0)
                    <x-ui.alert variant="danger" :title="__('File tidak dapat di-commit')">
                        {{ __(':count baris error. Tidak ada baris yang akan disimpan, termasuk baris yang valid. Perbaiki file lalu unggah ulang.', ['count' => $batch->error_count]) }}
                    </x-ui.alert>
                @elseif ($batch->valid_count === 0)
                    <x-ui.alert variant="info" :title="__('Tidak ada baris yang mengubah target.')" />
                @else
                    <x-ui.alert variant="success" :title="__('Seluruh baris valid. :count target akan disimpan sebagai draft.', ['count' => $batch->valid_count])" />
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($batch->error_count === 0 && $batch->valid_count > 0)
                        <form wire:submit="commit" class="flex flex-col gap-3">
                            @if ($this->afterCutoff)
                                <x-ui.textarea name="reason" :label="__('Alasan (wajib setelah cut-off)')" required wire:model="reason" />
                            @endif
                            <div><x-ui.button type="submit">{{ __('Commit import') }}</x-ui.button></div>
                        </form>
                    @endif
                    <div class="self-end"><x-ui.button variant="ghost" wire:click="discard">{{ __('Batalkan import ini') }}</x-ui.button></div>
                </div>
            @endif

            <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter baris') }}">
                <x-ui.select name="rowFilter" :label="__('Tampilkan baris')" :placeholder="__('Semua baris')" :options="TargetImportRow::STATUS_LABELS" wire:model.live="rowFilter" />
            </form>

            <x-ui.table-shell :caption="__('Baris file dan hasil validasi')" :columns="[__('Baris'), __('KPI'), __('Subjek'), __('Satuan'), __('Nilai'), __('Hasil'), __('Keterangan')]">
                @foreach ($this->rows ?? [] as $row)
                    <tr wire:key="import-row-{{ $row->id }}">
                        <td class="px-4 py-3">{{ $row->row_number }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $row->raw['kpi_code'] ?? '' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $row->raw['subject_type'] ?? '' }} · {{ $row->raw['subject_code'] ?? '' }}</td>
                        <td class="px-4 py-3">{{ $row->raw['unit'] ?? '' }}</td>
                        <td class="px-4 py-3">{{ $row->target_value !== null ? TargetValueRules::display($row->target_value) : ($row->raw['target_value'] ?? '') }}</td>
                        <td class="px-4 py-3">
                            <x-ui.status-badge :status="match ($row->status) { 'valid' => 'success', 'error' => 'danger', default => 'neutral' }">{{ TargetImportRow::STATUS_LABELS[$row->status] ?? $row->status }}</x-ui.status-badge>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ implode(' ', $row->errors ?? []) ?: '—' }}</td>
                    </tr>
                @endforeach
                <x-slot:empty>
                    <x-ui.empty-state :title="__('Tidak ada baris')" />
                </x-slot:empty>
            </x-ui.table-shell>

            {{ $this->rows?->links() }}
        </section>
    @endif

    <section class="flex flex-col gap-3" aria-labelledby="batches-heading">
        <h2 id="batches-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Riwayat import periode ini') }}</h2>

        <x-ui.table-shell :caption="__('Riwayat import')" :columns="[__('Waktu unggah'), __('File'), __('Pengunggah'), __('Baris'), __('Valid'), __('Error'), __('Status'), __('Detail')]">
            @foreach ($this->batches as $item)
                <tr wire:key="batch-{{ $item->public_id }}">
                    <td class="px-4 py-3 whitespace-nowrap">{{ $item->created_at->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3">{{ $item->file_name }}</td>
                    <td class="px-4 py-3">{{ $item->uploadedBy?->name ?? __('Sistem') }}</td>
                    <td class="px-4 py-3">{{ $item->row_count }}</td>
                    <td class="px-4 py-3">{{ $item->valid_count }}</td>
                    <td class="px-4 py-3">{{ $item->error_count }}</td>
                    <td class="px-4 py-3">{{ TargetImportBatch::STATUS_LABELS[$item->status] ?? $item->status }}</td>
                    <td class="px-4 py-3"><x-ui.button variant="ghost" wire:click="show('{{ $item->public_id }}')">{{ __('Lihat') }}<span class="sr-only"> {{ $item->file_name }}</span></x-ui.button></td>
                </tr>
            @endforeach
            <x-slot:empty>
                <x-ui.empty-state :title="__('Belum ada import')" />
            </x-slot:empty>
        </x-ui.table-shell>
    </section>
</div>
