<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\TeamReview\Application\ActionDraft;
use App\Modules\TeamReview\Application\CoachingNoteService;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Application\TeamReviewQuery;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CoachingNote;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveActionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\TeamReviewScenario;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new TeamReviewScenario;
    $this->service = app(TeamReviewService::class);
});

test('an issue records problem, cause, context, and the KPIs it affects', function (): void {
    $kpi = (new KpiFixtures)->publishedDefinition('TR-QUALITY');

    $issue = $this->scenario->issue(null, [
        'contextType' => 'machine',
        'contextReference' => 'Mesin 3',
        'kpiDefinitionIds' => [$kpi->definition->public_id],
    ]);

    expect($issue->cause_code)->toBe('MESIN-SETTING')
        ->and($issue->context_type?->value)->toBe('machine')
        ->and($issue->kpis->pluck('code')->all())->toBe(['TR-QUALITY'])
        ->and(AuditLog::query()->where('action', 'team_review.issue_recorded')->count())->toBe(1);

    expect(AccessFixtures::violatedRule(fn () => $this->scenario->issue(null, ['problem' => ' '])))->toBe('problem_required')
        ->and(AccessFixtures::violatedRule(fn () => $this->scenario->issue(null, ['reviewDate' => '2026-10-13'])))->toBe('review_date_in_future')
        ->and(AccessFixtures::violatedRule(fn () => $this->scenario->issue(null, ['contextType' => 'machine'])))->toBe('context_incomplete')
        ->and(AccessFixtures::violatedRule(fn () => $this->scenario->issue(null, ['kpiDefinitionIds' => ['01TIDAKADA0000000000000000']])))->toBe('kpi_not_found');
});

test('a corrective action needs an owner from the team and a due date that is not in the past', function (): void {
    $issue = $this->scenario->issue();
    $leader = $this->scenario->leader;
    $assign = fn (int $owner, string $dueOn, string $priority = 'medium') => $this->service->assignAction(
        TeamReviewScenario::actor($leader), $leader, $issue->id, new ActionDraft('Tindakan', $owner, $dueOn, $priority),
    );

    expect(AccessFixtures::violatedRule(fn () => $assign($this->scenario->otherLeader->id, '2026-10-15')))->toBe('invalid_owner')
        ->and(AccessFixtures::violatedRule(fn () => $assign($this->scenario->member->id, '2026-10-11')))->toBe('due_date_in_past')
        ->and(AccessFixtures::violatedRule(fn () => $assign($this->scenario->member->id, 'besok')))->toBe('invalid_period')
        ->and(AccessFixtures::violatedRule(fn () => $assign($this->scenario->member->id, '2026-10-15', 'urgent')))->toBe('invalid_priority');

    $action = $assign($this->scenario->member->id, '2026-10-15');

    expect($action->status)->toBe(ActionStatus::Open)
        ->and($action->owner_user_id)->toBe($this->scenario->member->id)
        ->and($action->due_on)->toBe('2026-10-15')
        ->and(OutboxMessage::query()->where('event_type', 'CorrectiveActionAssigned')->count())->toBe(1);
});

test('lifecycle: the owner works and reports, someone else verifies and closes, and every step is in the history', function (): void {
    $action = $this->scenario->action();
    $member = $this->scenario->member;
    $leader = $this->scenario->leader;
    $as = fn (User $user) => TeamReviewScenario::actor($user);

    $action = $this->service->start($as($member), $member, $action->id, $action->lock_version);
    $action = $this->service->wait($as($member), $member, $action->id, $action->lock_version, 'Menunggu suku cadang');
    expect($action->status)->toBe(ActionStatus::Waiting)->and($action->waiting_reason)->toBe('Menunggu suku cadang');

    $action = $this->service->resume($as($member), $member, $action->id, $action->lock_version);

    // Not verifiable before the owner reports completion.
    expect(AccessFixtures::violatedRule(fn () => $this->service->verify($as($leader), $leader, $action->id, $action->lock_version)))->toBe('completion_not_reported');

    $action = $this->service->reportCompletion($as($member), $member, $action->id, $action->lock_version, 'Sudah disetel', 'FOTO-01');
    expect($action->awaitsVerification())->toBeTrue()
        ->and(AccessFixtures::violatedRule(fn () => $this->service->reportCompletion($as($member), $member, $action->id, $action->lock_version, 'Lagi', null)))->toBe('completion_reported');

    $action = $this->service->sendBack($as($leader), $leader, $action->id, $action->lock_version, 'Hasil belum stabil');
    expect($action->awaitsVerification())->toBeFalse()->and($action->status)->toBe(ActionStatus::InProgress);

    $action = $this->service->reportCompletion($as($member), $member, $action->id, $action->lock_version, 'Stabil 3 shift', 'FOTO-02');
    $action = $this->service->verify($as($leader), $leader, $action->id, $action->lock_version, 'Dicek di lantai produksi');
    expect($action->status)->toBe(ActionStatus::Verified)->and($action->reviewer_user_id)->toBe($leader->id);

    $action = $this->service->close($as($leader), $leader, $action->id, $action->lock_version);
    expect($action->status)->toBe(ActionStatus::Closed)->and($action->closed_by_user_id)->toBe($leader->id);

    expect(CorrectiveActionEvent::query()->where('corrective_action_id', $action->id)->orderBy('id')->pluck('event')->all())->toBe([
        'created', 'status_changed', 'status_changed', 'status_changed', 'completion_reported', 'completion_returned', 'completion_reported', 'status_changed', 'status_changed',
    ])
        ->and(AuditLog::query()->where('entity_type', 'corrective_action')->where('entity_id', $action->public_id)->count())->toBe(9)
        // A closed action is final.
        ->and(fn () => $this->service->reschedule($as($leader), $leader, $action->id, $action->lock_version, '2026-10-20', 'Coba'))->toThrow(SafeDomainException::class);
});

