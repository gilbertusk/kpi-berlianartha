<x-layouts::app :title="$title">
    <div class="flex flex-col gap-6">
        <x-ui.page-header :title="$title" />

        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <x-ui.empty-state
                :title="__('Modul belum tersedia')"
                :description="__('Fitur pada menu ini akan dibangun pada tahap implementasi berikutnya. Halaman ini tidak menampilkan data apa pun.')"
            />
        </div>
    </div>
</x-layouts::app>
