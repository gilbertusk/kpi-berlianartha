<?php

use App\Models\User;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Reporting\Application\TeamOverviewQuery;
use App\Modules\TeamReview\Application\ActionDraft;
use App\Modules\TeamReview\Application\IssueDraft;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Application\TeamReviewQuery;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\TeamReview\Domain\ActionPriority;
use App\Modules\TeamReview\Domain\IssueContext;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\TeamReviewIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Daily team review')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'tindakan_status')]
    public string $actionFilter = 'outstanding';

    public string $unit = '';

    public string $reviewDate = '';

    public string $problem = '';

    public string $causeCode = '';

    public string $causeNote = '';

    public string $contextType = '';

    public string $contextReference = '';

    public string $kpiCodes = '';

    /** Public id of the issue whose action form is open. */
    #[Locked]
    public string $issueId = '';

    public string $actionText = '';

    public string $owner = '';

    public string $dueOn = '';

    public string $priority = 'medium';

    public function mount(): void
    {
        Gate::authorize('team-review.view');
        $this->reviewDate = $this->businessToday();
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage('tindakan');
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->currentUser()->can('team-review.manage');
    }

    /**
     * Teams this account may record a review for.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        $options = [];

        foreach (app(TeamOverviewQuery::class)->teams($this->currentUser()) as $team) {
            $options[$team->code] = "{$team->code} — {$team->name}";
        }

        return $options;
    }

    /**
     * @return LengthAwarePaginator<int, CorrectiveAction>
     */
    #[Computed]
    public function actions(): LengthAwarePaginator
    {
        return app(TeamReviewQuery::class)->actions(
            $this->currentUser(),
            app(TeamReviewAccess::class)->viewScope($this->currentUser()),
            array_key_exists($this->actionFilter, TeamReviewQuery::ACTION_FILTERS) ? $this->actionFilter : null,
            $this->getPage('tindakan'),
        );
    }

    /**
     * @return LengthAwarePaginator<int, TeamReviewIssue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return app(TeamReviewQuery::class)->issues(app(TeamReviewAccess::class)->viewScope($this->currentUser()), null, $this->getPage('masalah'));
    }

    /**
     * @return array<string, string> user public id => name
     */
    #[Computed]
    public function ownerOptions(): array
    {
        $issue = $this->openIssue();

        if ($issue === null) {
            return [];
        }

        $candidates = app(TeamReviewQuery::class)->ownerCandidates($issue->organization_unit_id);
        $options = [];

        // Accounts are offered by public id; internal ids stay on the server.
        foreach (User::query()->whereIn('id', array_keys($candidates))->orderBy('name')->get(['id', 'public_id']) as $candidate) {
            $options[$candidate->public_id] = $candidates[$candidate->id];
        }

        return $options;
    }

    public function recordIssue(TeamReviewService $service): void
    {
        Gate::authorize('team-review.manage');
        abort_unless(array_key_exists($this->unit, $this->unitOptions), 422);

        $unitId = collect(app(TeamOverviewQuery::class)->teams($this->currentUser()))->firstWhere('code', $this->unit)?->id;
        abort_if($unitId === null, 422);

        $codes = array_values(array_unique(array_filter(array_map(static fn (string $code): string => strtoupper(trim($code)), explode(',', mb_substr($this->kpiCodes, 0, 2000))))));

        if (count($codes) > 20) {
            $this->addError('action', __('Maksimal 20 KPI terdampak per masalah.'));

            return;
        }

        $kpis = KpiDefinition::query()->whereIn('code', $codes)->pluck('public_id', 'code');

        if (count($codes) !== $kpis->count()) {
            $this->addError('action', __('Kode KPI tidak ditemukan: :codes', ['codes' => implode(', ', array_diff($codes, $kpis->keys()->all()))]));

            return;
        }

        $done = $this->attempt('action', __('Masalah dicatat. Tambahkan corrective action dengan PIC dan tenggat.'), function () use ($service, $unitId, $kpis): void {
            $service->recordIssue($this->selfActor(), $this->currentUser(), new IssueDraft(
                unitId: $unitId,
                reviewDate: $this->reviewDate,
                problem: $this->problem,
                causeCode: $this->causeCode,
                causeNote: $this->causeNote,
                contextType: $this->contextType,
                contextReference: $this->contextReference,
                kpiDefinitionIds: array_values($kpis->all()),
            ));
        });

        if ($done) {
            $this->reset('problem', 'causeCode', 'causeNote', 'contextType', 'contextReference', 'kpiCodes');
            unset($this->issues);
        }
    }

    public function openActionForm(string $issueId): void
    {
        Gate::authorize('team-review.manage');
        $issue = TeamReviewIssue::query()->where('public_id', $issueId)->firstOrFail();
        abort_unless(app(TeamReviewAccess::class)->canManage($this->currentUser(), $issue->organization_unit_id), 403);

        $this->issueId = $issueId;
        $this->reset('actionText', 'owner', 'dueOn');
        $this->priority = 'medium';
        unset($this->ownerOptions);
    }

    public function assignAction(TeamReviewService $service): void
    {
        Gate::authorize('team-review.manage');
        $issue = $this->openIssue();
        abort_if($issue === null, 404);
        abort_unless(array_key_exists($this->owner, $this->ownerOptions), 422);
        $ownerId = (int) User::query()->where('public_id', $this->owner)->valueOrFail('id');

        $done = $this->attempt('action', __('Corrective action ditugaskan.'), function () use ($service, $issue, $ownerId): void {
            $service->assignAction($this->selfActor(), $this->currentUser(), $issue->id, new ActionDraft(
                action: $this->actionText,
                ownerUserId: $ownerId,
                dueOn: $this->dueOn,
                priority: $this->priority,
            ));
        });

        if ($done) {
            $this->reset('issueId', 'actionText', 'owner', 'dueOn');
            unset($this->issues, $this->actions);
        }
    }

    private function openIssue(): ?TeamReviewIssue
    {
        if ($this->issueId === '') {
            return null;
        }

        $issue = TeamReviewIssue::query()->where('public_id', $this->issueId)->first();

        return $issue !== null && app(TeamReviewAccess::class)->canManage($this->currentUser(), $issue->organization_unit_id) ? $issue : null;
    }
}; ?>

