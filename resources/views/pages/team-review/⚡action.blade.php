<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Corrective action')] class extends GuardedPage {
    #[Locked]
    public string $actionId = '';

    /** Lock version of the action as this account saw it. */
    #[Locked]
    public int $lock = 0;

    public string $note = '';

    public string $evidence = '';

    public string $dueOn = '';

    public function mount(CorrectiveAction $action): void
    {
        $this->authorize('viewCorrectiveAction', $action);
        $this->actionId = $action->public_id;
        // The version shown on the page, read from the database.
        $this->lock = $this->action->lock_version;
    }

    #[Computed]
    public function action(): CorrectiveAction
    {
        return CorrectiveAction::query()
            ->with(['issue.kpis', 'issue.reportedBy', 'unit', 'owner', 'reviewer', 'events.actor'])
            ->where('public_id', $this->actionId)
            ->firstOrFail();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return $this->action->owner_user_id === $this->currentUser()->id;
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(TeamReviewAccess::class)->canManage($this->currentUser(), $this->action->organization_unit_id);
    }

    public function start(TeamReviewService $service): void
    {
        $this->run(__('Tindakan dimulai.'), fn () => $service->start($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock));
    }

    public function wait(TeamReviewService $service): void
    {
        $this->run(__('Tindakan ditandai menunggu.'), fn () => $service->wait($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock, $this->note));
    }

    public function resume(TeamReviewService $service): void
    {
        $this->run(__('Tindakan dilanjutkan.'), fn () => $service->resume($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock));
    }

    public function reportCompletion(TeamReviewService $service): void
    {
        $this->run(__('Penyelesaian dilaporkan dan menunggu verifikasi.'), fn () => $service->reportCompletion($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock, $this->note, $this->evidence));
    }

    public function verify(TeamReviewService $service): void
    {
        $this->run(__('Tindakan diverifikasi.'), fn () => $service->verify($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock, $this->note));
    }

    public function sendBack(TeamReviewService $service): void
    {
        $this->run(__('Penyelesaian dikembalikan ke PIC.'), fn () => $service->sendBack($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock, $this->note));
    }

    public function close(TeamReviewService $service): void
    {
        $this->run(__('Tindakan ditutup.'), fn () => $service->close($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock));
    }

    public function reschedule(TeamReviewService $service): void
    {
        $this->run(__('Tenggat diubah.'), fn () => $service->reschedule($this->selfActor(), $this->currentUser(), $this->action->id, $this->lock, $this->dueOn, $this->note));
    }

    /**
     * Access is checked again on every action; the service decides whether
     * this account is the owner or a manager of the team.
     */
    private function run(string $message, Closure $operation): void
    {
        $this->authorize('viewCorrectiveAction', $this->action);

        $done = $this->attempt('action', $message, $operation);
        unset($this->action, $this->isOwner, $this->canManage);

        if ($done) {
            $this->lock = $this->action->lock_version;
            $this->reset('note', 'evidence', 'dueOn');
        }
    }
}; ?>

