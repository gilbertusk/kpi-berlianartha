{{-- $entries: paginator of AuditLog, $actorNames: array<public_id, name> --}}
<section class="flex flex-col gap-4" aria-labelledby="audit-heading">
    <h2 id="audit-heading" class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Riwayat audit') }}</h2>

    <x-ui.table-shell :caption="__('Riwayat audit')" :columns="[__('Waktu'), __('Aksi'), __('Pelaku'), __('Perubahan'), __('Alasan')]">
        @foreach ($entries as $entry)
            <tr wire:key="audit-{{ $entry->public_id }}">
                <td class="px-4 py-3 whitespace-nowrap">{{ $entry->occurred_at->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $entry->action }}</td>
                <td class="px-4 py-3">
                    {{ $entry->actor_identifier ? ($actorNames[$entry->actor_identifier] ?? $entry->actor_identifier) : __('Sistem') }}
                    @if ($entry->actor_role)
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $entry->actor_role }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs">
                    @foreach ((array) $entry->after_values as $key => $value)
                        <span class="block"><span class="font-medium">{{ $key }}</span>: {{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value, JSON_UNESCAPED_UNICODE) }}</span>
                    @endforeach
                </td>
                <td class="px-4 py-3">{{ $entry->reason ?? '—' }}</td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada audit')" />
        </x-slot:empty>
    </x-ui.table-shell>

    {{ $entries->links() }}
</section>
