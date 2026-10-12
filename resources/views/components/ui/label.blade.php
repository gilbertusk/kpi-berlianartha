@props([
    'for',
    'required' => false,
])

<label for="{{ $for }}" {{ $attributes->class('block text-sm font-medium text-zinc-800 dark:text-zinc-100') }}>
    {{ $slot }}@if ($required)<span class="text-danger-700 dark:text-danger-400" aria-hidden="true"> *</span><span class="sr-only"> ({{ __('wajib') }})</span>@endif
</label>
