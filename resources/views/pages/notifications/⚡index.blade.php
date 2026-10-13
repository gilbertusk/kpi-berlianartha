<?php

use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Infrastructure\Persistence\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Notifikasi')] class extends GuardedPage {
    use WithPagination;

    #[Url(as: 'belum_dibaca')]
    public bool $unreadOnly = false;

    public function updatedUnreadOnly(): void
    {
        $this->resetPage();
    }

    /**
     * Only this account's notifications.
     *
     * @return LengthAwarePaginator<int, UserNotification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return UserNotification::query()
            ->where('user_id', $this->currentUser()->id)
            ->when($this->unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * @return array<string, bool>
     */
    #[Computed]
    public function preferences(): array
    {
        return app(NotificationService::class)->emailPreferences($this->currentUser());
    }

    #[Computed]
    public function emailEnabled(): bool
    {
        return app(NotificationService::class)->emailEnabled();
    }

    public function markAllRead(NotificationService $notifications): void
    {
        $count = $notifications->markAllRead($this->currentUser());
        $this->flash = __(':count notifikasi ditandai sudah dibaca.', ['count' => $count]);
        unset($this->notifications);
    }

    public function toggleEmail(string $category, NotificationService $notifications): void
    {
        $category = NotificationCategory::tryFrom($category);
        abort_if($category === null, 422);

        $notifications->setEmailPreference($this->currentUser(), $category, ! $this->preferences[$category->value]);
        unset($this->preferences);
        $this->flash = __('Preferensi email disimpan.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header :title="__('Notifikasi')" :description="__('Tugas, pengembalian, koreksi, dan pengingat untuk akun Anda. Membuka notifikasi membawa Anda ke record terkait; akses ke record itu tetap diperiksa di sana.')">
        <x-slot:actions>
            <x-ui.button variant="secondary" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead">{{ __('Tandai semua sudah dibaca') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="unreadOnly" class="size-4"> {{ __('Hanya yang belum dibaca') }}</label>

    <x-ui.table-shell :caption="__('Notifikasi Anda')" :columns="[__('Waktu'), __('Kategori'), __('Isi'), __('Status'), __('Buka')]">
        @foreach ($this->notifications as $notification)
            <tr wire:key="notification-{{ $notification->public_id }}">
                <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $notification->created_at->setTimezone(config('app.business_timezone', 'Asia/Jakarta'))->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-3">{{ $notification->category->label() }}</td>
                <th scope="row" class="px-4 py-3 text-start font-medium">
                    {{ $notification->title }}
                    <span class="block text-sm font-normal text-zinc-700 dark:text-zinc-300">{{ $notification->body }}</span>
                </th>
                <td class="px-4 py-3">
                    <x-ui.status-badge :status="$notification->read_at === null ? 'info' : 'neutral'">{{ $notification->read_at === null ? __('Belum dibaca') : __('Sudah dibaca') }}</x-ui.status-badge>
                </td>
                <td class="px-4 py-3">
                    <a href="{{ route('notifications.open', $notification) }}" class="font-medium text-brand-800 underline hover:no-underline dark:text-brand-200">
                        {{ __('Buka') }}<span class="sr-only"> {{ $notification->title }}</span>
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Tidak ada notifikasi')" :description="__('Notifikasi muncul saat ada tugas atau perubahan yang menyangkut Anda.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $this->notifications->links() }}

    <x-ui.panel :title="__('Preferensi email')" :description="__('Notifikasi di aplikasi selalu dikirim. Salinan email dapat dimatikan per kategori.')">
        @unless ($this->emailEnabled)
            <p class="mb-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Channel email belum diaktifkan untuk sistem ini (DEC-014). Pilihan di bawah berlaku setelah channel email aktif.') }}</p>
        @endunless

        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach (NotificationCategory::cases() as $category)
                <li wire:key="preference-{{ $category->value }}">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="size-4" @checked($this->preferences[$category->value]) wire:click="toggleEmail('{{ $category->value }}')">
                        {{ __('Email untuk :category', ['category' => $category->label()]) }}
                    </label>
                </li>
            @endforeach
        </ul>
    </x-ui.panel>
</div>
