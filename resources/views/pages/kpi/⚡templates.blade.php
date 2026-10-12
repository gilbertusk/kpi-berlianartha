<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Application\KpiCatalogQuery;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Template scorecard')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'jenis')]
    public string $subject = '';

    public string $newCode = '';
    public string $newName = '';
    public string $newUnit = '';
    public string $newSubject = 'employee';
    public string $newPosition = '';
    public string $newNotes = '';

    public function mount(): void
    {
        $this->authorize('viewAny', KpiTemplate::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'subject'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return app(KpiCatalogQuery::class)->templates(
            $this->access()->unitScope($this->currentUser(), Capability::KpiTemplateView),
            $this->search,
            array_key_exists($this->subject, KpiTemplate::SUBJECTS) ? $this->subject : null,
            $this->getPage(),
        );
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->currentUser()->can('create', KpiTemplate::class);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function manageableUnits(): array
    {
        $snapshot = app(OrganizationDirectory::class)->snapshot($this->businessToday());
        $scope = $this->access()->unitScope($this->currentUser(), Capability::KpiTemplateManage);

        return collect($snapshot->all())
            ->filter(fn (UnitNode $unit): bool => $scope->coversUnit($unit->id))
            ->mapWithKeys(fn (UnitNode $unit): array => [$unit->publicId => $unit->type->label().': '.$snapshot->pathLabel($unit->id)])
            ->all();
    }

    public function create(KpiTemplateService $templates): void
    {
        $validated = $this->validate([
            'newCode' => ['required', 'string', 'max:60'],
            'newName' => ['required', 'string', 'max:160'],
            'newUnit' => ['required', 'string', 'size:26'],
            'newSubject' => ['required', 'in:'.implode(',', array_keys(KpiTemplate::SUBJECTS))],
            'newPosition' => ['nullable', 'string', 'max:120'],
            'newNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $unit = OrganizationUnit::query()->where('public_id', $validated['newUnit'])->firstOrFail();
        $this->authorize('create', [KpiTemplate::class, $unit]);

        $version = null;

        $created = $this->attempt('action', __('Template dibuat sebagai draft v1.'), function () use ($templates, $validated, $unit, &$version): void {
            $version = $templates->createTemplate(
                $this->actorFor(Capability::KpiTemplateManage, $this->access()->unitTarget($unit->id)),
                $validated['newCode'],
                $validated['newName'],
                $unit->id,
                $validated['newSubject'],
                $validated['newPosition'] ?? null,
                $validated['newNotes'] ?? null,
            );
        });

        if ($created && $version !== null) {
            $this->redirectRoute('kpi.templates.show', ['template' => $version->template->public_id], navigate: true);
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Template scorecard')"
        :description="__('Template per unit dan jabatan. Total bobot wajib tepat 100% sebelum publish. Setiap periode memakai versi yang berlaku pada tanggalnya.')"
    />

    @include('pages.kpi.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter template') }}">
        <x-ui.input name="search" type="search" :label="__('Cari kode atau nama')" wire:model.live.debounce.400ms="search" />
        <x-ui.select name="subject" :label="__('Jenis scorecard')" :placeholder="__('Semua jenis')" :options="KpiTemplate::SUBJECTS" wire:model.live="subject" />
    </form>

    <x-ui.table-shell :caption="__('Daftar template')" :columns="[__('Kode'), __('Nama'), __('Unit'), __('Jenis / jabatan'), __('Versi terakhir'), __('Detail')]">
        @foreach ($this->rows as $template)
            <tr wire:key="template-{{ $template->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $template->code }}</td>
                <td class="px-4 py-3">{{ $template->name }}</td>
                <td class="px-4 py-3">{{ $template->unit->code }}</td>
                <td class="px-4 py-3">{{ KpiTemplate::SUBJECTS[$template->subject] ?? $template->subject }}{{ $template->position_title ? ' · '.$template->position_title : '' }}</td>
                <td class="px-4 py-3">
                    v{{ $template->getAttribute('latest_version_number') }}
                    @include('pages.kpi.partials.status-badge', ['status' => $template->getAttribute('latest_status')])
                </td>
                <td class="px-4 py-3">
                    <a href="{{ route('kpi.templates.show', $template) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Buka') }}<span class="sr-only"> {{ $template->code }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada template')" :description="__('Template dibuat oleh HR Performance. Bobot draft PRD belum disetujui (DEC-003).')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($this->canCreate)
        <x-ui.panel :title="__('Buat template')" :description="__('Satu kombinasi unit, jenis scorecard, dan jabatan hanya memiliki satu template; perubahan dilakukan lewat versi.')">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="newCode" :label="__('Kode template')" required wire:model="newCode" />
                <x-ui.input name="newName" :label="__('Nama template')" required wire:model="newName" />
                <x-ui.select name="newUnit" :label="__('Unit')" required :placeholder="__('Pilih unit')" :options="$this->manageableUnits" wire:model="newUnit" />
                <x-ui.select name="newSubject" :label="__('Jenis scorecard')" required :options="KpiTemplate::SUBJECTS" wire:model="newSubject" />
                <x-ui.input name="newPosition" :label="__('Jabatan (opsional)')" :hint="__('Kosongkan bila berlaku untuk semua jabatan di unit.')" wire:model="newPosition" />
                <x-ui.textarea name="newNotes" :label="__('Catatan')" wire:model="newNotes" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('Buat template') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
