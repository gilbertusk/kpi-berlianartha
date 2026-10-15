<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Production\Application\PpicBoard;
use App\Modules\Production\Application\ProductionAccess;
use App\Modules\Production\Domain\PpicStatus;
use App\Modules\Production\Infrastructure\Persistence\Models\PpicStatusChange;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Board PPIC')] class extends GuardedPage {
    #[Url]
    public string $customer = '';

    #[Url]
    public string $order = '';

    #[Url(as: 'wo')]
    public string $workOrder = '';

    #[Url(as: 'dari')]
    public string $needFrom = '';

    #[Url(as: 'sampai')]
    public string $needTo = '';

    #[Url(as: 'mesin')]
    public string $machine = '';

    #[Url]
    public string $status = '';

    #[Locked]
    public string $moving = '';

    #[Locked]
    public string $expected = '';

    public string $target = '';

    public string $reason = '';

    #[Locked]
    public string $historyOf = '';

    public function mount(): void
    {
        Gate::authorize('ppic.view');
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        return app(PpicBoard::class)->rows($this->currentUser(), [
            'customer' => $this->customer,
            'order' => $this->order,
            'work_order' => $this->workOrder,
            'need_from' => $this->needFrom,
            'need_to' => $this->needTo,
            'machine' => $this->machine,
            'status' => $this->status,
        ]);
    }

    #[Computed]
    public function batch(): ?ProductionBatch
    {
        return app(PpicBoard::class)->currentBatch();
    }

    #[Computed]
    public function canMove(): bool
    {
        return app(ProductionAccess::class)->canManageBoard($this->currentUser());
    }

    /**
     * @return list<PpicStatusChange>
     */
    #[Computed]
    public function history(): array
    {
        return $this->historyOf === '' ? [] : app(PpicBoard::class)->history($this->currentUser(), $this->historyOf);
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return [PpicBoard::UNSET => __('Belum ditetapkan'), ...collect(PpicStatus::cases())->mapWithKeys(fn (PpicStatus $status): array => [$status->value => $status->label()])->all()];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['customer', 'order', 'workOrder', 'needFrom', 'needTo', 'machine', 'status'], true)) {
            unset($this->rows);
        }
    }

    public function resetFilters(): void
    {
        $this->reset('customer', 'order', 'workOrder', 'needFrom', 'needTo', 'machine', 'status');
        unset($this->rows);
    }

    public function startMove(string $workOrder): void
    {
        Gate::authorize('ppic.view');
        abort_unless($this->canMove, 403);
        $row = collect($this->rows)->first(fn (array $row): bool => $row['record']->work_order === $workOrder);
        abort_if($row === null, 404);

        // The status seen now is what the move expects; a move made meanwhile
        // by someone else is reported instead of overwritten.
        $this->moving = $workOrder;
        $this->expected = $row['status']?->value ?? '';
        $this->reset('target', 'reason');
    }

    public function move(PpicBoard $board): void
    {
        Gate::authorize('ppic.view');
        $to = PpicStatus::tryFrom($this->target);

        if ($to === null) {
            $this->addError('target', __('Pilih status tujuan.'));

            return;
        }

        $done = $this->attempt('action', __('Status work order :wo diubah.', ['wo' => $this->moving]), function () use ($board, $to): void {
            $board->move($this->selfActor(), $this->currentUser(), $this->moving, PpicStatus::tryFrom($this->expected), $to, $this->reason);
        });

        if ($done) {
            $this->reset('moving', 'expected', 'target', 'reason');
        }

        unset($this->rows, $this->history);
    }

    public function showHistory(string $workOrder): void
    {
        Gate::authorize('ppic.view');
        $this->historyOf = $this->historyOf === $workOrder ? '' : $workOrder;
        unset($this->history);
    }
}; ?>

