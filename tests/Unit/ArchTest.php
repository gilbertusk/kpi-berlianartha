<?php

declare(strict_types=1);

arch('no debugging statements are left in application code')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('security preset')
    ->preset()
    ->security();

// DOMAIN_BOUNDARIES.md §2: domain rules must not depend on HTTP, Livewire,
// queue transport, ORM, or storage vendors. Enforced as soon as a module lands.
if (is_dir(__DIR__.'/../../app/Modules')) {
    arch('module domain layers stay framework-agnostic')
        ->expect('App\Modules\*\Domain')
        ->not->toUse([
            'Illuminate\Http',
            'Illuminate\Support\Facades',
            'Illuminate\Database\Eloquent',
            'Illuminate\Queue',
            'Livewire',
            'Aws',
        ]);
} else {
    test('module domain layers stay framework-agnostic')
        ->skip('No module has been introduced yet (app/Modules does not exist).');
}
