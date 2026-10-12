{{-- Success message from the last action, or a safe domain error under the given keys. --}}
@if ($flash)
    <x-ui.alert variant="success" :title="$flash" wire:key="flash-{{ md5($flash) }}" />
@endif

@foreach ($keys ?? ['action'] as $key)
    @error($key)
        <x-ui.alert variant="danger" :title="$message" />
    @enderror
@endforeach