test('only the owner moves an action forward and nobody verifies their own', function (): void {
    $action = $this->scenario->action();
    $leader = $this->scenario->leader;
    $head = $this->scenario->head;

    expect(fn () => $this->service->start(TeamReviewScenario::actor($leader), $leader, $action->id, $action->lock_version))->toThrow(AuthorizationException::class);

    // The team leader owns an action: the department head verifies it.
    $own = $this->scenario->completed($this->scenario->action(null, $leader));

    expect(AccessFixtures::violatedRule(fn () => $this->service->verify(TeamReviewScenario::actor($leader), $leader, $own->id, $own->lock_version)))->toBe('self_verification')
        ->and(AccessFixtures::violatedRule(fn () => $this->service->reschedule(TeamReviewScenario::actor($leader), $leader, $own->id, $own->lock_version, '2026-10-30', 'Butuh waktu')))->toBe('self_reschedule')
        ->and($this->service->verify(TeamReviewScenario::actor($head), $head, $own->id, $own->lock_version)->status)->toBe(ActionStatus::Verified);
});

test('a stale page cannot move an action twice', function (): void {
    $action = $this->scenario->action();
    $member = $this->scenario->member;

    $this->service->start(TeamReviewScenario::actor($member), $member, $action->id, $action->lock_version);

    expect(fn () => $this->service->start(TeamReviewScenario::actor($member), $member, $action->id, $action->lock_version))->toThrow(SafeDomainException::class)
        ->and(CorrectiveActionEvent::query()->where('corrective_action_id', $action->id)->count())->toBe(2);
});

test('completion evidence is required before verification when configured', function (): void {
    Config::set('team_review.completion_evidence_required', true);
    $service = app(TeamReviewService::class);
    $action = $this->scenario->action();
    $member = $this->scenario->member;
    $action = $service->start(TeamReviewScenario::actor($member), $member, $action->id, $action->lock_version);

    expect(AccessFixtures::violatedRule(fn () => $service->reportCompletion(TeamReviewScenario::actor($member), $member, $action->id, $action->lock_version, 'Selesai', null)))->toBe('evidence_required')
        ->and($service->reportCompletion(TeamReviewScenario::actor($member), $member, $action->id, $action->lock_version, 'Selesai', 'BA-01')->evidence_reference)->toBe('BA-01');
});

test('accounts outside the team scope cannot record, manage, or see its review', function (): void {
    $action = $this->scenario->action();
    $other = $this->scenario->otherLeader;
    $query = app(TeamReviewQuery::class);
    $access = app(TeamReviewAccess::class);

    expect(fn () => $this->scenario->issue($other))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->reschedule(TeamReviewScenario::actor($other), $other, $action->id, $action->lock_version, '2026-10-20', 'Coba'))->toThrow(AuthorizationException::class)
        ->and($access->canViewAction($other, $action))->toBeFalse()
        ->and($query->actions($other, $access->viewScope($other), null, 1)->total())->toBe(0)
        ->and($query->issues($access->viewScope($other), null, 1)->total())->toBe(0);

    // Read-only roles see it but cannot manage it; the owner sees their own action.
    foreach ([$this->scenario->hr, $this->scenario->management, $this->scenario->auditor] as $reader) {
        expect($access->canViewAction($reader, $action))->toBeTrue()
            ->and($access->canManage($reader, $action->organization_unit_id))->toBeFalse()
            ->and(fn () => $this->service->close(TeamReviewScenario::actor($reader), $reader, $action->id, $action->lock_version))->toThrow(AuthorizationException::class);
    }

    expect($access->canViewAction($this->scenario->member, $action))->toBeTrue()
        ->and($query->actions($this->scenario->member, $access->viewScope($this->scenario->member), 'mine', 1)->total())->toBe(1)
        ->and($query->issues($access->viewScope($this->scenario->member), null, 1)->total())->toBe(0);
});