@php
    $local = fn ($moment): string => $moment === null ? '—' : app(\App\Modules\Shared\Application\Time\BusinessTime::class)->toBusiness($moment)->format('Y-m-d H:i');
    $counts = collect($this->rows)->countBy(fn (array $row): string => $row['status']?->value ?? \App\Modules\Production\Application\PpicBoard::UNSET);
    $shown = array_slice($this->rows, 0, 200);
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Board PPIC')"
        :description="__('Work order, jadwal, dan kesiapan material dari sistem sumber; status board dicatat di sistem KPI dengan waktu, pengguna, dan alasan. Sistem KPI tidak mengubah jadwal ERP.')"
    >
        <x-slot:actions>
            @can('production.view')
                <x-ui.button variant="secondary" :href="route('production.index')" wire:navigate>{{ __('Data produksi') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action', 'target']])

    @if ($this->batch === null)
        <x-ui.alert variant="warning" :title="__('Belum ada data work order')">{{ __('Admin PPIC mengimpor work order dari sistem sumber di halaman Produksi.') }}</x-ui.alert>
    @else
        <x-ui.alert variant="info" :title="__('Data sumber per :at', ['at' => $local($this->batch->source_extracted_at)])">
            {{ __('Work order as-of :date versi :version, di-commit :committed (:age). Jadwal dan material dapat sudah berubah di sistem sumber sejak waktu ekstraksi.', ['date' => $this->batch->coverage_date, 'version' => $this->batch->version, 'committed' => $local($this->batch->committed_at), 'age' => $this->batch->source_extracted_at->diffForHumans()]) }}
        </x-ui.alert>
    @endif

    <x-ui.panel :title="__('Filter')">
        <div class="grid gap-4 sm:grid-cols-4">
            <x-ui.input name="customer" :label="__('Customer')" wire:model.live.debounce.500ms="customer" />
            <x-ui.input name="order" :label="__('Order')" wire:model.live.debounce.500ms="order" />
            <x-ui.input name="workOrder" :label="__('Work order')" wire:model.live.debounce.500ms="workOrder" />
            <x-ui.input name="machine" :label="__('Mesin')" wire:model.live.debounce.500ms="machine" />
            <x-ui.input name="needFrom" type="date" :label="__('Kebutuhan dari')" wire:model.live="needFrom" />
            <x-ui.input name="needTo" type="date" :label="__('Kebutuhan sampai')" wire:model.live="needTo" />
            <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="$this->statusOptions()" wire:model.live="status" />
            <div class="self-end"><x-ui.button variant="secondary" wire:click="resetFilters">{{ __('Reset filter') }}</x-ui.button></div>
        </div>
    </x-ui.panel>

    @if (count($this->rows) > count($shown))
        <x-ui.alert variant="info" :title="__('Menampilkan :shown dari :total work order', ['shown' => count($shown), 'total' => count($this->rows)])">{{ __('Persempit dengan filter untuk melihat work order lainnya, atau gunakan laporan work order PPIC.') }}</x-ui.alert>
    @endif

    <ul class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('Jumlah work order per status pada filter ini') }}">
        @foreach ($this->statusOptions() as $value => $label)
            <li class="rounded border border-zinc-200 px-2 py-1 dark:border-zinc-700">{{ $label }}: <span class="font-semibold">{{ $counts[$value] ?? 0 }}</span></li>
        @endforeach
    </ul>

    <x-ui.table-shell :caption="__('Work order')" :columns="[__('Work order'), __('Customer / order'), __('Produk / mesin'), __('Jadwal'), __('Material / design'), __('Penanda'), __('Progres output'), __('Status'), __('Aksi')]">
        @foreach ($shown as $row)
            @php($record = $row['record'])
            <tr wire:key="wo-{{ $record->work_order }}">
                <th scope="row" class="px-4 py-3 text-start font-medium font-mono text-xs">{{ $record->work_order }}</th>
                <td class="px-4 py-3">{{ $record->customer_code ?? '—' }} <span class="block text-xs">{{ $record->detail('order_number') ?? '—' }}</span></td>
                <td class="px-4 py-3">{{ $record->product_code ?? '—' }} <span class="block text-xs">{{ $record->machine_code ?? '—' }}</span></td>
                <td class="px-4 py-3 whitespace-nowrap text-xs">{{ __('Mulai') }} {{ $record->detail('planned_start') ?? '—' }}<br>{{ __('Selesai') }} {{ $record->detail('planned_end') ?? '—' }}<br><span class="font-semibold">{{ __('Kebutuhan') }} {{ $record->due_date }}</span></td>
                <td class="px-4 py-3 text-xs">{{ $record->detail('material_status') }} <span class="block">{{ $record->detail('design_status') ?? '—' }}</span></td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        @if ($row['overdue'])<x-ui.status-badge status="danger">{{ __('Overdue') }}</x-ui.status-badge>@endif
                        @if ($row['material_not_ready'])<x-ui.status-badge status="warning">{{ __('Material belum siap') }}</x-ui.status-badge>@endif
                        @if ($row['emergency'])<x-ui.status-badge status="warning">{{ __('Emergency') }}</x-ui.status-badge>@endif
                        @if ($row['schedule_changed'])<x-ui.status-badge status="info">{{ __('Jadwal berubah') }}</x-ui.status-badge><span class="block text-xs">{{ __('Sebelumnya: :s', ['s' => $row['previous_schedule']]) }}</span>@endif
                    </div>
                </td>
                <td class="px-4 py-3 text-xs">@forelse ($row['output_by_team'] as $team => $quantity)<span class="block">{{ $team }}: {{ $quantity }} / {{ $record->quantity }} {{ $record->unit }}</span>@empty 0 / {{ $record->quantity }} {{ $record->unit }} @endforelse @if ($row['output_in_other_units'] !== [])<span class="block">{{ __('Output satuan lain tidak dikonversi: :u', ['u' => implode(', ', $row['output_in_other_units'])]) }}</span>@endif</td>
                <td class="px-4 py-3">
                    {{ $row['status']?->label() ?? __('Belum ditetapkan') }}
                    @if ($row['last_change'] !== null)
                        <span class="block text-xs">{{ $local($row['last_change']->changed_at) }} · {{ $row['last_change']->changedBy->name }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-2">
                        @if ($this->canMove)
                            <x-ui.button wire:click="startMove('{{ $record->work_order }}')">{{ __('Ubah status') }}<span class="sr-only"> {{ $record->work_order }}</span></x-ui.button>
                        @endif
                        <x-ui.button variant="secondary" wire:click="showHistory('{{ $record->work_order }}')">{{ __('Riwayat') }}<span class="sr-only"> {{ $record->work_order }}</span></x-ui.button>
                    </div>
                </td>
            </tr>

            @if ($moving === $record->work_order)
                <tr wire:key="move-{{ $record->work_order }}">
                    <td colspan="9" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                        <form wire:submit="move" class="grid gap-4 sm:grid-cols-2">
                            <x-ui.select name="target" :label="__('Status tujuan')" :placeholder="__('Pilih status')" :options="collect(PpicStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" required wire:model="target" />
                            <x-ui.textarea name="reason" :label="__('Alasan')" :hint="__('Wajib bila work order melewati tanggal kebutuhan atau dipindah ke status sebelumnya.')" wire:model="reason" />
                            <div><x-ui.button type="submit">{{ __('Simpan status') }}</x-ui.button></div>
                        </form>
                    </td>
                </tr>
            @endif

            @if ($historyOf === $record->work_order)
                <tr wire:key="history-{{ $record->work_order }}">
                    <td colspan="9" class="bg-zinc-50 px-4 py-4 text-sm dark:bg-zinc-800">
                        @forelse ($this->history as $change)
                            <p>#{{ $change->sequence }} · {{ $local($change->changed_at) }} · {{ $change->changedBy->name }}: {{ $change->from_status?->label() ?? __('Belum ditetapkan') }} → {{ $change->to_status->label() }}@if ($change->was_overdue) ({{ __('melewati kebutuhan :d', ['d' => $change->need_date]) }})@endif @if ($change->reason) — {{ $change->reason }}@endif</p>
                        @empty
                            <p>{{ __('Belum ada perubahan status.') }}</p>
                        @endforelse
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada work order')" :description="__('Tidak ada work order pada data sumber terbaru yang cocok dengan filter.')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
