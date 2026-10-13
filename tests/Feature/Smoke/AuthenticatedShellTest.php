<?php

declare(strict_types=1);

use App\Models\User;

it('shows the dashboard placeholder to an authenticated user', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Data KPI belum dikonfigurasi')
        ->assertSee('Lewati ke konten utama')
        ->assertSee('<main id="main-content"', escape: false);
});

it('renders the main navigation and hides Administration from accounts without an admin capability', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('dashboard'))->assertOk();

    foreach (config('navigation.main') as $item) {
        if ($item['ability'] === null) {
            $response->assertSee($item['label'])
                ->assertSee(route($item['route']), escape: false);
        } else {
            $response->assertDontSee(route($item['route']), escape: false);
        }
    }

    expect(array_column(config('navigation.main'), 'label'))
        ->toBe(['Dashboard', 'KPI', 'Periode & Target', 'Aktual', 'Approval', 'Reports', 'Administration'])
        ->and(collect(config('navigation.main'))->firstWhere('label', 'Periode & Target')['ability'])
        ->toBe('periods.view')
        ->and(collect(config('navigation.main'))->firstWhere('label', 'Administration')['ability'])
        ->toBe('administration.view');
});

it('shows a no-data placeholder for modules that are not implemented yet', function (string $routeName, string $title): void {
    $this->actingAs(User::factory()->create());

    $this->get(route($routeName))
        ->assertOk()
        ->assertSeeInOrder(['<main id="main-content"', '<h1', $title, '</h1>'], escape: false)
        ->assertSee('Modul belum tersedia');
})->with([
    ['approvals.index', 'Approval'],
    ['reports.index', 'Reports'],
]);

it('requires a verified email before opening the dashboard', function (): void {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});
