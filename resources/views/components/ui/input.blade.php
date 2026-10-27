@props([
    'name',
    'label',
    'id' => null,
    'type' => 'text',
    'hint' => null,
    'required' => false,
])

@php
    $id ??= $name;
    $hasError = $errors->has($name);
    $describedBy = collect([
        $hint ? $id.'-hint' : null,
        $hasError ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<div class="grid gap-1.5">
    <x-ui.label :for="$id" :required="$required">{{ $label }}</x-ui.label>

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @required($required)
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class([
            'block w-full rounded-md border bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm placeholder:text-zinc-400 dark:bg-zinc-900 dark:text-zinc-100',
            'border-zinc-300 dark:border-zinc-600' => ! $hasError,
            'border-danger-500 dark:border-danger-400' => $hasError,
        ]) }}
    />

    @if ($hint)
        <p id="{{ $id }}-hint" class="text-sm text-zinc-600 dark:text-zinc-400">{{ $hint }}</p>
    @endif

    <x-ui.field-error :for="$name" :id="$id.'-error'" />
</div>
