<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScorecardPolicyCatalog;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Scorecard')] class extends GuardedPage {
    public function mount(): void
    {
        Gate::authorize('scorecards.view');
    }

    /**
     * Periods past target setting that hold a scorecard this account may see.
     *
     * @return Collection<int, AssessmentPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return AssessmentPeriod::query()
            ->whereNotIn('status', [PeriodStatus::Draft->value, PeriodStatus::Open->value])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (AssessmentPeriod $period): bool => $this->currentUser()->can('viewScorecards', $period))
            ->values();
    }

    /**
     * Approved policies, or null when the configuration is invalid.
     *
     * @return list<ScorecardPolicy>|null
     */
    #[Computed]
    public function policies(): ?array
    {
        try {
            return app(ScorecardPolicyCatalog::class)->all();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Scorecard')"
        :description="__('Skor per subjek dihitung dari target final, aktual terverifikasi, versi definisi KPI yang dikunci periode, dan aturan scorecard yang disetujui. Setiap perhitungan disimpan utuh dan tidak pernah ditimpa.')"
    />

    @if ($this->policies === null)
        <x-ui.alert variant="danger" :title="__('Konfigurasi aturan scorecard tidak valid')">
            {{ __('Perhitungan skor dihentikan sampai konfigurasi diperbaiki. Hubungi administrator aplikasi.') }}
        </x-ui.alert>
    @elseif ($this->policies === [])
        <x-ui.alert variant="warning" :title="__('Perhitungan skor resmi diblokir: DEC-004 belum disetujui')">
            {{ __('Aturan scorecard — data terlambat, bobot KPI N/A, pembulatan kontribusi dan skor akhir, serta rating band — belum memiliki persetujuan bisnis. Sistem tidak memakai aturan bawaan, sehingga skor belum dapat dihitung.') }}
        </x-ui.alert>
    @else
        <x-ui.table-shell :caption="__('Aturan scorecard yang disetujui')" :columns="[__('Versi'), __('Berlaku'), __('Referensi sign-off')]">
            @foreach ($this->policies as $policy)
                <tr wire:key="policy-{{ $policy->reference() }}">
                    <td class="px-4 py-3 font-mono text-xs">{{ $policy->reference() }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $policy->effectiveFrom }} — {{ $policy->effectiveUntil ?? __('seterusnya') }}</td>
                    <td class="px-4 py-3">{{ $policy->signoffReference }}</td>
                </tr>
            @endforeach
        </x-ui.table-shell>
    @endif

    <x-ui.table-shell :caption="__('Periode dengan scorecard')" :columns="[__('Kode'), __('Nama'), __('Rentang'), __('Status'), __('Scorecard')]">
        @foreach ($this->periods as $period)
            <tr wire:key="period-{{ $period->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $period->code }}</td>
                <td class="px-4 py-3">{{ $period->name }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $period->starts_on }} — {{ $period->ends_on }}</td>
                <td class="px-4 py-3">@include('pages.periods.partials.status-badge', ['status' => $period->status])</td>
                <td class="px-4 py-3">
                    <a href="{{ route('scorecards.period', $period) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Buka') }}<span class="sr-only"> {{ $period->code }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada periode dengan scorecard')" :description="__('Scorecard tersedia setelah periode masuk tahap In progress.')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
