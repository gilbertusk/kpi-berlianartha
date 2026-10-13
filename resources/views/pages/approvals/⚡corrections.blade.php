<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Workflow\Application\CorrectionService;
use App\Modules\Workflow\Application\WorkflowAccess;
use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Koreksi')] class extends GuardedPage {
    #[Url(as: 'status')]
    public string $status = 'pending';

    /** Public id of the request whose decision form is open. */
    #[Locked]
    public string $openId = '';

    public string $comment = '';

    /**
     * Lock version of each listed request as this account saw it.
     *
     * @var array<string, int>
     */
    #[Locked]
    public array $locks = [];

    public function mount(): void
    {
        Gate::authorize('approvals.view');
        $this->captureLocks();
    }

    public function updatedStatus(): void
    {
        Gate::authorize('approvals.view');
        unset($this->requests);
        $this->reset('openId', 'comment');
        $this->captureLocks();
    }

    /**
     * @return Collection<int, CorrectionRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        $status = CorrectionStatus::tryFrom($this->status);

        return app(WorkflowQuery::class)
            ->corrections($this->currentUser())
            ->filter(static fn (CorrectionRequest $request): bool => $status === null || $request->status === $status)
            ->values();
    }

    public function open(string $requestId): void
    {
        Gate::authorize('approvals.view');
        $this->find($requestId);
        $this->openId = $requestId;
        $this->reset('comment');
        $this->resetErrorBag();
    }

    public function approve(CorrectionService $corrections): void
    {
        $request = $this->find($this->openId);
        $this->finish(__('Koreksi disetujui: versi aktual baru dibuat dan scorecard dihitung ulang.'), fn () => $corrections->approve($this->selfActor(), $this->currentUser(), $request->id, $this->locks[$request->public_id] ?? 0, $this->comment));
    }

    public function reject(CorrectionService $corrections): void
    {
        $request = $this->find($this->openId);
        $this->finish(__('Permintaan koreksi ditolak.'), fn () => $corrections->reject($this->selfActor(), $this->currentUser(), $request->id, $this->locks[$request->public_id] ?? 0, $this->comment));
    }

    public function cancel(CorrectionService $corrections): void
    {
        $request = $this->find($this->openId);
        $this->finish(__('Permintaan koreksi dibatalkan.'), fn () => $corrections->cancel($this->selfActor(), $this->currentUser(), $request->id, $this->locks[$request->public_id] ?? 0, $this->comment));
    }

    public function mayDecide(CorrectionRequest $request): bool
    {
        return $request->status === CorrectionStatus::Pending
            && $request->requested_by_user_id !== $this->currentUser()->id
            && app(WorkflowAccess::class)->mayApproveCorrection($this->currentUser(), $request->actual->target->organization_unit_id);
    }

    public function mayCancel(CorrectionRequest $request): bool
    {
        return $request->status === CorrectionStatus::Pending && $request->requested_by_user_id === $this->currentUser()->id;
    }

    /**
     * Only a request this account may handle can be opened or decided.
     */
    private function find(string $requestId): CorrectionRequest
    {
        Gate::authorize('approvals.view');

        $request = CorrectionRequest::query()->with('actual.target.employee')->where('public_id', $requestId)->firstOrFail();
        abort_unless(app(WorkflowQuery::class)->mayHandle($this->currentUser(), $request), 403);

        return $request;
    }

    private function finish(string $message, Closure $operation): void
    {
        $done = $this->attempt('action', $message, $operation);
        unset($this->requests);

        if ($done) {
            $this->reset('openId', 'comment');
        }

        $this->captureLocks();
    }

    private function captureLocks(): void
    {
        $this->locks = [];

        foreach ($this->requests as $request) {
            $this->locks[$request->public_id] = $request->lock_version;
        }
    }
}; ?>

