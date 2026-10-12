<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;

function shareValidationErrors(array $messages = []): void
{
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($messages)));
}

beforeEach(function (): void {
    shareValidationErrors();
});

it('renders a button that does not submit forms by default', function (): void {
    $html = Blade::render('<x-ui.button>Simpan</x-ui.button>');

    expect($html)->toContain('type="button"')->toContain('Simpan');
});

it('renders a button as a link when an href is given', function (): void {
    $html = Blade::render('<x-ui.button href="/dashboard" variant="secondary">Kembali</x-ui.button>');

    expect($html)->toContain('<a href="/dashboard"')->not->toContain('<button');
});

it('rejects unknown button variants instead of silently restyling them', function (): void {
    Blade::render('<x-ui.button variant="unknown">X</x-ui.button>');
})->throws(ViewException::class, 'Unhandled match case');

it('renders an input with an associated label and hint', function (): void {
    $html = Blade::render('<x-ui.input name="email" label="Email" type="email" hint="Gunakan email kantor." required />');

    expect($html)
        ->toContain('<label for="email"')
        ->toContain('id="email"')
        ->toContain('name="email"')
        ->toContain('required')
        ->toContain('aria-describedby="email-hint"')
        ->toContain('(wajib)')
        ->not->toContain('aria-invalid');
});

it('marks an input invalid and links its validation error', function (): void {
    shareValidationErrors(['email' => ['Email wajib diisi.']]);

    $html = Blade::render('<x-ui.input name="email" label="Email" />');

    expect($html)
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="email-error"')
        ->toContain('id="email-error"')
        ->toContain('Email wajib diisi.');
});

it('renders alerts with an accessible role and a text label', function (string $variant, string $role, string $label): void {
    $html = Blade::render(
        '<x-ui.alert :variant="$variant" title="Judul">Isi</x-ui.alert>',
        ['variant' => $variant],
    );

    expect($html)->toContain("role=\"{$role}\"")->toContain($label)->toContain('Judul')->toContain('Isi');
})->with([
    ['info', 'status', 'Informasi'],
    ['success', 'status', 'Berhasil'],
    ['warning', 'status', 'Peringatan'],
    ['danger', 'alert', 'Kesalahan'],
]);

it('renders status badges with text, not color alone', function (string $status): void {
    $html = Blade::render(
        '<x-ui.status-badge :status="$status">Label status</x-ui.status-badge>',
        ['status' => $status],
    );

    expect($html)->toContain('Label status')->toContain('aria-hidden="true"');
})->with(['neutral', 'info', 'success', 'warning', 'danger']);

it('renders a page header with a single h1', function (): void {
    $html = Blade::render('<x-ui.page-header title="Dashboard" description="Ringkasan" />');

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('Dashboard')->toContain('Ringkasan');
});

it('renders a table shell with caption, column headers, and an empty slot', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-ui.table-shell caption="Daftar" :columns="['Nama', 'Status']">
            <x-slot:empty>Tidak ada data</x-slot:empty>
        </x-ui.table-shell>
        BLADE);

    expect($html)
        ->toContain('<caption')
        ->toContain('Daftar')
        ->toContain('scope="col"')
        ->toContain('colspan="2"')
        ->toContain('Tidak ada data');
});

it('renders empty, loading, and error states', function (): void {
    expect(Blade::render('<x-ui.empty-state title="Belum ada data" />'))->toContain('Belum ada data')
        ->and(Blade::render('<x-ui.loading-state />'))->toContain('role="status"')->toContain('aria-live="polite"')
        ->and(Blade::render('<x-ui.error-state />'))->toContain('role="alert"');
});

it('rejects unknown alert and status variants', function (string $template): void {
    Blade::render($template);
})->with([
    '<x-ui.alert variant="unknown">X</x-ui.alert>',
    '<x-ui.status-badge status="unknown">X</x-ui.status-badge>',
])->throws(ViewException::class, 'Unhandled match case');

it('does not repeat the variant label when an alert has no title', function (): void {
    $html = Blade::render('<x-ui.alert variant="info">Isi</x-ui.alert>');

    expect(substr_count($html, 'Informasi'))->toBe(1);
});

it('escapes user-controlled text in every primitive', function (): void {
    $payload = '<script>alert(1)</script>';

    $html = Blade::render(<<<'BLADE'
        <x-ui.page-header :title="$p" :description="$p" />
        <x-ui.input name="field" :label="$p" :hint="$p" />
        <x-ui.alert variant="info" :title="$p">{{ $p }}</x-ui.alert>
        <x-ui.status-badge>{{ $p }}</x-ui.status-badge>
        <x-ui.table-shell :caption="$p" :columns="[$p]" />
        <x-ui.empty-state :title="$p" :description="$p" />
        <x-ui.loading-state :label="$p" />
        <x-ui.error-state :title="$p" :description="$p" />
        BLADE, ['p' => $payload]);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});
