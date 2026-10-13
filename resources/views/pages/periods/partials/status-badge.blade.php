{{-- Period status with text + symbol (NFR 007). $status: PeriodStatus --}}
@php
    use App\Modules\PeriodAssignment\Domain\PeriodStatus;

    $tone = match ($status) {
        PeriodStatus::Draft => 'neutral',
        PeriodStatus::Open, PeriodStatus::InProgress, PeriodStatus::Review => 'info',
        PeriodStatus::Finalized => 'success',
        PeriodStatus::Locked => 'warning',
    };
@endphp

<x-ui.status-badge :status="$tone">{{ $status->label() }}</x-ui.status-badge>