@php
    $today = $this->businessToday();
    $priorityOptions = collect(ActionPriority::cases())->mapWithKeys(fn ($priority) => [$priority->value => $priority->label()])->all();
    $contextOptions = collect(IssueContext::cases())->mapWithKeys(fn ($context) => [$context->value => $context->label()])->all();
    $link = 'font-medium text-brand-800 underline hover:no-underline dark:text-brand-200';
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Daily team review')" :description="__('Masalah harian team, penyebab, KPI terdampak, dan corrective action dengan PIC serta tenggat. Setiap perubahan status tercatat di riwayat tindakan.')" />

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <section class="flex flex-col gap-3" aria-labelledby="actions-heading">
        <h2 id="actions-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Corrective action') }}</h2>

        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter corrective action') }}">
            <x-ui.select name="actionFilter" :label="__('Tampilkan')" :placeholder="__('Semua')" :options="TeamReviewQuery::ACTION_FILTERS" wire:model.live="actionFilter" />
        </form>

        <x-ui.table-shell :caption="__('Corrective action dalam scope Anda')" :columns="[__('Tindakan'), __('Unit'), __('PIC'), __('Tenggat'), __('Prioritas'), __('Status'), __('Buka')]">
            @foreach ($this->actions as $action)
                <tr wire:key="action-{{ $action->public_id }}">
                    <th scope="row" class="px-4 py-3 text-start font-medium">{{ \Illuminate\Support\Str::limit($action->action, 90) }}</th>
                    <td class="px-4 py-3 font-mono text-xs">{{ $action->unit->code }}</td>
                    <td class="px-4 py-3">{{ $action->owner->name }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        {{ $action->due_on }}
                        @if ($action->isOverdueOn($today))
                            <x-ui.status-badge status="danger">{{ __('Lewat tenggat') }}</x-ui.status-badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $action->priority->label() }}</td>
                    <td class="px-4 py-3">
                        {{ $action->status->label() }}
                        @if ($action->awaitsVerification())
                            <span class="block text-xs">{{ __('Menunggu verifikasi') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3"><a href="{{ route('team-review.action', $action) }}" wire:navigate class="{{ $link }}">{{ __('Buka') }}<span class="sr-only"> {{ \Illuminate\Support\Str::limit($action->action, 40) }}</span></a></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Tidak ada corrective action')" :description="__('Tidak ada tindakan yang cocok dengan filter dalam scope Anda.')" />
            </x-slot:empty>
        </x-ui.table-shell>

        {{ $this->actions->links() }}
    </section>

    @if ($this->canManage)
        <x-ui.panel :title="__('Catat masalah')" :description="__('Masalah wajib diisi. Referensi work order, mesin, shift, atau customer berupa teks karena master datanya belum ditetapkan (DEC-016).')">
            <form wire:submit="recordIssue" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="unit" :label="__('Team')" :placeholder="__('Pilih team')" :options="$this->unitOptions" required wire:model="unit" />
                <x-ui.input name="reviewDate" type="date" :label="__('Tanggal review')" required wire:model="reviewDate" />
                <div class="sm:col-span-2"><x-ui.textarea name="problem" :label="__('Masalah')" required wire:model="problem" /></div>
                <x-ui.input name="causeCode" :label="__('Kode penyebab (opsional)')" wire:model="causeCode" />
                <x-ui.input name="kpiCodes" :label="__('Kode KPI terdampak (pisahkan dengan koma)')" wire:model="kpiCodes" />
                <div class="sm:col-span-2"><x-ui.textarea name="causeNote" :label="__('Catatan penyebab')" wire:model="causeNote" /></div>
                <x-ui.select name="contextType" :label="__('Terkait dengan (opsional)')" :placeholder="__('Tidak ada')" :options="$contextOptions" wire:model="contextType" />
                <x-ui.input name="contextReference" :label="__('Referensi')" wire:model="contextReference" />
                <div class="sm:col-span-2"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="recordIssue">{{ __('Catat masalah') }}</x-ui.button></div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->issues->total() > 0 || $this->canManage)
        <section class="flex flex-col gap-3" aria-labelledby="issues-heading">
            <h2 id="issues-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Masalah yang dicatat') }}</h2>

            <x-ui.table-shell :caption="__('Masalah dalam scope Anda')" :columns="[__('Tanggal'), __('Unit'), __('Masalah'), __('Penyebab'), __('KPI terdampak'), __('Tindakan'), __('Aksi')]">
                @foreach ($this->issues as $issue)
                    <tr wire:key="issue-{{ $issue->public_id }}">
                        <td class="px-4 py-3 whitespace-nowrap">{{ $issue->review_date }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $issue->unit->code }}</td>
                        <th scope="row" class="px-4 py-3 text-start font-medium">
                            {{ $issue->problem }}
                            @if ($issue->context_type)
                                <span class="block text-xs font-normal">{{ $issue->context_type->label() }}: {{ $issue->context_reference }}</span>
                            @endif
                        </th>
                        <td class="px-4 py-3">{{ $issue->cause_code ?? '—' }}@if ($issue->cause_note) <span class="block text-xs">{{ $issue->cause_note }}</span>@endif</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $issue->kpis->pluck('code')->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $issue->actions_count }}</td>
                        <td class="px-4 py-3">
                            @if ($this->canManage)
                                <x-ui.button variant="secondary" wire:click="openActionForm('{{ $issue->public_id }}')">{{ __('Tambah tindakan') }}<span class="sr-only"> {{ $issue->review_date }} {{ $issue->unit->code }}</span></x-ui.button>
                            @else
                                —
                            @endif
                        </td>
                    </tr>

                    @if ($issueId === $issue->public_id)
                        <tr wire:key="issue-form-{{ $issue->public_id }}">
                            <td colspan="7" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                                <form wire:submit="assignAction" class="grid gap-4 sm:grid-cols-3">
                                    <div class="sm:col-span-3"><x-ui.textarea name="actionText" :label="__('Corrective action')" required wire:model="actionText" /></div>
                                    <x-ui.select name="owner" :label="__('PIC')" :placeholder="__('Pilih PIC')" :options="$this->ownerOptions" required wire:model="owner" />
                                    <x-ui.input name="dueOn" type="date" :label="__('Tenggat')" required wire:model="dueOn" />
                                    <x-ui.select name="priority" :label="__('Prioritas')" :options="$priorityOptions" required wire:model="priority" />
                                    <div class="sm:col-span-3"><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="assignAction">{{ __('Tugaskan') }}</x-ui.button></div>
                                </form>
                            </td>
                        </tr>
                    @endif
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Belum ada masalah yang dicatat')" :description="__('Masalah yang dicatat pada daily team review muncul di sini.')" />
                </x-slot:empty>
            </x-ui.table-shell>

            {{ $this->issues->links() }}
        </section>
    @endif
</div>
