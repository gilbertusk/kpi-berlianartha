{{-- Target state with text + symbol (NFR 007). $state: missing|draft|submitted|approved --}}
@php
    [$tone, $label] = match ($state) {
        'draft' => ['neutral', __('Draft')],
        'submitted' => ['info', __('Menunggu persetujuan')],
        'approved' => ['success', __('Final')],
        default => ['warning', __('Belum diisi')],
    };
@endphp

<x-ui.status-badge :status="$tone">{{ $label }}</x-ui.status-badge>
