<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);
});

it('renders a production-safe 404 page', function (): void {
    $this->get('/halaman-yang-tidak-ada')
        ->assertNotFound()
        ->assertSee('Halaman tidak ditemukan');
});

it('renders the unauthorized page without exposing the exception message', function (): void {
    Route::middleware('web')->get('/_test/forbidden', fn () => abort(403, 'sentinel-policy-detail'));

    $this->get('/_test/forbidden')
        ->assertForbidden()
        ->assertSee('Akses ditolak')
        ->assertDontSee('sentinel-policy-detail');
});

it('renders a production-safe 500 page without internal details', function (): void {
    Route::middleware('web')->get('/_test/failure', function (): never {
        throw new RuntimeException('sentinel-internal-detail');
    });

    $this->get('/_test/failure')
        ->assertInternalServerError()
        ->assertSee('Terjadi kesalahan pada sistem')
        ->assertDontSee('sentinel-internal-detail')
        ->assertDontSee('RuntimeException')
        ->assertDontSee(base_path(), escape: false);
});
