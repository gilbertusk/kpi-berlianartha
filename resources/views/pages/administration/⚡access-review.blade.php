<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessReviewQuery;
use App\Modules\IdentityAccess\Application\Grant;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Http\Livewire\AdministrationPage;
use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Access review')] class extends AdministrationPage {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'role')]
    public string $role = '';

    #[Url(as: 'temuan')]
    public string $flag = '';

    #[Url(as: 'audit')]
    public string $auditPrefix = '';

    public function mount(): void
    {
        Gate::authorize('access-review.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'flag'], true)) {
            $this->resetPage();
        }

        if ($property === 'auditPrefix') {
            $this->resetPage('auditPage');
        }
    }

    #[Computed]
    public function scopeIsCompanyWide(): bool
    {
        return $this->access()->unitScope($this->currentUser(), Capability::AccessReviewView)->all;
    }

    #[Computed]
    public function reviewerScope(): UnitScope
    {
        return $this->access()->unitScope($this->currentUser(), Capability::AccessReviewView);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return app(AccessReviewQuery::class)->users(
            $this->reviewerScope,
            $this->search,
            Role::tryFrom($this->role),
            $this->flag === '' ? null : $this->flag,
            $this->getPage(),
        );
    }

    #[Computed]
    public function audit(): ?LengthAwarePaginator
    {
        // Audit records are RESTRICTED; only company-wide reviewers see the trail.
        return $this->scopeIsCompanyWide
            ? app(AccessReviewQuery::class)->auditTrail($this->auditPrefix, $this->getPage('auditPage'))
            : null;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function actorNames(): array
    {
        if ($this->audit === null) {
            return [];
        }

        $ids = collect($this->audit->items())->pluck('actor_identifier')->filter()->unique()->all();

        return User::query()->whereIn('public_id', $ids)->pluck('name', 'public_id')->all();
    }
}; ?>

@php
    $flagLabels = [
        AccessReviewQuery::FLAG_ADMIN_WITH_BUSINESS_ROLE => __('System Admin merangkap role bisnis'),
        AccessReviewQuery::FLAG_AUDITOR_WITH_OPERATIONAL_ROLE => __('Auditor merangkap role operasional'),
        AccessReviewQuery::FLAG_INACTIVE_WITH_GRANTS => __('Akun nonaktif masih memiliki role'),
    ];
    $review = app(AccessReviewQuery::class);
    $snapshot = app(OrganizationDirectory::class)->snapshot(app(\App\Modules\IdentityAccess\Application\AccessControl::class)->businessToday());
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Access review')"
        :description="__('Hak akses efektif saat ini (role assignment dan delegasi aktif) untuk recertification berkala. Halaman ini hanya-baca.')"
    />

    @include('pages.administration.partials.nav')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" role="search" aria-label="{{ __('Filter access review') }}">
        <x-ui.input name="search" type="search" :label="__('Cari nama atau email')" wire:model.live.debounce.400ms="search" />
        <x-ui.select name="role" :label="__('Role aktif')" :placeholder="__('Semua role')" :options="collect(Role::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" wire:model.live="role" />
        <x-ui.select name="flag" :label="__('Temuan')" :placeholder="__('Semua akun')" :options="$flagLabels" wire:model.live="flag" />
    </form>

    <x-ui.table-shell :caption="__('Hak akses efektif per akun')" :columns="[__('Akun'), __('Status'), __('Pegawai'), __('Hak akses efektif'), __('Temuan')]">
        @foreach ($this->rows as $account)
            @php
                $grantView = $review->grantsOf($account, $this->reviewerScope);
                $grants = $grantView['visible'];
                $flags = $review->flagsOf($account);
                $homeUnit = $account->employee ? app(OrganizationDirectory::class)->homeUnitId($account->employee->id, $snapshot->businessDate) : null;
            @endphp
            <tr wire:key="review-{{ $account->public_id }}">
                <td class="px-4 py-3">
                    {{ $account->name }}
                    <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $account->email }}</span>
                    <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Login terakhir') }}: {{ $account->last_login_at?->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i') ?? '—' }}</span>
                </td>
                <td class="px-4 py-3">
                    <x-ui.status-badge :status="$account->isActive() ? 'success' : 'danger'">{{ $account->status->label() }}</x-ui.status-badge>
                </td>
                <td class="px-4 py-3">
                    @if ($account->employee)
                        <span class="font-mono text-xs">{{ $account->employee->employee_number }}</span>
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $homeUnit ? $snapshot->pathLabel($homeUnit) : __('Tanpa home team') }}</span>
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3">
                    @forelse ($grants as $grant)
                        <span class="block">
                            {{ $grant->role->label() }}
                            <span class="font-mono text-xs">({{ $grant->companyWide ? __('company') : ($grant->unitCode ?? __('data sendiri')) }})</span>
                            @if ($grant->source === Grant::SOURCE_DELEGATION)
                                <x-ui.status-badge status="info">{{ __('Delegasi s.d.') }} {{ $grant->effectiveUntil }}</x-ui.status-badge>
                            @elseif ($grant->effectiveUntil)
                                <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('s.d.') }} {{ $grant->effectiveUntil }}</span>
                            @endif
                        </span>
                    @empty
                        <span class="text-zinc-600 dark:text-zinc-400">{{ $grantView['hidden'] > 0 ? '' : __('Tidak ada') }}</span>
                    @endforelse
                    @if ($grantView['hidden'] > 0)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __('+:count hak akses di luar scope Anda', ['count' => $grantView['hidden']]) }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @forelse ($flags as $flag)
                        <x-ui.status-badge status="warning" class="mb-1">{{ $flagLabels[$flag] }}</x-ui.status-badge>
                    @empty
                        —
                    @endforelse
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada akun')" :description="__('Tidak ada akun untuk filter tersebut dalam scope Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    <section class="flex flex-col gap-4" aria-labelledby="audit-heading">
        <h2 id="audit-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Audit trail akses dan organisasi') }}</h2>

        @if ($this->audit === null)
            <x-ui.alert variant="info" :title="__('Audit trail hanya tersedia untuk reviewer dengan scope company.')" />
        @else
            <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3" aria-label="{{ __('Filter audit trail') }}">
                <x-ui.select name="auditPrefix" :label="__('Kategori')" :placeholder="__('Semua kategori')" :options="collect(AccessReviewQuery::AUDIT_PREFIXES)->mapWithKeys(fn ($prefix) => [$prefix => rtrim($prefix, '.')])->all()" wire:model.live="auditPrefix" />
            </form>

            <x-ui.table-shell :caption="__('Audit trail')" :columns="[__('Waktu'), __('Aksi'), __('Pelaku'), __('Objek'), __('Alasan')]">
                @foreach ($this->audit as $entry)
                    <tr wire:key="audit-{{ $entry->public_id }}">
                        <td class="px-4 py-3 whitespace-nowrap">{{ $entry->occurred_at->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $entry->action }}</td>
                        <td class="px-4 py-3">
                            {{ $entry->actor_identifier ? ($this->actorNames[$entry->actor_identifier] ?? $entry->actor_identifier) : ($entry->actor_type === 'system' ? __('Sistem') : __('Anonim')) }}
                            @if ($entry->actor_role)
                                <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $entry->actor_role }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $entry->entity_type }}:{{ $entry->entity_id }}</td>
                        <td class="px-4 py-3">{{ $entry->reason ?? '—' }}</td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state :title="__('Belum ada audit')" />
                </x-slot:empty>
            </x-ui.table-shell>

            {{ $this->audit->links() }}
        @endif
    </section>
</div>
