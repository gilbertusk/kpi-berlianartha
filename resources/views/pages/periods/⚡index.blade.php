<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\PeriodAssignment\Application\PeriodQuery;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Application\PeriodSetup;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\PeriodType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Periode penilaian')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'status')]
    public string $status = '';

    public string $newCode = '';
    public string $newName = '';
    public string $newType = 'monthly';
    public string $newStartsOn = '';
    public string $newEndsOn = '';
    public string $newUnit = '';
    public string $newReferenceDate = '';
    public string $newNotes = '';

    /** @var array<string, string> */
    public array $newDeadlines = [];

    public function mount(): void
    {
        $this->authorize('viewAny', AssessmentPeriod::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return app(PeriodQuery::class)->periods(
            $this->access()->unitScope($this->currentUser(), Capability::PeriodView),
            PeriodStatus::tryFrom($this->status)?->value,
            $this->businessToday(),
            $this->getPage(),
        );
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->currentUser()->can('create', AssessmentPeriod::class);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function manageableUnits(): array
    {
        $snapshot = app(OrganizationDirectory::class)->snapshot($this->businessToday());
        $scope = $this->access()->unitScope($this->currentUser(), Capability::PeriodManage);

        return collect($snapshot->all())
            ->filter(fn (UnitNode $unit): bool => $scope->coversUnit($unit->id))
            ->mapWithKeys(fn (UnitNode $unit): array => [$unit->publicId => $unit->type->label().': '.($unit->parentId === null ? $unit->name : $snapshot->pathLabel($unit->id))])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(PeriodStatus::cases())->mapWithKeys(fn (PeriodStatus $status): array => [$status->value => $status->label()])->all();
    }

    public function create(PeriodService $periods): void
    {
        $validated = $this->validate([
            'newCode' => ['required', 'string', 'max:60'],
            'newName' => ['required', 'string', 'max:160'],
            'newType' => ['required', 'in:'.implode(',', array_keys(PeriodType::options()))],
            'newStartsOn' => ['required', 'date_format:Y-m-d'],
            'newEndsOn' => ['required', 'date_format:Y-m-d'],
            'newUnit' => ['required', 'string', 'size:26'],
            'newReferenceDate' => ['nullable', 'date_format:Y-m-d'],
            'newNotes' => ['nullable', 'string', 'max:2000'],
            'newDeadlines' => ['array'],
            'newDeadlines.*' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $unit = OrganizationUnit::query()->where('public_id', $validated['newUnit'])->firstOrFail();
        $this->authorize('create', [AssessmentPeriod::class, $unit]);

        $period = null;

        $created = $this->attempt('action', __('Periode dibuat sebagai draft.'), function () use ($periods, $validated, $unit, &$period): void {
            $period = $periods->createDraft(
                $this->actorFor(Capability::PeriodManage, $this->access()->unitTarget($unit->id)),
                new PeriodSetup(
                    code: $validated['newCode'],
                    name: $validated['newName'],
                    type: $validated['newType'],
                    startsOn: $validated['newStartsOn'],
                    endsOn: $validated['newEndsOn'],
                    unitId: $unit->id,
                    referenceDate: $validated['newReferenceDate'] ?? null,
                    notes: $validated['newNotes'] ?? null,
                    deadlines: array_intersect_key($this->newDeadlines, array_flip(array_map(fn (DeadlineStage $stage): string => $stage->value, DeadlineStage::cases()))),
                ),
            );
        });

        if ($created && $period !== null) {
            $this->redirectRoute('periods.show', ['period' => $period->public_id], navigate: true);
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Periode penilaian')"
        :description="__('Periode menetapkan siapa dinilai oleh siapa dan target yang berlaku. Snapshot penugasan dibuat saat periode dibuka dan tidak berubah setelahnya.')"
    />

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter periode') }}">
        <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="$this->statusOptions" wire:model.live="status" />
    </form>

    <x-ui.table-shell :caption="__('Daftar periode')" :columns="[__('Kode'), __('Nama'), __('Tipe'), __('Rentang'), __('Scope'), __('Status'), __('Detail')]">
        @foreach ($this->rows as $period)
            <tr wire:key="period-{{ $period->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $period->code }}</td>
                <td class="px-4 py-3">{{ $period->name }}</td>
                <td class="px-4 py-3">{{ $period->period_type->label() }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $period->starts_on }} — {{ $period->ends_on }}</td>
                <td class="px-4 py-3">{{ $period->scopeUnit->code }}</td>
                <td class="px-4 py-3">@include('pages.periods.partials.status-badge', ['status' => $period->status])</td>
                <td class="px-4 py-3">
                    <a href="{{ route('periods.show', $period) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Buka') }}<span class="sr-only"> {{ $period->code }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada periode')" :description="__('Periode dibuat oleh HR Performance untuk scope organisasi yang dikelolanya.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($this->canCreate)
        <x-ui.panel :title="__('Buat periode')" :description="__('Tanggal mulai dan akhir bersifat inklusif dalam zona waktu bisnis. Deadline bersifat opsional; nilai SLA belum diputuskan (DEC-009) sehingga tidak ada nilai bawaan.')">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="newCode" :label="__('Kode periode')" required :hint="__('Contoh: 2026-11')" wire:model="newCode" />
                <x-ui.input name="newName" :label="__('Nama periode')" required wire:model="newName" />
                <x-ui.select name="newType" :label="__('Tipe periode')" required :options="PeriodType::options()" :hint="__('Periode bertipe sama tidak boleh tumpang tindih untuk scope yang sama.')" wire:model="newType" />
                <x-ui.select name="newUnit" :label="__('Scope organisasi')" required :placeholder="__('Pilih unit')" :options="$this->manageableUnits" wire:model="newUnit" />
                <x-ui.input name="newStartsOn" type="date" :label="__('Mulai')" required wire:model="newStartsOn" />
                <x-ui.input name="newEndsOn" type="date" :label="__('Selesai')" required wire:model="newEndsOn" />
                <x-ui.input name="newReferenceDate" type="date" :label="__('Tanggal acuan penugasan')" :hint="__('Kosongkan untuk memakai tanggal mulai. Team, jabatan, template, dan approver diambil per tanggal ini.')" wire:model="newReferenceDate" />
                <x-ui.textarea name="newNotes" :label="__('Catatan')" wire:model="newNotes" />

                <fieldset class="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                    <legend class="mb-2 text-base font-semibold">{{ __('Deadline per tahap (opsional)') }}</legend>
                    @foreach (DeadlineStage::cases() as $stage)
                        <x-ui.input name="newDeadlines.{{ $stage->value }}" type="date" :label="$stage->label()" wire:model="newDeadlines.{{ $stage->value }}" />
                    @endforeach
                </fieldset>

                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Buat periode') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
