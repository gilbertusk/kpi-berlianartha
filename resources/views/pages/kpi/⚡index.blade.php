<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Application\KpiCatalogQuery;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('KPI')] class extends GuardedPage {
    public function mount(): void
    {
        Gate::authorize('kpi.view');
    }

    /**
     * @return array<string, array<string, int>>
     */
    #[Computed]
    public function counts(): array
    {
        $scope = $this->access()->unitScope($this->currentUser(), Capability::KpiDefinitionView);

        return app(KpiCatalogQuery::class)->definitionVersionCounts($scope);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('KPI')"
        :description="__('Kamus KPI dan template scorecard berversi. Kandidat dari lampiran PRD tetap berstatus draft sampai lengkap dan disetujui.')"
    />

    @include('pages.kpi.partials.nav')

    <x-ui.alert variant="warning" :title="__('Belum ada keputusan bisnis yang mengesahkan KPI resmi')">
        {{ __('DEC-003 (bobot), DEC-004 (aturan skor), DEC-005 (scope KPI), dan DEC-006 (definisi KPI) masih terbuka. Versi hanya boleh dipublish dengan referensi sign-off yang sah.') }}
    </x-ui.alert>

    <x-ui.table-shell :caption="__('Jumlah versi definisi KPI per status')" :columns="[__('Asal'), ...array_map(fn ($status) => $status->label(), LifecycleStatus::cases())]">
        @foreach (['authored' => __('Dibuat HR'), 'prd_candidate' => __('Kandidat PRD')] as $origin => $label)
            <tr>
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $label }}</th>
                @foreach (LifecycleStatus::cases() as $status)
                    <td class="px-4 py-3">{{ $this->counts[$origin][$status->value] ?? 0 }}</td>
                @endforeach
            </tr>
        @endforeach
    </x-ui.table-shell>
</div>