test('overdue is counted from the due date for outstanding actions only', function (): void {
    $issue = $this->scenario->issue();
    $this->scenario->action($issue, null, '2026-10-13');
    $this->scenario->action($issue, null, '2026-10-20');
    $done = $this->scenario->completed($this->scenario->action($issue, null, '2026-10-13'));
    $leader = $this->scenario->leader;
    $this->service->verify(TeamReviewScenario::actor($leader), $leader, $done->id, $done->lock_version);
    $query = app(TeamReviewQuery::class);
    $scope = app(TeamReviewAccess::class)->viewScope($leader);

    expect($query->actionCounts($leader, $scope))->toBe(['outstanding' => 2, 'overdue' => 0, 'to_verify' => 0]);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 01:00:00', 'UTC'));

    expect($query->actionCounts($leader, $scope))->toBe(['outstanding' => 2, 'overdue' => 1, 'to_verify' => 0])
        ->and($query->actions($leader, $scope, 'overdue', 1)->total())->toBe(1);

    // Rescheduling needs a reason and is written to the history.
    $late = $query->actions($leader, $scope, 'overdue', 1)->items()[0];
    $this->service->reschedule(TeamReviewScenario::actor($leader), $leader, $late->id, $late->lock_version, '2026-10-21', 'Suku cadang baru tiba tanggal 20');

    expect($query->actionCounts($leader, $scope)['overdue'])->toBe(0)
        ->and(AuditLog::query()->where('action', 'corrective_action.rescheduled')->firstOrFail()->after_values)->toBe(['due_on' => '2026-10-21']);
});

test('the database refuses invalid action transitions and changes to history', function (): void {
    $action = $this->scenario->action();
    $event = CorrectiveActionEvent::query()->where('corrective_action_id', $action->id)->firstOrFail();

    $attempts = [
        fn () => DB::table('corrective_actions')->where('id', $action->id)->update(['status' => 'closed']),
        fn () => DB::table('corrective_actions')->where('id', $action->id)->delete(),
        fn () => DB::table('corrective_action_events')->where('id', $event->id)->update(['note' => 'diubah']),
        fn () => DB::table('corrective_action_events')->where('id', $event->id)->delete(),
    ];

    foreach ($attempts as $attempt) {
        expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class);
    }
});

test('coaching notes are readable only by the author and the team leaders of that team', function (): void {
    $notes = app(CoachingNoteService::class);
    $leader = $this->scenario->leader;
    $employee = $this->scenario->memberEmployee;
    $access = app(TeamReviewAccess::class);
    $query = app(TeamReviewQuery::class);

    $note = $notes->add(TeamReviewScenario::actor($leader), $leader, $employee->id, 'Perlu pendampingan setting mesin');

    $readable = fn (User $user): int => $query->coachingNotes($user, $employee, $access->coachingScope($user))->count();

    expect($readable($leader))->toBe(1)
        ->and($access->canReadCoachingNote($leader, $note->load('employee')))->toBeTrue();

    // The subject, managers above, HR, Management, auditors, and other teams' leaders cannot read it.
    foreach ([$this->scenario->member, $this->scenario->head, $this->scenario->hr, $this->scenario->management, $this->scenario->auditor, $this->scenario->otherLeader] as $user) {
        expect($readable($user))->toBe(0, $user->name)
            ->and($access->canReadCoachingNote($user, $note))->toBeFalse($user->name);
    }

    // A second leader of the same team reads it; the role is never delegable.
    $second = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    expect($readable($second))->toBe(1)
        ->and(Capability::CoachingNoteManage->isDelegable())->toBeFalse()
        ->and((new RoleCapabilityMatrix)->allows(Role::HrPerformance, Capability::CoachingNoteManage))->toBeFalse();

    // Writing: only for the own team, never about oneself, and the audit entry holds no note text.
    expect(fn () => $notes->add(TeamReviewScenario::actor($this->scenario->otherLeader), $this->scenario->otherLeader, $employee->id, 'Catatan'))->toThrow(AuthorizationException::class)
        ->and(fn () => $notes->add(TeamReviewScenario::actor($this->scenario->head), $this->scenario->head, $employee->id, 'Catatan'))->toThrow(AuthorizationException::class)
        ->and(AccessFixtures::violatedRule(fn () => $notes->add(TeamReviewScenario::actor($leader), $leader, $employee->id, ' ')))->toBe('note_required')
        ->and(json_encode(AuditLog::query()->where('action', 'coaching_note.added')->firstOrFail()->after_values))->not->toContain('pendampingan');

    expect(fn () => DB::transaction(fn () => DB::table('coaching_notes')->where('id', $note->id)->update(['note' => 'x'])))->toThrow(QueryException::class)
        ->and(CoachingNote::query()->count())->toBe(1);
});
