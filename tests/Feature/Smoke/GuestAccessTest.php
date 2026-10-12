<?php

declare(strict_types=1);

it('shows the landing page to guests with a link to log in', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('login'), escape: false);
});

it('shows the login page to guests', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('name="email"', escape: false)
        ->assertSee('name="password"', escape: false);
});

it('does not offer public self-registration', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Guest',
        'email' => 'guest@example.test',
        'password' => 'irrelevant-password',
        'password_confirmation' => 'irrelevant-password',
    ])->assertNotFound();

    $this->assertGuest();
});

it('redirects guests away from protected pages', function (string $routeName): void {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with([
    'dashboard',
    'kpi.index',
    'approvals.index',
    'reports.index',
    'administration.index',
]);
