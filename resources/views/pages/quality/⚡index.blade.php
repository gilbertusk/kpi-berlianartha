<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Quality\Application\NcrQuery;
use App\Modules\Quality\Application\NcrService;
use App\Modules\Quality\Application\QualityAccess;
use App\Modules\Quality\Domain\NcrSource;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('NCR & CAPA')] class extends GuardedPage {
    #[Url(as: 'tahap')]
    public string $stage = '';

    #[Url(as: 'team')]
    public string $unit = '';

    #[Url]
    public string $severity = '';

    #[Url(as: 'tanda')]
    public string $flag = '';

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('quality.view');
        $this->form = ['responsible_unit' => '', 'source_type' => '', 'source_reference' => '', 'category_code' => '', 'severity' => '', 'description' => '', 'quantity' => '', 'unit' => '', 'work_order' => '', 'product_code' => '', 'lot' => '', 'process_code' => '', 'machine_code' => '', 'shift_code' => '', 'detected_at' => '', 'owner_user_id' => (string) $this->currentUser()->id, 'pic_user_id' => '', 'due_date' => '', 'repeat_of' => '', 'kpi_code' => ''];
    }

    /**
     * @return list<array{ncr: QualityNcr, open: \App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrStage|null, overdue_days: int, repeats: int}>
     */
    #[Computed]
    public function rows(): array
    {
        return app(NcrQuery::class)->rows($this->currentUser(), ['stage' => $this->stage, 'unit' => $this->unit, 'severity' => $this->severity, 'flag' => $this->flag]);
    }

    /**
     * @return array{stages: array<string, int>, overdue: int, verification: int, closed: int}
     */
    #[Computed]
    public function summary(): array
    {
        return app(NcrQuery::class)->summary($this->currentUser());
    }

    #[Computed]
    public function canRaise(): bool
    {
        return app(QualityAccess::class)->isQcManager($this->currentUser());
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function units(): array
    {
        $options = [];

        foreach (app(OrganizationDirectory::class)->snapshot($this->businessToday())->all() as $node) {
            $options[$node->code] = "{$node->code} — {$node->name}";
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function people(): array
    {
        return User::query()->where('status', AccountStatus::Active->value)->orderBy('name')->limit(500)->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all();
    }

    #[Computed]
    public function severities(): array
    {
        try {
            return NcrService::rules()->severities;
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['stage', 'unit', 'severity', 'flag'], true)) {
            unset($this->rows);
        }
    }

    public function raise(NcrService $ncrs): void
    {
        Gate::authorize('quality.view');
        abort_unless($this->canRaise, 403);
        $ncr = null;

        $this->attempt('action', __('NCR dicatat pada tahap Temuan.'), function () use ($ncrs, &$ncr): void {
            $ncr = $ncrs->raise($this->selfActor(), $this->currentUser(), $this->form);
        });

        if ($ncr instanceof QualityNcr) {
            $this->redirectRoute('quality.show', ['ncr' => $ncr->public_id], navigate: true);
        }
    }
}; ?>

@php
    $stageOptions = collect(NcrStage::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $sourceOptions = collect(NcrSource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('NCR & CAPA')"
        :description="__('Nonconformance dan tindakan korektif Quality Control: Temuan, Containment, Root Cause, Corrective Action, Verification, Closed. Setiap tahap punya PIC, tenggat, isi, dan bukti; riwayat tidak pernah dihapus.')"
    >
        <x-slot:actions>
            @can('reports.view')
                <x-ui.button variant="secondary" :href="route('reports.index', ['laporan' => 'quality_ncrs'])" wire:navigate>{{ __('Laporan NCR') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <x-ui.alert variant="warning" :title="__('Aturan mutu yang belum disetujui')">
        {{ __('Skala severity, taxonomy defect dan root cause, SLA per tahap, approver tahap, disposition, preventive action, effectiveness review, aturan pencocokan repeat defect, dan formula rejection rate, first pass yield, complaint rate, return rate, repeat defect, inspection lead time, serta closure SLA menunggu keputusan. Overdue dihitung dari tenggat yang ditetapkan pada tiap tahap.') }}
    </x-ui.alert>

    <dl class="grid gap-3 text-sm sm:grid-cols-4">
        <div class="rounded border border-zinc-200 p-3 dark:border-zinc-700"><dt>{{ __('Lewat tenggat') }}</dt><dd class="text-xl font-semibold">{{ $this->summary['overdue'] }}</dd></div>
        <div class="rounded border border-zinc-200 p-3 dark:border-zinc-700"><dt>{{ __('Menunggu verifikasi') }}</dt><dd class="text-xl font-semibold">{{ $this->summary['verification'] }}</dd></div>
        <div class="rounded border border-zinc-200 p-3 dark:border-zinc-700"><dt>{{ __('Terbuka') }}</dt><dd class="text-xl font-semibold">{{ array_sum($this->summary['stages']) - $this->summary['closed'] }}</dd></div>
        <div class="rounded border border-zinc-200 p-3 dark:border-zinc-700"><dt>{{ __('Closed') }}</dt><dd class="text-xl font-semibold">{{ $this->summary['closed'] }}</dd></div>
    </dl>

    <x-ui.panel :title="__('Filter')">
        <div class="grid gap-4 sm:grid-cols-4">
            <x-ui.select name="stage" :label="__('Tahap')" :placeholder="__('Semua tahap')" :options="$stageOptions" wire:model.live="stage" />
            <x-ui.select name="unit" :label="__('Team penanggung jawab')" :placeholder="__('Semua team')" :options="$this->units" wire:model.live="unit" />
            @if ($this->severities !== [])
                <x-ui.select name="severity" :label="__('Severity')" :placeholder="__('Semua')" :options="array_combine($this->severities, $this->severities)" wire:model.live="severity" />
            @endif
            <x-ui.select name="flag" :label="__('Tanda')" :placeholder="__('Semua NCR')" :options="['overdue' => __('Lewat tenggat'), 'mine' => __('Milik atau PIC saya'), 'repeat' => __('Repeat issue')]" wire:model.live="flag" />
        </div>
    </x-ui.panel>

    <x-ui.table-shell :caption="__('NCR dalam scope Anda')" :columns="[__('NCR'), __('Ditemukan'), __('Team'), __('Sumber'), __('Defect'), __('Tahap'), __('PIC dan tenggat'), __('Pemilik'), __('KPI terkait'), __('Repeat')]">
        @foreach ($this->rows as $row)
            @php($ncr = $row['ncr'])
            <tr wire:key="ncr-{{ $ncr->public_id }}">
                <th scope="row" class="px-4 py-3 text-start font-medium"><a href="{{ route('quality.show', ['ncr' => $ncr->public_id]) }}" wire:navigate class="{{ $link }}">{{ $ncr->number }}</a></th>
                <td class="px-4 py-3 whitespace-nowrap text-xs">{{ app(\App\Modules\Shared\Application\Time\BusinessTime::class)->toBusiness($ncr->detected_at)->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $ncr->responsibleUnit->code }}</td>
                <td class="px-4 py-3 text-xs">{{ $ncr->source_type->label() }}<span class="block">{{ $ncr->source_reference }}</span></td>
                <td class="px-4 py-3 text-xs">{{ $ncr->category_code }}<span class="block">{{ $ncr->severity ?? __('severity belum ditetapkan') }}</span></td>
                <td class="px-4 py-3">{{ $ncr->stage->label() }}@if ($ncr->cycle > 1)<span class="block text-xs">{{ __('siklus :n', ['n' => $ncr->cycle]) }}</span>@endif</td>
                <td class="px-4 py-3 text-xs">
                    @if ($row['open'] !== null)
                        {{ $row['open']->pic->name }}<span class="block">{{ $row['open']->due_date }}</span>
                        @if ($row['overdue_days'] > 0)<x-ui.status-badge status="danger">{{ __('Lewat :n hari', ['n' => $row['overdue_days']]) }}</x-ui.status-badge>@endif
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3 text-xs">{{ $ncr->owner->name }}</td>
                <td class="px-4 py-3 text-xs">{{ $ncr->kpiVersion === null ? '—' : $ncr->kpiVersion->name.' v'.$ncr->kpiVersion->version_number }}</td>
                <td class="px-4 py-3 text-xs">
                    @if ($ncr->repeatOf !== null){{ __('Ulangan :n', ['n' => $ncr->repeatOf->number]) }}@endif
                    @if ($row['repeats'] > 0)<span class="block">{{ __(':n NCR ulangan', ['n' => $row['repeats']]) }}</span>@endif
                    @if ($ncr->repeatOf === null && $row['repeats'] === 0)—@endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada NCR')" :description="__('Tidak ada NCR dalam scope Anda yang cocok dengan filter.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    @if ($this->canRaise)
        <x-ui.panel :title="__('Catat NCR baru')" :description="__('Sumber inspeksi merujuk inspeksi pada batch QC yang berlaku; data work order, produk, lot, satuan, dan kuantitas reject diisi dari inspeksi bila kosong. Complaint, return, dan temuan internal dirujuk dengan nomor dari sistemnya.')">
            <form wire:submit="raise" class="grid gap-4 sm:grid-cols-3">
                <x-ui.select name="form.source_type" :label="__('Sumber')" :placeholder="__('Pilih')" :options="$sourceOptions" required wire:model="form.source_type" />
                <x-ui.input name="form.source_reference" :label="__('Referensi sumber')" :hint="__('ID inspeksi, nomor complaint, atau nomor return')" required wire:model="form.source_reference" />
                <x-ui.select name="form.responsible_unit" :label="__('Team penanggung jawab')" :placeholder="__('Pilih team')" :options="$this->units" required wire:model="form.responsible_unit" />
                <x-ui.input name="form.category_code" :label="__('Kategori defect (kode)')" wire:model="form.category_code" />
                @if ($this->severities !== [])
                    <x-ui.select name="form.severity" :label="__('Severity')" :placeholder="__('Pilih')" :options="array_combine($this->severities, $this->severities)" required wire:model="form.severity" />
                @endif
                <x-ui.input name="form.detected_at" type="datetime-local" :label="__('Waktu ditemukan')" wire:model="form.detected_at" />
                <div class="sm:col-span-3"><x-ui.textarea name="form.description" :label="__('Uraian temuan')" required wire:model="form.description" /></div>
                <x-ui.input name="form.quantity" :label="__('Kuantitas defect')" inputmode="decimal" wire:model="form.quantity" />
                <x-ui.input name="form.unit" :label="__('Satuan')" wire:model="form.unit" />
                <x-ui.input name="form.work_order" :label="__('Work order')" wire:model="form.work_order" />
                <x-ui.input name="form.product_code" :label="__('Produk')" wire:model="form.product_code" />
                <x-ui.input name="form.lot" :label="__('Lot')" wire:model="form.lot" />
                <x-ui.input name="form.process_code" :label="__('Proses')" wire:model="form.process_code" />
                <x-ui.input name="form.machine_code" :label="__('Mesin')" wire:model="form.machine_code" />
                <x-ui.input name="form.shift_code" :label="__('Shift')" wire:model="form.shift_code" />
                <x-ui.input name="form.kpi_code" :label="__('Kode KPI terkait (opsional)')" wire:model="form.kpi_code" />
                <x-ui.input name="form.repeat_of" :label="__('Ulangan dari NCR (opsional)')" :hint="__('Contoh: NCR-2026-0001')" wire:model="form.repeat_of" />
                <x-ui.select name="form.owner_user_id" :label="__('Pemilik NCR')" :options="$this->people" required wire:model="form.owner_user_id" />
                <x-ui.select name="form.pic_user_id" :label="__('PIC tahap Temuan')" :placeholder="__('Pilih')" :options="$this->people" required wire:model="form.pic_user_id" />
                <x-ui.input name="form.due_date" type="date" :label="__('Tenggat tahap Temuan')" required wire:model="form.due_date" />
                <div class="sm:col-span-3"><x-ui.button type="submit">{{ __('Catat NCR') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif
</div>
