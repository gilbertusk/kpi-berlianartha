<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Notification\Application\NotificationMessage;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Infrastructure\Persistence\Models\UserNotification;
use App\Modules\TeamReview\Application\CoachingNoteService;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CoachingNote;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\TeamReviewIssue;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\TeamReviewScenario;

/*
 * Direct URL and forged Livewire action tests for the team page, the daily
 * team review, and the notification page.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new TeamReviewScenario;
    $this->users = [
        'team_leader' => $this->scenario->leader,
        'department_head' => $this->scenario->head,
        'other_leader' => $this->scenario->otherLeader,
        'hr_performance' => $this->scenario->hr,
        'management' => $this->scenario->management,
        'auditor' => $this->scenario->auditor,
        'member' => $this->scenario->member,
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
    ];
});

test('team pages open for exactly the accounts with a part in them', function (string $roleKey, int $team, int $review, int $action): void {
    $record = $this->scenario->action();
    $user = $this->users[$roleKey];

    $this->actingAs($user)->get(route('team.index'))->assertStatus($team);
    $this->actingAs($user)->get(route('team-review.index'))->assertStatus($review);
    $this->actingAs($user)->get(route('team-review.action', $record))->assertStatus($action);
})->with([
    'team leader' => ['team_leader', 200, 200, 200],
    'department head' => ['department_head', 200, 200, 200],
    'leader of another team' => ['other_leader', 200, 200, 403],
    'hr (read-only)' => ['hr_performance', 403, 200, 200],
    'management (read-only)' => ['management', 403, 200, 200],
    'auditor (read-only)' => ['auditor', 403, 200, 200],
    // The owner reaches the review through their own action.
    'member who owns the action' => ['member', 403, 200, 200],
    'system admin' => ['system_admin', 403, 403, 403],
]);

test('an employee without any action has no team review', function (): void {
    $this->actingAs($this->users['member'])->get(route('team-review.index'))->assertForbidden();
});

test('the team leader records an issue and assigns an action; owner and leader take it to closed on the page', function (): void {
    Livewire::actingAs($this->users['team_leader'])->test('pages::team-review.index')
        ->set('unit', 'PROD-WOVEN')
        ->call('recordIssue')
        ->assertHasErrors('action')
        ->set('problem', 'Benang putus berulang')
        ->set('kpiCodes', 'TIDAK-ADA')
        ->call('recordIssue')
        ->assertHasErrors('action')
        ->set('kpiCodes', '')
        ->set('causeCode', 'setting')
        ->call('recordIssue')
        ->assertHasNoErrors()
        ->assertSee('Benang putus berulang');

    $issue = TeamReviewIssue::query()->firstOrFail();

    Livewire::actingAs($this->users['team_leader'])->test('pages::team-review.index')
        ->call('openActionForm', $issue->public_id)
        ->set('actionText', 'Setel ulang tension')
        ->set('owner', $this->scenario->member->public_id)
        ->call('assignAction')
        ->assertHasErrors('action')
        ->set('dueOn', '2026-10-15')
        ->call('assignAction')
        ->assertHasNoErrors()
        ->assertSee('Setel ulang tension');

    $action = CorrectiveAction::query()->firstOrFail();
    expect($action->owner_user_id)->toBe($this->scenario->member->id)->and($action->status)->toBe(ActionStatus::Open);

    Livewire::actingAs($this->users['member'])->test('pages::team-review.action', ['action' => $action])
        ->assertSee('Tindak lanjut PIC')
        ->assertDontSee('Pengelola review')
        ->call('start')
        ->assertHasNoErrors()
        ->call('reportCompletion')
        ->assertHasErrors('action')
        ->set('note', 'Sudah disetel')
        ->set('evidence', 'FOTO-1')
        ->call('reportCompletion')
        ->assertHasNoErrors()
        ->assertSee('menunggu verifikasi');

    Livewire::actingAs($this->users['team_leader'])->test('pages::team-review.action', ['action' => $action])
        ->assertSee('Pengelola review')
        ->call('verify')
        ->assertHasNoErrors()
        ->call('close')
        ->assertHasNoErrors()
        ->assertSee('Status: Closed')
        ->assertDontSee('Pengelola review');

    expect($action->refresh()->status)->toBe(ActionStatus::Closed);
});

test('forged actions are refused: wrong owner, wrong team, read-only roles, foreign issue or owner', function (): void {
    $action = $this->scenario->completed();
    $issue = $action->issue;

    // The owner cannot verify their own action; a read-only role cannot manage.
    Livewire::actingAs($this->users['member'])->test('pages::team-review.action', ['action' => $action])->call('verify')->assertForbidden();
    Livewire::actingAs($this->users['hr_performance'])->test('pages::team-review.action', ['action' => $action])->call('verify')->assertForbidden();
    Livewire::actingAs($this->users['team_leader'])->test('pages::team-review.action', ['action' => $action])->call('start')->assertForbidden();
    Livewire::actingAs($this->users['other_leader'])->test('pages::team-review.action', ['action' => $action])->assertForbidden();

    // Another team's leader cannot add an action to this issue or record an issue for this team.
    Livewire::actingAs($this->users['other_leader'])->test('pages::team-review.index')
        ->assertDontSee('Benang putus')
        ->call('openActionForm', $issue->public_id)
        ->assertForbidden();

    Livewire::actingAs($this->users['other_leader'])->test('pages::team-review.index')
        ->set('unit', 'PROD-WOVEN')
        ->set('problem', 'Paksa')
        ->call('recordIssue')
        ->assertStatus(422);

    // An owner from outside the team cannot be injected.
    Livewire::actingAs($this->users['team_leader'])->test('pages::team-review.index')
        ->call('openActionForm', $issue->public_id)
        ->set('actionText', 'Tindakan')
        ->set('dueOn', '2026-10-15')
        ->set('owner', $this->scenario->otherLeader->public_id)
        ->call('assignAction')
        ->assertStatus(422);

    Livewire::actingAs($this->users['hr_performance'])->test('pages::team-review.index')
        ->assertDontSee('Catat masalah')
        ->call('recordIssue')
        ->assertForbidden();

    expect($action->refresh()->status)->toBe(ActionStatus::InProgress)
        ->and(CorrectiveAction::query()->count())->toBe(1);
});

test('the team page shows members, their history, and coaching notes only to the team leader', function (): void {
    app(CoachingNoteService::class)->add(TeamReviewScenario::actor($this->scenario->leader), $this->scenario->leader, $this->scenario->memberEmployee->id, 'Perlu pendampingan');

    Livewire::actingAs($this->users['team_leader'])->test('pages::team.index')
        ->assertSee('W-011')
        ->assertSee('W-012')
        ->assertSee('Histori perubahan anggota PROD-WOVEN')
        ->assertSee('tidak ada modul cuti')
        ->assertDontSee('Perlu pendampingan')
        ->call('openCoaching', 'W-011')
        ->assertSee('Perlu pendampingan')
        ->set('coachingNote', 'Sudah membaik')
        ->call('addCoachingNote')
        ->assertHasNoErrors()
        ->assertSee('Sudah membaik')
        ->call('openCoaching', 'AR-999')
        ->assertNotFound();

    // The department head manages the team review but does not read coaching notes.
    Livewire::actingAs($this->users['department_head'])->test('pages::team.index')
        ->set('unit', 'PROD-WOVEN')
        ->assertSee('W-011')
        ->assertDontSee('Perlu pendampingan')
        ->call('openCoaching', 'W-011')
        ->assertForbidden();

    // Another team's leader cannot switch to this team.
    Livewire::actingAs($this->users['other_leader'])->test('pages::team.index')
        ->set('unit', 'PROD-WOVEN')
        ->assertDontSee('W-011')
        ->assertSee('ACC-AR');

    expect(CoachingNote::query()->count())->toBe(2);
});

test('the notification page lists only the own notifications and keeps email preferences', function (): void {
    $service = app(NotificationService::class);
    $service->notify([$this->scenario->member->id], new NotificationMessage(NotificationCategory::Reminder, 'Untuk anggota', 'Isi', 'n-1'));
    $service->notify([$this->scenario->leader->id], new NotificationMessage(NotificationCategory::Reminder, 'Untuk leader', 'Isi', 'n-2'));

    Livewire::actingAs($this->users['member'])->test('pages::notifications.index')
        ->assertSee('Untuk anggota')
        ->assertDontSee('Untuk leader')
        ->assertSee('Channel email belum diaktifkan')
        ->call('toggleEmail', 'reminder')
        ->assertHasNoErrors()
        ->call('toggleEmail', 'bukan-kategori')
        ->assertStatus(422);

    expect($service->emailPreferences($this->scenario->member)['reminder'])->toBeFalse()
        ->and($service->emailPreferences($this->scenario->leader)['reminder'])->toBeTrue();

    Livewire::actingAs($this->users['member'])->test('pages::notifications.index')->call('markAllRead')->assertSee('1 notifikasi ditandai');

    expect(UserNotification::query()->where('user_id', $this->scenario->member->id)->whereNull('read_at')->count())->toBe(0)
        ->and(UserNotification::query()->where('user_id', $this->scenario->leader->id)->whereNull('read_at')->count())->toBe(1);
});
