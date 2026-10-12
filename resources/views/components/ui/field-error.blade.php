@props([
    'for',
    'id' => null,
])

@error($for)
    <p id="{{ $id ?? $for.'-error' }}" {{ $attributes->class('flex items-start gap-1.5 text-sm text-danger-700 dark:text-danger-400') }}>
        <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
        </svg>
        <span><span class="sr-only">{{ __('Kesalahan') }}: </span>{{ $message }}</span>
    </p>
@enderror
