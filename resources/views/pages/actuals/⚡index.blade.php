<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Aktual')] class extends GuardedPage {
    public function mount(): void
    {
        Gate::authorize('actuals.view');
    }

    /**
     * Opened periods that hold something this account may see: units of its
     * scope, or its own targets.
     *
     * @return Collection<int, AssessmentPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return AssessmentPeriod::query()
            ->with('scopeUnit')
            ->whereNotIn('status', [PeriodStatus::Draft->value, PeriodStatus::Open->value])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (AssessmentPeriod $period): bool => $this->currentUser()->can('viewActuals', $period))
            ->values();
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Aktual')"
        :description="__('Realisasi KPI per periode. Aktual diisi selama periode berstatus In progress, diajukan beserta bukti, lalu diverifikasi Team Leader.')"
    />

    <x-ui.table-shell :caption="__('Periode dengan aktual')" :columns="[__('Kode'), __('Nama'), __('Rentang'), __('Status'), __('Aktual')]">
        @foreach ($this->periods as $period)
            <tr wire:key="period-{{ $period->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $period->code }}</td>
                <td class="px-4 py-3">{{ $period->name }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $period->starts_on }} — {{ $period->ends_on }}</td>
                <td class="px-4 py-3">@include('pages.periods.partials.status-badge', ['status' => $period->status])</td>
                <td class="px-4 py-3">
                    <a href="{{ route('actuals.entry', $period) }}" wire:navigate class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Buka') }}<span class="sr-only"> {{ $period->code }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada periode untuk diisi')" :description="__('Aktual tersedia setelah periode masuk tahap In progress.')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
