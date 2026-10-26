<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use Tests\Support\AccessFixtures;
use Tests\Support\BrowserJourney;
use Tests\Support\WorkflowScenario;

/*
 * Step 19 — what each role can open in a real browser, automated
 * accessibility scan (axe-core, WCAG serious and critical), JavaScript and
 * CSP errors, and the tablet viewport. Data: the workflow scenario in
 * review (synthetic, TEST-ONLY policy).
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new WorkflowScenario;
});

/**
 * @return array<string, User>
 */
function browserAccounts(WorkflowScenario $scenario): array
{
    return [
        'management' => $scenario->management,
        'department_head' => $scenario->manager,
        'team_leader' => $scenario->scorecards->leader,
        'employee' => $scenario->scorecards->staff,
        'hr_performance' => $scenario->hr(),
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
    ];
}

/**
 * Pages each role uses daily; every one must open and pass the scan.
 *
 * @return array<string, list<string>>
 */
function rolePages(WorkflowScenario $scenario): array
{
    $period = $scenario->period;
    $review = route('approvals.review', $scenario->review('W-001'));

    return [
        'management' => [route('dashboard'), route('approvals.index'), $review, route('reports.index')],
        'department_head' => [route('dashboard'), route('approvals.index'), $review, route('team.index')],
        'team_leader' => [route('dashboard'), route('actuals.entry', $period), route('team.index'), route('team-review.index')],
        'employee' => [route('dashboard'), route('actuals.index'), route('actuals.entry', $period), route('notifications.index')],
        'hr_performance' => [route('kpi.index'), route('periods.show', $period), route('scorecards.period', $period), route('approvals.period', $period)],
        'auditor' => [route('reports.index'), route('kpi.definitions'), $review],
        'system_admin' => [route('administration.index'), route('administration.accounts'), route('integrations.index')],
    ];
}

test('each role opens its daily pages without accessibility, JavaScript, or CSP errors', function (string $role): void {
    $user = browserAccounts($this->scenario)[$role];
    $this->actingAs($user);

    foreach (rolePages($this->scenario)[$role] as $url) {
        BrowserJourney::open($url)
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    }
})->with(['management', 'department_head', 'team_leader', 'employee', 'hr_performance', 'auditor', 'system_admin']);

test('the main pages stay usable on a tablet viewport', function (): void {
    $this->actingAs($this->scenario->scorecards->leader);

    visit(route('actuals.entry', $this->scenario->period))
        ->on()->iPadMini()
        ->assertPresent('#main-content')
        ->assertSee('W-001')
        ->assertNoAccessibilityIssues();

    $this->actingAs($this->scenario->manager);
    visit(route('approvals.review', $this->scenario->review('W-001')))
        ->on()->iPadMini()
        ->assertPresent('#main-content')
        ->assertSee('Setujui review')
        ->assertNoAccessibilityIssues();
});

test('direct URLs outside the role answer forbidden or not found in the browser', function (string $role, string $page, string $expected): void {
    $user = browserAccounts($this->scenario)[$role];
    $urls = [
        'administration' => route('administration.accounts'),
        'review' => route('approvals.review', $this->scenario->review('W-001')),
        'other_scorecard' => route('scorecards.show', [$this->scenario->period, $this->scenario->scorecards->assignment('AR-001')]),
        'bypasses' => route('approvals.bypasses'),
    ];

    $this->actingAs($user);
    // The status page, and none of the protected page's content or controls.
    visit($urls[$page])
        ->assertSee($expected)
        ->assertNotPresent('#main-content')
        ->assertDontSee('Setujui review');
})->with([
    'employee → administration' => ['employee', 'administration', '403'],
    'employee → own approval notes' => ['employee', 'review', '403'],
    'employee → another scorecard' => ['employee', 'other_scorecard', '404'],
    'team leader → approval review' => ['team_leader', 'review', '403'],
    'system admin → approval review' => ['system_admin', 'review', '403'],
    'auditor → bypass register' => ['auditor', 'bypasses', '403'],
]);

test('login works with the keyboard alone and lands on the dashboard', function (): void {
    $user = AccessFixtures::userWithRole(Role::Employee);

    BrowserJourney::open(route('login'), 'input[name=email]')
        ->assertNoAccessibilityIssues()
        ->assertScript('document.activeElement.name', 'email')
        ->type('email', $user->email)
        ->keys('input[name=email]', 'Tab')
        ->assertScript('document.activeElement.name', 'password')
        ->type('password', 'password')
        ->keys('input[name=password]', 'Enter')
        ->assertPresent('#main-content')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

test('a wrong password is refused with an understandable message', function (): void {
    $user = AccessFixtures::userWithRole(Role::Employee);

    BrowserJourney::open(route('login'), 'input[name=email]')
        ->fill('email', $user->email)
        ->fill('password', 'salah-sekali')
        ->press('Log in')
        ->assertSee('These credentials do not match our records.')
        ->assertPathIs('/login');
});
