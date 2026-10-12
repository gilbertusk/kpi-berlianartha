{{-- Lifecycle status with text + symbol (NFR 007). $status: LifecycleStatus|string|null --}}
@php
    $value = $status instanceof \App\Modules\KpiGovernance\Domain\LifecycleStatus ? $status->value : $status;
    [$tone, $label] = match ($value) {
        'draft' => ['neutral', __('Draft')],
        'review' => ['info', __('Review')],
        'published' => ['success', __('Published')],
        'retired' => ['warning', __('Retired')],
        default => ['neutral', '—'],
    };
@endphp

<x-ui.status-badge :status="$tone">{{ $label }}</x-ui.status-badge>
