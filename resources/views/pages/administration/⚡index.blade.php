<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Administration')] class extends Component {
    public function mount(): void
    {
        Gate::authorize('administration.view');
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Administration')"
        :description="__('Kelola struktur organisasi, penugasan, akun, role, delegasi, dan tinjauan akses sesuai kewenangan Anda.')"
    />

    @include('pages.administration.partials.nav')

    <x-ui.alert variant="info" :title="__('Akses mengikuti role dan scope organisasi')">
        {{ __('Menu di atas hanya menampilkan bagian yang diizinkan untuk akun Anda. Setiap perubahan dicatat pada audit trail dan wajib memiliki alasan.') }}
    </x-ui.alert>
</div>
