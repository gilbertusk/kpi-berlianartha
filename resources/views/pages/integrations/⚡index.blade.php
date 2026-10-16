<?php

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\Livewire\GuardedPage;
use App\Modules\Integration\Application\ExceptionRegister;
use App\Modules\Integration\Application\InboundRunner;
use App\Modules\Integration\Application\IntegrationQuery;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationException;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Integrasi')] class extends GuardedPage {
    #[Locked]
    public string $deciding = '';

    public string $decision = '';

    public string $reason = '';

    public bool $allExceptions = false;

    public function mount(): void
    {
        Gate::authorize('integrations.view');
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function overview(): array
    {
        return app(IntegrationQuery::class)->overview();
    }

    #[Computed]
    public function runs(): \Illuminate\Support\Collection
    {
        return app(IntegrationQuery::class)->recentRuns();
    }

    #[Computed]
    public function exceptions(): \Illuminate\Support\Collection
    {
        return app(IntegrationQuery::class)->exceptions(! $this->allExceptions);
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->access()->allows($this->currentUser(), Capability::IntegrationManage);
    }

    public function runNow(string $connection, InboundRunner $runner): void
    {
        Gate::authorize('integrations.view');
        abort_unless($this->canManage, 403);
        $result = $runner->run($connection, $this->selfActor());
        $this->flash = $result['skipped'] !== []
            ? __('Tidak dijalankan: :reason', ['reason' => implode(' ', $result['skipped'])])
            : __(':n file diproses.', ['n' => count($result['runs'])]);
        unset($this->overview, $this->runs, $this->exceptions);
    }

    public function retry(string $runId, InboundRunner $runner): void
    {
        Gate::authorize('integrations.view');
        abort_unless($this->canManage, 403);
        $run = IntegrationRun::query()->where('public_id', $runId)->firstOrFail();

        $this->attempt('action', __('Run diulang.'), function () use ($runner, $run): void {
            $runner->retry($run, $this->selfActor());
        });

        unset($this->overview, $this->runs, $this->exceptions);
    }

    public function startDecision(string $exceptionId): void
    {
        Gate::authorize('integrations.view');
        abort_unless($this->canManage, 403);
        IntegrationException::query()->where('public_id', $exceptionId)->firstOrFail();
        $this->deciding = $exceptionId;
        $this->reset('decision', 'reason');
    }

    public function decide(ExceptionRegister $register): void
    {
        Gate::authorize('integrations.view');
        $exception = IntegrationException::query()->where('public_id', $this->deciding)->firstOrFail();

        $done = $this->attempt('action', __('Keputusan exception dicatat.'), function () use ($register, $exception): void {
            $register->decide($this->selfActor(), $this->currentUser(), $exception->id, $this->decision, $this->reason);
        });

        if ($done) {
            $this->reset('deciding', 'decision', 'reason');
        }

        unset($this->overview, $this->exceptions);
    }
}; ?>

@php
    $local = fn ($moment): string => $moment === null ? '—' : app(\App\Modules\Shared\Application\Time\BusinessTime::class)->toBusiness($moment)->format('Y-m-d H:i');
    $restricted = collect($this->overview)->mapWithKeys(fn (array $row): array => [$row['connection']->key => $row['connection']->restricted])->all();
    $tone = fn (string $status): string => match ($status) { 'PROVEN', 'committed', 'applied' => 'success', 'BLOCKED', 'quarantined', 'dead_letter', 'failed' => 'danger', 'DEFERRED', 'duplicate' => 'neutral', default => 'info' };
@endphp

