<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <main id="main-content" tabindex="-1" class="focus:outline-none">
            {{ $slot }}
        </main>
    </flux:main>
</x-layouts::app.sidebar>
