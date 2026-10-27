@props([
    'for',
    'required' => false,
])

<label for="{{ $for }}" {{ $attributes->class('block text-sm font-semibold text-zinc-700 dark:text-zinc-300') }}>
    {{ $slot }}@if ($required)<span class="text-danger-700 dark:text-danger-400" aria-hidden="true"> *</span><span class="sr-only"> ({{ __('wajib') }})</span>@endif
</label>