<div class="flex flex-col gap-6">
    <x-ui.page-header
        :title="__('Integrasi dan migrasi data')"
        :description="__('Inventori koneksi, run inbound, watermark dan kesegaran data, rekonsiliasi, dead-letter, dan exception register. Hanya jumlah, checksum, dan keputusan yang ditampilkan; isi baris dan kredensial tidak pernah dicatat.')"
    />

    @include('pages.administration.partials.feedback', ['keys' => ['action']])

    <x-ui.alert variant="warning" :title="__('Belum ada koneksi runtime yang terbukti')">
        {{ __('Sistem sumber, metode akses, owner, SLA, dan kredensial belum disetujui (DEC-008, DEC-016). Kontrak file, pipeline staging, dan alat migrasi diuji dengan data fixture; status PROVEN hanya diberikan setelah bukti runtime pada sistem nyata.') }}
    </x-ui.alert>

    <x-ui.table-shell :caption="__('Koneksi')" :columns="[__('Koneksi'), __('Status'), __('System of record'), __('Run terakhir'), __('Sukses terakhir'), __('Watermark'), __('Baris / diterima / ditolak / duplikat'), __('Retry / dead-letter'), __('Exception terbuka'), __('Rekonsiliasi'), __('Aksi')]">
        @foreach ($this->overview as $row)
            @php($connection = $row['connection'])
            <tr wire:key="connection-{{ $connection->key }}">
                <th scope="row" class="px-4 py-3 text-start font-medium">{{ $connection->label }}<span class="block font-mono text-xs font-normal">{{ $connection->key }}</span></th>
                <td class="px-4 py-3 text-xs"><x-ui.status-badge :status="$tone($connection->status->value)">{{ $connection->status->value }}</x-ui.status-badge><span class="block">{{ $connection->statusReason }}</span>@if ($row['blocked_because'])<span class="block italic">{{ $row['blocked_because'] }}</span>@endif</td>
                <td class="px-4 py-3 text-xs">{{ $connection->metadata['system_of_record'] }}<span class="block">{{ __('Pemilik data: :o', ['o' => $connection->dataOwner]) }}</span></td>
                <td class="px-4 py-3 text-xs">{{ $row['last_run'] === null ? '—' : $row['last_run']->status.' · '.$local($row['last_run']->created_at) }}</td>
                <td class="px-4 py-3 text-xs">{{ $local($row['last_success']?->finished_at) }}</td>
                <td class="px-4 py-3 text-xs">{{ $local($row['watermark']) }}@if ($row['freshness_hours'] !== null)<span class="block">{{ __(':h jam lalu', ['h' => $row['freshness_hours']]) }}</span>@endif</td>
                <td class="px-4 py-3 text-xs">{{ $row['rows'] }} / {{ $row['accepted'] }} / {{ $row['rejected'] }} / {{ $row['duplicates'] }}</td>
                <td class="px-4 py-3 text-xs">{{ $row['retries'] }} / {{ $row['dead_letters'] }}</td>
                <td class="px-4 py-3 text-xs">{{ $row['open_exceptions'] }}</td>
                <td class="px-4 py-3 text-xs">{{ $row['reconciled'] === null ? '—' : ($row['reconciled'] ? __('cocok') : __('ada selisih')) }}</td>
                <td class="px-4 py-3">
                    @if ($this->canManage && $connection->isPipeline() && $row['blocked_because'] === null)
                        <x-ui.button variant="secondary" wire:click="runNow('{{ $connection->key }}')">{{ __('Jalankan') }}<span class="sr-only"> {{ $connection->key }}</span></x-ui.button>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table-shell>

    <x-ui.table-shell :caption="__('Run terbaru')" :columns="[__('Run'), __('Koneksi'), __('Mode'), __('File'), __('Status'), __('Percobaan'), __('Baris'), __('Rekonsiliasi'), __('Target'), __('Error'), __('Aksi')]">
        @foreach ($this->runs as $run)
            <tr wire:key="run-{{ $run->public_id }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $run->public_id }}<span class="block">{{ $local($run->created_at) }}</span></td>
                <td class="px-4 py-3 text-xs">{{ $run->connection }}</td>
                <td class="px-4 py-3 text-xs">{{ $run->mode }}</td>
                <th scope="row" class="px-4 py-3 text-start text-xs font-medium">{{ $run->file_name }}<span class="block font-mono font-normal">{{ $run->content_hash === null ? '' : substr($run->content_hash, 0, 12).'…' }}</span></th>
                <td class="px-4 py-3"><x-ui.status-badge :status="$tone($run->status)">{{ $run->status }}</x-ui.status-badge></td>
                <td class="px-4 py-3 text-xs">{{ $run->attempts }}</td>
                <td class="px-4 py-3 text-xs">{{ $run->row_count ?? '—' }} / {{ $run->accepted_count ?? '—' }} / {{ $run->rejected_count ?? '—' }} / {{ $run->duplicate_count ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs">
                    @if ($run->reconciliation === null)
                        —
                    @elseif ($restricted[$run->connection] ?? false)
                        {{ __('baris :d', ['d' => $run->reconciliation['rows']['difference'] ?? '—']) }} · {{ $run->reconciled ? __('cocok') : __('ada selisih (nilai terbatas)') }}
                    @else
                        {{ json_encode($run->reconciliation, JSON_UNESCAPED_UNICODE) }}
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ $run->target_type ?? '—' }}<span class="block">{{ $run->target_public_id }}</span></td>
                <td class="px-4 py-3 text-xs">{{ $run->error_code }}<span class="block">{{ $run->error_message }}</span></td>
                <td class="px-4 py-3">
                    @if ($this->canManage && $run->mode === 'inbound' && in_array($run->status, ['failed', 'dead_letter'], true))
                        <x-ui.button variant="secondary" wire:click="retry('{{ $run->public_id }}')">{{ __('Ulangi') }}<span class="sr-only"> {{ $run->file_name }}</span></x-ui.button>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state :title="__('Belum ada run')" :description="__('Belum ada koneksi yang diaktifkan; migrasi dijalankan lewat command integration:migrate-employees.')" />
        </x-slot:empty>
    </x-ui.table-shell>

    <x-ui.panel :title="__('Exception register')" :description="__('Setiap file yang dikarantina, baris yang ditolak, selisih rekonsiliasi, konflik migrasi, dan dead-letter. Keputusan mencatat alasan dan pemilik data yang menyetujui.')">
        <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="allExceptions" class="rounded border-zinc-300"> {{ __('Tampilkan juga yang sudah diputuskan') }}</label>
        <x-ui.table-shell :caption="__('Exception')" :columns="[__('Waktu'), __('Koneksi'), __('Jenis'), __('Detail'), __('Pemilik data'), __('Status'), __('Aksi')]">
            @foreach ($this->exceptions as $exception)
                <tr wire:key="exception-{{ $exception->public_id }}">
                    <td class="px-4 py-3 text-xs whitespace-nowrap">{{ $local($exception->created_at) }}</td>
                    <td class="px-4 py-3 text-xs">{{ $exception->connection }}</td>
                    <th scope="row" class="px-4 py-3 text-start text-xs font-medium">{{ $exception->kind }}</th>
                    <td class="px-4 py-3 text-xs">{{ $exception->detail }}</td>
                    <td class="px-4 py-3 text-xs">{{ $exception->data_owner }}</td>
                    <td class="px-4 py-3 text-xs">{{ $exception->status }}@if ($exception->resolvedBy)<span class="block">{{ $exception->resolvedBy->name }}: {{ $exception->resolution_reason }}</span>@endif</td>
                    <td class="px-4 py-3">
                        @if ($this->canManage && $exception->status === 'open')
                            <x-ui.button variant="secondary" wire:click="startDecision('{{ $exception->public_id }}')">{{ __('Putuskan') }}</x-ui.button>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                @if ($deciding === $exception->public_id)
                    <tr wire:key="decide-{{ $exception->public_id }}">
                        <td colspan="7" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800">
                            <form wire:submit="decide" class="grid gap-4 sm:grid-cols-2">
                                <x-ui.select name="decision" :label="__('Keputusan')" :placeholder="__('Pilih')" :options="['accepted' => __('Diterima apa adanya'), 'resolved' => __('Diselesaikan di sumber')]" required wire:model="decision" />
                                <x-ui.textarea name="reason" :label="__('Alasan dan pemilik data yang menyetujui')" required wire:model="reason" />
                                <div><x-ui.button type="submit">{{ __('Simpan keputusan') }}</x-ui.button></div>
                            </form>
                        </td>
                    </tr>
                @endif
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state :title="__('Tidak ada exception')" />
            </x-slot:empty>
        </x-ui.table-shell>
    </x-ui.panel>
</div>
