<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountService;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\AdministrationPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Akun')] class extends AdministrationPage {
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public string $newName = '';
    public string $newEmail = '';
    public string $newEmployeeNumber = '';

    #[Locked]
    public ?string $selected = null;

    #[Locked]
    public int $selectedVersion = 0;

    public string $mode = '';
    public string $reason = '';
    public string $employeeNumber = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $needle = trim($this->search);

        return User::query()
            ->with('employee')
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($needle !== '', function (Builder $query) use ($needle): void {
                $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';
                $query->where(fn (Builder $match) => $match
                    ->whereRaw('lower(name) like ?', [$pattern])
                    ->orWhereRaw('lower(email) like ?', [$pattern]));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->currentUser()->can('create', User::class);
    }

    #[Computed]
    public function selectedAccount(): ?User
    {
        return $this->selected === null ? null : User::query()->with('employee')->where('public_id', $this->selected)->first();
    }

    public function provision(AccountService $accounts): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newEmail' => ['required', 'email', 'max:255'],
            'newEmployeeNumber' => ['nullable', 'string', 'max:40'],
        ]);

        $result = null;

        $created = $this->attempt('action', __('Akun dibuat. Tautan untuk membuat password dikirim ke email pengguna.'), function () use ($accounts, $validated, &$result): void {
            $result = $accounts->provision(
                $this->actorFor(Capability::AccountManage),
                $validated['newName'],
                $validated['newEmail'],
                ($validated['newEmployeeNumber'] ?? '') === '' ? null : $validated['newEmployeeNumber'],
            );
        });

        if ($created && $result?->setupLinkSent === false) {
            $this->flash = __('Akun dibuat, tetapi tautan pembuatan password belum terkirim. Gunakan "Kirim reset" pada daftar akun.');
        }

        if ($created) {
            $this->reset('newName', 'newEmail', 'newEmployeeNumber');
            unset($this->rows);
        }
    }

    public function select(string $publicId, string $mode): void
    {
        abort_unless(in_array($mode, ['deactivate', 'reactivate', 'link'], true), 404);

        $account = $this->account($publicId);
        $this->authorize('update', $account);

        $this->resetErrorBag();
        $this->selected = $account->public_id;
        $this->selectedVersion = $account->lock_version;
        $this->mode = $mode;
        $this->reason = '';
        $this->employeeNumber = $account->employee?->employee_number ?? '';
    }

    public function cancel(): void
    {
        $this->reset('selected', 'selectedVersion', 'mode', 'reason', 'employeeNumber');
    }

    public function deactivate(AccountService $accounts): void
    {
        $validated = $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $account = $this->account((string) $this->selected);
        $this->authorize('update', $account);

        $this->finish($this->attempt('action', __('Akun dinonaktifkan dan sesi aktifnya diakhiri.'), fn () => $accounts->deactivate(
            $this->actorFor(Capability::AccountManage), $account->id, $this->selectedVersion, $validated['reason'],
        )));
    }

    public function reactivate(AccountService $accounts): void
    {
        $validated = $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $account = $this->account((string) $this->selected);
        $this->authorize('update', $account);

        $this->finish($this->attempt('action', __('Akun diaktifkan kembali.'), fn () => $accounts->reactivate(
            $this->actorFor(Capability::AccountManage), $account->id, $this->selectedVersion, $validated['reason'],
        )));
    }

    public function linkEmployee(AccountService $accounts): void
    {
        $validated = $this->validate([
            'employeeNumber' => ['nullable', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $account = $this->account((string) $this->selected);
        $this->authorize('update', $account);

        $this->finish($this->attempt('action', __('Hubungan akun dengan data pegawai diperbarui.'), fn () => $accounts->linkEmployee(
            $this->actorFor(Capability::AccountManage),
            $account->id,
            $this->selectedVersion,
            ($validated['employeeNumber'] ?? '') === '' ? null : $validated['employeeNumber'],
            $validated['reason'],
        )));
    }

    public function sendReset(string $publicId, AccountService $accounts): void
    {
        $account = $this->account($publicId);
        $this->authorize('update', $account);

        $this->attempt('action', __('Tautan reset password dikirim ke email akun.'), fn () => $accounts->sendPasswordReset(
            $this->actorFor(Capability::AccountManage), $account->id,
        ));
    }

    private function account(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function finish(bool $succeeded): void
    {
        if ($succeeded) {
            $this->cancel();
            unset($this->rows, $this->selectedAccount);
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Akun')"
        :description="__('Pengelolaan akun teknis. Akun nonaktif tidak dapat masuk, tetapi tetap muncul pada histori. Password tidak pernah diketahui administrator.')"
    />

    @include('pages.administration.partials.nav')

    @include('pages.administration.partials.feedback')

    <form wire:submit.prevent class="grid gap-4 sm:grid-cols-2" role="search" aria-label="{{ __('Filter akun') }}">
        <x-ui.input name="search" type="search" :label="__('Cari nama atau email')" wire:model.live.debounce.400ms="search" />
        <x-ui.select name="status" :label="__('Status')" :placeholder="__('Semua status')" :options="collect(AccountStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" wire:model.live="status" />
    </form>

    <x-ui.table-shell :caption="__('Daftar akun')" :columns="[__('Nama'), __('Email'), __('Status'), __('NIK'), __('Login terakhir'), __('2FA'), __('Aksi')]">
        @foreach ($this->rows as $account)
            <tr wire:key="account-{{ $account->public_id }}">
                <td class="px-4 py-3">{{ $account->name }}</td>
                <td class="px-4 py-3">{{ $account->email }}</td>
                <td class="px-4 py-3">
                    <x-ui.status-badge :status="$account->isActive() ? 'success' : 'danger'">{{ $account->status->label() }}</x-ui.status-badge>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ $account->employee?->employee_number ?? '—' }}</td>
                <td class="px-4 py-3">{{ $account->last_login_at?->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="px-4 py-3">{{ $account->two_factor_confirmed_at ? __('Aktif') : __('Tidak') }}</td>
                <td class="px-4 py-3">
                    @if ($this->canManage && $account->id !== auth()->id())
                        <div class="flex flex-wrap gap-2">
                            @if ($account->isActive())
                                <x-ui.button variant="ghost" wire:click="sendReset('{{ $account->public_id }}')">{{ __('Kirim reset') }}</x-ui.button>
                                <x-ui.button variant="ghost" wire:click="select('{{ $account->public_id }}', 'link')">{{ __('Hubungkan NIK') }}</x-ui.button>
                                <x-ui.button variant="secondary" wire:click="select('{{ $account->public_id }}', 'deactivate')">{{ __('Nonaktifkan') }}</x-ui.button>
                            @else
                                <x-ui.button variant="secondary" wire:click="select('{{ $account->public_id }}', 'reactivate')">{{ __('Aktifkan') }}</x-ui.button>
                            @endif
                        </div>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada akun')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->rows->links() }}

    @if ($this->selectedAccount)
        <x-ui.panel :title="match ($mode) { 'deactivate' => __('Nonaktifkan akun'), 'reactivate' => __('Aktifkan kembali akun'), default => __('Hubungkan dengan data pegawai') }" :description="$this->selectedAccount->name.' — '.$this->selectedAccount->email">
            <form wire:submit="{{ match ($mode) { 'deactivate' => 'deactivate', 'reactivate' => 'reactivate', default => 'linkEmployee' } }}" class="grid gap-4 sm:grid-cols-2">
                @if ($mode === 'link')
                    <x-ui.input name="employeeNumber" :label="__('Nomor induk pegawai')" :hint="__('Kosongkan untuk melepas hubungan.')" wire:model="employeeNumber" />
                @endif
                <x-ui.textarea name="reason" :label="__('Alasan')" required wire:model="reason" />
                <div class="flex gap-2 sm:col-span-2">
                    <x-ui.button type="submit" :variant="$mode === 'deactivate' ? 'danger' : 'primary'">{{ __('Simpan') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="cancel">{{ __('Batal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    @if ($this->canManage)
        <x-ui.panel :title="__('Buat akun')" :description="__('Akun dibuat tanpa password. Pengguna membuat password sendiri melalui tautan yang dikirim ke email.')">
            <form wire:submit="provision" class="grid gap-4 sm:grid-cols-3">
                <x-ui.input name="newName" :label="__('Nama')" required wire:model="newName" />
                <x-ui.input name="newEmail" type="email" :label="__('Email')" required wire:model="newEmail" />
                <x-ui.input name="newEmployeeNumber" :label="__('NIK (opsional)')" wire:model="newEmployeeNumber" />
                <div class="sm:col-span-3">
                    <x-ui.button type="submit">{{ __('Buat akun') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif
</div>
