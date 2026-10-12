<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use Illuminate\Support\Facades\Route;

test('safe domain errors render stable JSON without an internal exception message', function (): void {
    Route::get('/_test/concurrency-error', function (): never {
        throw new ConcurrencyConflict('stored_object_metadata', 'secret-internal-id', 2);
    });

    $response = $this->getJson('/_test/concurrency-error');

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'concurrency_conflict')
        ->assertJsonPath('error.message', 'Data telah berubah. Muat ulang halaman sebelum mencoba kembali.')
        ->assertJsonPath('error.context.expected_version', 2)
        ->assertJsonMissing(['exception'])
        ->assertDontSee('secret-internal-id')
        ->assertDontSee('Stack trace');
});