@php
    $statusOptions = collect(CorrectionStatus::cases())->mapWithKeys(fn (CorrectionStatus $status) => [$status->value => $status->label()])->all();
    $tone = fn (CorrectionStatus $status): string => match ($status) {
        CorrectionStatus::Pending => 'info',
        CorrectionStatus::Approved => 'success',
        CorrectionStatus::Rejected => 'danger',
        CorrectionStatus::Cancelled => 'neutral',
    };
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Koreksi')"
        :description="__('Permintaan mengganti aktual terverifikasi setelah periode masuk Review, Finalized, atau Locked. Koreksi disetujui oleh orang yang berbeda dari pemintanya; versi lama aktual dan perhitungan tetap tersimpan.')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('approvals.index')" wire:navigate>{{ __('Kembali ke approval') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter koreksi') }}">
        <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="$statusOptions" wire:model.live="status" />
    </form>

    <x-ui.table-shell :caption="__('Permintaan koreksi')" :columns="[__('Periode'), __('KPI / subjek'), __('Nilai berlaku'), __('Nilai diminta'), __('Alasan'), __('Diminta oleh'), __('Status'), __('Aksi')]">
        @foreach ($this->requests as $request)
            <tr wire:key="correction-{{ $request->public_id }}">
                <td class="px-4 py-3 whitespace-nowrap">{{ $request->period->code }} <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $request->period->status->label() }}</span></td>
                <th scope="row" class="px-4 py-3 text-start font-medium">
                    {{ $request->actual->target->kpi_name }}
                    <span class="block font-mono text-xs font-normal text-zinc-600 dark:text-zinc-400">{{ $request->actual->target->kpi_code }} · {{ $request->actual->target->subject_code }} · {{ $request->actual->windowLabel() }}</span>
                </th>
                <td class="px-4 py-3">{{ $request->currentVersion->displayValue() }} <span class="text-xs">v{{ $request->currentVersion->version_number }}</span></td>
                <td class="px-4 py-3 font-semibold">{{ $request->proposedDisplay() }}@if ($request->newVersion) <span class="text-xs font-normal">→ v{{ $request->newVersion->version_number }}</span>@endif</td>
                <td class="px-4 py-3">
                    {{ $request->reason }}
                    @if ($request->evidence_reference) <span class="block text-xs">{{ __('Bukti: :ref', ['ref' => $request->evidence_reference]) }}</span> @endif
                    @if ($request->decision_comment) <span class="block text-xs">{{ __('Keputusan: :comment', ['comment' => $request->decision_comment]) }}</span> @endif
                </td>
                <td class="px-4 py-3">{{ $request->requestedBy->name }}@if ($request->decidedBy) <span class="block text-xs">{{ __('Diputuskan: :name', ['name' => $request->decidedBy->name]) }}</span>@endif</td>
                <td class="px-4 py-3"><x-ui.status-badge :status="$tone($request->status)">{{ $request->status->label() }}</x-ui.status-badge></td>
                <td class="px-4 py-3">
                    @if ($this->mayDecide($request) || $this->mayCancel($request))
                        <x-ui.button variant="secondary" wire:click="open('{{ $request->public_id }}')">{{ __('Putuskan') }}<span class="sr-only"> {{ $request->actual->target->kpi_code }} {{ $request->actual->target->subject_code }}</span></x-ui.button>
                    @else
                        —
                    @endif
                </td>
            </tr>

            @if ($openId === $request->public_id)
                <tr wire:key="correction-form-{{ $request->public_id }}">
                    <td colspan="8" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                        <div class="grid gap-4">
                            <x-ui.textarea name="comment" :label="$this->mayCancel($request) ? __('Alasan pembatalan') : __('Catatan keputusan (wajib saat menolak)')" wire:model="comment" />
                            <div class="flex flex-wrap gap-3">
                                @if ($this->mayDecide($request))
                                    <x-ui.button wire:click="approve" wire:loading.attr="disabled" wire:target="approve,reject"
                                        wire:confirm="{{ __('Setujui koreksi ini? Versi aktual baru dibuat dan scorecard periode dihitung ulang.') }}">{{ __('Setujui koreksi') }}</x-ui.button>
                                    <x-ui.button variant="secondary" wire:click="reject" wire:loading.attr="disabled" wire:target="approve,reject">{{ __('Tolak') }}</x-ui.button>
                                @endif
                                @if ($this->mayCancel($request))
                                    <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">{{ __('Batalkan permintaan') }}</x-ui.button>
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada permintaan koreksi')" :description="__('Koreksi diminta dari halaman approval scorecard, pada aktual di belakang perhitungannya.')" />
        </x-slot:empty>
    </x-ui.table-shell>
</div>