@php
    $action = $this->action;
    $issue = $action->issue;
    $today = $this->businessToday();
    $zone = config('app.business_timezone', 'Asia/Jakarta');
    $ownerCanWork = $this->isOwner && $action->status->isOutstanding();
    $managerCanVerify = $this->canManage && ! $this->isOwner && $action->awaitsVerification();
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Corrective action')" :description="$action->unit->code.' · '.__('PIC: :name', ['name' => $action->owner->name]).' · '.__('Tenggat: :date', ['date' => $action->due_on])">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('team-review.index')" wire:navigate>{{ __('Kembali ke team review') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <x-ui.panel :title="__('Status: :status', ['status' => $action->status->label()])">
        @if ($action->isOverdueOn($today))
            <p class="mb-3"><x-ui.status-badge status="danger">{{ __('Melewati tenggat :date', ['date' => $action->due_on]) }}</x-ui.status-badge></p>
        @elseif ($action->awaitsVerification())
            <p class="mb-3"><x-ui.status-badge status="info">{{ __('Penyelesaian dilaporkan, menunggu verifikasi') }}</x-ui.status-badge></p>
        @endif

        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div class="sm:col-span-2"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Tindakan') }}</dt><dd>{{ $action->action }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Masalah (:date)', ['date' => $issue->review_date]) }}</dt><dd>{{ $issue->problem }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Penyebab') }}</dt><dd>{{ $issue->cause_code ?? '—' }} {{ $issue->cause_note }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('KPI terdampak') }}</dt><dd class="font-mono text-xs">{{ $issue->kpis->pluck('code')->implode(', ') ?: '—' }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Prioritas') }}</dt><dd>{{ $action->priority->label() }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Dicatat oleh') }}</dt><dd>{{ $issue->reportedBy->name }}</dd></div>
            @if ($action->waiting_reason)
                <div class="sm:col-span-2"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Menunggu karena') }}</dt><dd>{{ $action->waiting_reason }}</dd></div>
            @endif
            @if ($action->completion_note)
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Catatan penyelesaian') }}</dt><dd>{{ $action->completion_note }}</dd></div>
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Bukti penyelesaian') }}</dt><dd>{{ $action->evidence_reference ?? '—' }}</dd></div>
            @endif
            @if ($action->reviewer)
                <div><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Diverifikasi oleh') }}</dt><dd>{{ $action->reviewer->name }}</dd></div>
            @endif
        </dl>
    </x-ui.panel>

    @if ($ownerCanWork)
        <x-ui.panel :title="__('Tindak lanjut PIC')" :description="__('Laporkan penyelesaian saat tindakan selesai; orang lain akan memverifikasinya.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.textarea name="note" :label="__('Catatan (alasan menunggu atau catatan penyelesaian)')" wire:model="note" />
                <x-ui.input name="evidence" :label="__('Referensi bukti penyelesaian')" wire:model="evidence" />
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    @if ($action->status === ActionStatus::Open)
                        <x-ui.button wire:click="start" wire:loading.attr="disabled">{{ __('Mulai kerjakan') }}</x-ui.button>
                    @elseif ($action->status === ActionStatus::Waiting)
                        <x-ui.button wire:click="resume" wire:loading.attr="disabled">{{ __('Lanjutkan') }}</x-ui.button>
                    @elseif (! $action->awaitsVerification())
                        <x-ui.button wire:click="reportCompletion" wire:loading.attr="disabled">{{ __('Laporkan selesai') }}</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="wait" wire:loading.attr="disabled">{{ __('Tandai menunggu') }}</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.panel>
    @endif

    @if ($this->canManage && $action->status !== ActionStatus::Closed)
        <x-ui.panel :title="__('Pengelola review')" :description="__('Verifikasi dilakukan oleh pengelola yang bukan PIC tindakan ini.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.textarea name="note" :id="'manager-note'" :label="__('Catatan / alasan')" wire:model="note" />
                @if ($action->status->isOutstanding())
                    <x-ui.input name="dueOn" type="date" :label="__('Tenggat baru')" wire:model="dueOn" />
                @endif
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    @if ($managerCanVerify)
                        <x-ui.button wire:click="verify" wire:loading.attr="disabled">{{ __('Verifikasi selesai') }}</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="sendBack" wire:loading.attr="disabled">{{ __('Kembalikan ke PIC') }}</x-ui.button>
                    @endif
                    @if ($action->status === ActionStatus::Verified)
                        <x-ui.button wire:click="close" wire:loading.attr="disabled">{{ __('Tutup tindakan') }}</x-ui.button>
                    @endif
                    @if ($action->status->isOutstanding())
                        <x-ui.button variant="secondary" wire:click="reschedule" wire:loading.attr="disabled">{{ __('Ubah tenggat') }}</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.panel>
    @endif

    <x-ui.table-shell :caption="__('Riwayat tindakan')" :columns="[__('Waktu'), __('Kejadian'), __('Status'), __('Oleh'), __('Catatan')]">
        @foreach ($action->events as $event)
            <tr wire:key="event-{{ $event->id }}">
                <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $event->occurred_at->setTimezone($zone)->format('Y-m-d H:i') }}</td>
                <th scope="row" class="px-4 py-3 text-start font-mono text-xs font-medium">{{ $event->event }}</th>
                <td class="px-4 py-3">{{ $event->from_status ? $event->from_status.' → ' : '' }}{{ $event->to_status }}</td>
                <td class="px-4 py-3">{{ $event->actor->name }}</td>
                <td class="px-4 py-3">{{ $event->note ?? '—' }}</td>
            </tr>
        @endforeach
    </x-ui.table-shell>
</div>
