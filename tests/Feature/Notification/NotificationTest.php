<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\Notification\Application\NotificationMessage;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Application\ReminderService;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Domain\ReminderRule;
use App\Modules\Notification\Infrastructure\Jobs\SendNotificationEmail;
use App\Modules\Notification\Infrastructure\Mail\NotificationMail;
use App\Modules\Notification\Infrastructure\Persistence\Models\NotificationDelivery;
use App\Modules\Notification\Infrastructure\Persistence\Models\UserNotification;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Outbox\OutboxProcessor;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\Workflow\Application\CorrectionService;
use App\Modules\Workflow\Application\WorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AccessFixtures;
use Tests\Support\ScorecardScenario;
use Tests\Support\TeamReviewScenario;
use Tests\Support\WorkflowScenario;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
});

function message(string $key = 'k-1', NotificationCategory $category = NotificationCategory::Reminder): NotificationMessage
{
    return new NotificationMessage($category, 'Judul', 'Isi pemberitahuan', $key, 'approvals.index');
}

/**
 * Processes every pending outbox message, then every one again.
 */
function drainOutbox(): void
{
    $processor = app(OutboxProcessor::class);

    foreach ([1, 2] as $pass) {
        foreach (OutboxMessage::query()->orderBy('id')->pluck('public_id') as $messageId) {
            $processor->process((string) $messageId);
        }
    }
}

function notificationsFor(int $userId, ?NotificationCategory $category = null): int
{
    return UserNotification::query()->where('user_id', $userId)->when($category !== null, fn ($query) => $query->where('category', $category?->value))->count();
}

test('a notification is created once per recipient and key, with its in-app delivery', function (): void {
    $first = AccessFixtures::user();
    $second = AccessFixtures::user();
    $service = app(NotificationService::class);

    expect($service->notify([$first->id, $second->id, $first->id], message()))->toBe(2)
        ->and($service->notify([$first->id, $second->id], message()))->toBe(0)
        ->and($service->notify([$first->id], message('k-2')))->toBe(1)
        ->and(UserNotification::query()->count())->toBe(3)
        ->and(NotificationDelivery::query()->where('channel', Channel::InApp->value)->where('status', DeliveryStatus::Sent->value)->count())->toBe(3)
        // Email is off until a mail service is approved (DEC-014).
        ->and(NotificationDelivery::query()->where('channel', Channel::Email->value)->count())->toBe(0)
        ->and($service->unreadCount($first))->toBe(2);

    // A deactivated account is told nothing.
    $inactive = AccessFixtures::user();
    $inactive->forceFill(['status' => AccountStatus::Deactivated])->save();

    expect($service->notify([$inactive->id], message('k-3')))->toBe(0);
});

test('the email copy is queued when the channel is on and respects the preference of the recipient', function (): void {
    Config::set('notifications.email.enabled', true);
    Mail::fake();
    $user = AccessFixtures::user();
    $muted = AccessFixtures::user();
    $service = app(NotificationService::class);
    $service->setEmailPreference($muted, NotificationCategory::Reminder, false);

    $service->notify([$user->id, $muted->id], message());

    Mail::assertSent(NotificationMail::class, 1);
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo($user->email));

    $delivery = fn ($account) => NotificationDelivery::query()
        ->where('channel', Channel::Email->value)
        ->whereHas('notification', fn ($query) => $query->where('user_id', $account->id))
        ->firstOrFail();

    expect($delivery($user)->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery($user)->attempts)->toBe(1)
        ->and($delivery($muted)->status)->toBe(DeliveryStatus::Skipped)
        ->and($service->emailPreferences($muted)[NotificationCategory::Reminder->value])->toBeFalse()
        ->and($service->emailPreferences($muted)[NotificationCategory::Correction->value])->toBeTrue();
});

test('a failed email is retried, counted, never sent twice, and marked failed when retries run out', function (): void {
    Config::set('notifications.email.enabled', true);
    Queue::fake();
    $user = AccessFixtures::user();
    app(NotificationService::class)->notify([$user->id], message());
    Queue::assertPushed(SendNotificationEmail::class, 1);

    $delivery = NotificationDelivery::query()->where('channel', Channel::Email->value)->firstOrFail();
    expect($delivery->status)->toBe(DeliveryStatus::Pending)->and($delivery->attempts)->toBe(0);

    // First attempt: the mail transport is down.
    Event::listen(MessageSending::class, fn () => throw new RuntimeException('smtp down: secret-host'));
    expect(fn () => (new SendNotificationEmail($delivery->id))->handle())->toThrow(RuntimeException::class);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        // Only the exception class is stored, never its message.
        ->and($delivery->last_error_code)->toBe('RuntimeException');

    // The retry succeeds; a further run sends nothing.
    Event::forget(MessageSending::class);
    Mail::fake();
    (new SendNotificationEmail($delivery->id))->handle();
    (new SendNotificationEmail($delivery->id))->handle();

    Mail::assertSent(NotificationMail::class, 1);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->last_error_code)->toBeNull();

    // A delivery that exhausts its retries ends as failed; a sent one is never downgraded.
    app(NotificationService::class)->notify([$user->id], message('k-2'));
    $second = NotificationDelivery::query()->where('channel', Channel::Email->value)->orderByDesc('id')->firstOrFail();
    (new SendNotificationEmail($second->id))->failed(new RuntimeException('x'));
    (new SendNotificationEmail($delivery->id))->failed(new RuntimeException('x'));

    expect($second->refresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Sent);
});

test('workflow events notify the right accounts once, however often the outbox is processed', function (): void {
    $scenario = new WorkflowScenario;
    drainOutbox();

    // Entering review: the reviewer gets one task notification for the period, not one per scorecard.
    expect(notificationsFor($scenario->manager->id, NotificationCategory::ApprovalTask))->toBe(1)
        ->and(notificationsFor($scenario->management->id))->toBe(0)
        ->and(notificationsFor($scenario->scorecards->staff->id))->toBe(0);

    // Returned scorecard → the HR accounts that can resubmit it.
    $review = $scenario->review('W-001');
    app(WorkflowService::class)->returnForRevision(WorkflowScenario::actor($scenario->manager), $scenario->manager, $review->id, $review->lock_version, 'Cek ulang');
    drainOutbox();

    expect(notificationsFor($scenario->hr()->id, NotificationCategory::ScorecardReturned))->toBe(1)
        ->and(notificationsFor($scenario->secondHr()->id, NotificationCategory::ScorecardReturned))->toBe(1)
        ->and(notificationsFor($scenario->manager->id, NotificationCategory::ScorecardReturned))->toBe(0);

    // Correction requested → approvers, not the requester; decided → the requester.
    $corrections = app(CorrectionService::class);
    $hr = $scenario->hr();
    $request = $corrections->request(WorkflowScenario::actor($hr), $hr, $scenario->actual('SC-QUALITY', 'W-002')->id, '85', null, 'Salah input');
    drainOutbox();

    expect(notificationsFor($scenario->management->id, NotificationCategory::Correction))->toBe(1)
        ->and(notificationsFor($hr->id, NotificationCategory::Correction))->toBe(0);

    $corrections->reject(WorkflowScenario::actor($scenario->management), $scenario->management, $request->id, $request->lock_version, 'Bukti kurang');
    drainOutbox();

    expect(notificationsFor($hr->id, NotificationCategory::Correction))->toBe(1);

    // No notification text carries a score or rating.
    foreach (UserNotification::query()->get() as $notification) {
        expect($notification->title.$notification->body)->not->toMatch('/\d+\.\d{2,}|Band uji/');
    }
});

test('a corrective action notifies its owner when assigned and its managers when completion is reported', function (): void {
    $scenario = new TeamReviewScenario;
    $action = $scenario->action();
    drainOutbox();

    expect(notificationsFor($scenario->member->id, NotificationCategory::CorrectiveAction))->toBe(1)
        ->and(notificationsFor($scenario->leader->id))->toBe(0);

    $scenario->completed($action);
    drainOutbox();

    expect(notificationsFor($scenario->leader->id, NotificationCategory::CorrectiveAction))->toBe(1)
        ->and(notificationsFor($scenario->member->id, NotificationCategory::CorrectiveAction))->toBe(1);
});

test('without approved rules the scheduler sends no reminder, and an invalid rule stops the run', function (): void {
    $scenario = new TeamReviewScenario;
    $scenario->action(null, null, '2026-10-12');

    expect(config('notifications.reminder_rules'))->toBe([]);

    $this->artisan('notifications:send-reminders')->expectsOutputToContain('No approved reminder rules')->assertSuccessful();
    expect(UserNotification::query()->where('category', NotificationCategory::Reminder->value)->count())->toBe(0);

    Config::set('notifications.reminder_rules', [['id' => 'x', 'type' => 'corrective_action_due', 'offset_days' => -1]]);
    $this->artisan('notifications:send-reminders')->assertFailed();

    foreach ([
        ['id' => 'ok-1', 'type' => 'unknown', 'signoff_reference' => 'S', 'offset_days' => 0],
        ['id' => 'ok-1', 'type' => 'corrective_action_due', 'signoff_reference' => 'S', 'offset_days' => 2],
        ['id' => 'ok-1', 'type' => 'corrective_action_overdue', 'signoff_reference' => 'S', 'offset_days' => 0],
        ['id' => 'ok-1', 'type' => 'period_deadline', 'signoff_reference' => 'S', 'offset_days' => -1, 'stage' => 'review'],
        ['id' => 'ok-1', 'type' => 'corrective_action_due', 'signoff_reference' => 'S', 'offset_days' => -1, 'surprise' => true],
    ] as $invalid) {
        expect(fn () => ReminderRule::fromArray($invalid))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => ReminderRule::listFrom([
        ['id' => 'same', 'type' => 'corrective_action_due', 'signoff_reference' => 'S', 'offset_days' => -1],
        ['id' => 'same', 'type' => 'corrective_action_due', 'signoff_reference' => 'S', 'offset_days' => 0],
    ]))->toThrow(InvalidArgumentException::class);
});

test('corrective action reminders and escalation follow the approved rules and are idempotent', function (): void {
    $scenario = new TeamReviewScenario;
    $action = $scenario->action(null, null, '2026-10-14');
    $done = $scenario->completed($scenario->action(null, null, '2026-10-14'));
    app(TeamReviewService::class)->verify(TeamReviewScenario::actor($scenario->leader), $scenario->leader, $done->id, $done->lock_version);

    Config::set('notifications.reminder_rules', [
        ['id' => 'ca-due-2', 'type' => 'corrective_action_due', 'signoff_reference' => 'TEST-ONLY', 'offset_days' => -2],
        ['id' => 'ca-late-3', 'type' => 'corrective_action_overdue', 'signoff_reference' => 'TEST-ONLY', 'offset_days' => 3, 'escalate_to_role' => 'department_head'],
    ]);
    $reminders = app(ReminderService::class);
    $before = UserNotification::query()->count();

    // Two days before the due date: the owner of the outstanding action only.
    expect($reminders->run(CarbonImmutable::now()))->toBe(['rules' => 2, 'sent' => 1])
        ->and($reminders->run(CarbonImmutable::now()))->toBe(['rules' => 2, 'sent' => 0])
        ->and(notificationsFor($scenario->member->id, NotificationCategory::Reminder))->toBe(1);

    // The next day nothing is due under these rules.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 01:00:00', 'UTC'));
    expect($reminders->run(CarbonImmutable::now())['sent'])->toBe(0);

    // Three days after the due date: owner reminded, department head escalated, once.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 01:00:00', 'UTC'));
    $this->artisan('notifications:send-reminders')->expectsOutputToContain('notifications created: 2')->assertSuccessful();
    $this->artisan('notifications:send-reminders')->expectsOutputToContain('notifications created: 0')->assertSuccessful();

    expect(notificationsFor($scenario->member->id, NotificationCategory::Reminder))->toBe(2)
        ->and(notificationsFor($scenario->head->id, NotificationCategory::Escalation))->toBe(1)
        ->and(notificationsFor($scenario->leader->id, NotificationCategory::Escalation))->toBe(0)
        ->and(UserNotification::query()->count())->toBe($before + 3)
        ->and($action->refresh()->status->isOutstanding())->toBeTrue();
});

test('period deadline reminders go to the configured role over the period scope', function (): void {
    $scenario = new WorkflowScenario;
    $period = app(PeriodService::class)->rescheduleDeadline(WorkflowScenario::actor($scenario->hr()), $scenario->period->id, $scenario->period->refresh()->lock_version, DeadlineStage::Review, '2026-10-14', 'Jadwal review');

    Config::set('notifications.reminder_rules', [
        ['id' => 'review-2', 'type' => 'period_deadline', 'signoff_reference' => 'TEST-ONLY', 'offset_days' => -2, 'stage' => 'review', 'audience_role' => 'department_head'],
    ]);
    $reminders = app(ReminderService::class);
    $before = notificationsFor($scenario->manager->id, NotificationCategory::Reminder);

    expect($period->status)->toBe(PeriodStatus::Review)
        ->and($reminders->run(CarbonImmutable::now())['sent'])->toBe(1)
        ->and($reminders->run(CarbonImmutable::now())['sent'])->toBe(0)
        ->and(notificationsFor($scenario->manager->id, NotificationCategory::Reminder))->toBe($before + 1)
        ->and(notificationsFor($scenario->management->id, NotificationCategory::Reminder))->toBe(0);
});

test('a deep link belongs to its recipient, marks the notification read, and leaves authorization to the destination', function (): void {
    $scenario = new TeamReviewScenario;
    $action = $scenario->action();
    $service = app(NotificationService::class);
    $toAction = new NotificationMessage(NotificationCategory::CorrectiveAction, 'Tindakan', 'Isi', 'link-1', 'team-review.action', ['action' => $action->public_id]);
    $service->notify([$scenario->member->id, $scenario->otherLeader->id], $toAction);
    $own = UserNotification::query()->where('user_id', $scenario->member->id)->firstOrFail();
    $foreign = UserNotification::query()->where('user_id', $scenario->otherLeader->id)->firstOrFail();

    // Someone else's notification does not exist for this account.
    $this->actingAs($scenario->leader)->get(route('notifications.open', $own))->assertNotFound();
    expect($own->refresh()->read_at)->toBeNull();

    $this->actingAs($scenario->member)->get(route('notifications.open', $own))->assertRedirect(route('team-review.action', $action));
    expect($own->refresh()->read_at)->not->toBeNull();
    $this->actingAs($scenario->member)->get(route('team-review.action', $action))->assertOk();

    // A recipient without access to the record is redirected and then refused there.
    $this->actingAs($scenario->otherLeader)->get(route('notifications.open', $foreign))->assertRedirect(route('team-review.action', $action));
    $this->actingAs($scenario->otherLeader)->get(route('team-review.action', $action))->assertForbidden();

    // A link to a route that no longer exists falls back to the list.
    $service->notify([$scenario->member->id], new NotificationMessage(NotificationCategory::Reminder, 'Lama', 'Isi', 'link-2', 'route.yang.dihapus'));
    $stale = UserNotification::query()->where('deduplication_key', 'link-2')->firstOrFail();
    $this->actingAs($scenario->member)->get(route('notifications.open', $stale))->assertRedirect(route('notifications.index'));

    $this->get(route('notifications.open', $own))->assertRedirect();
});

test('a rejected actual notifies the account that submitted it', function (): void {
    $scorecards = new ScorecardScenario;
    $scorecards->enter($scorecards->target('SC-QUALITY', 'W-001'), '95.5');
    app(ActualService::class)->submit($scorecards->hrActor(), $scorecards->period->id, ActualAccess::everything());
    $version = KpiActualVersion::query()->firstOrFail();

    app(ActualService::class)->reject(new Actor($scorecards->leader->id, Actor::system('test')->audit), $version->id, $version->lock_version, ActualAccess::everything(), 'Angka tidak sesuai laporan');
    drainOutbox();

    expect(notificationsFor($scorecards->hr()->id, NotificationCategory::ActualRejected))->toBe(1)
        ->and(notificationsFor($scorecards->leader->id))->toBe(0)
        ->and(UserNotification::query()->firstOrFail()->body)->not->toContain('95.5');
});

test('each scorecard that reaches a stage notifies its approvers, and lost email dispatches are queued again', function (): void {
    $scenario = new WorkflowScenario;
    $scenario->approve($scenario->manager, 'W-001');
    $scenario->approve($scenario->manager, 'W-002');
    drainOutbox();

    // Two scorecards reached calibration on the same day: two task notifications per HR approver.
    expect(notificationsFor($scenario->hr()->id, NotificationCategory::ApprovalTask))->toBe(2);

    $scenario->calibrate($scenario->hr(), 'W-001');
    drainOutbox();

    expect(notificationsFor($scenario->management->id, NotificationCategory::ApprovalTask))->toBe(1);

    Config::set('notifications.email.enabled', true);
    Queue::fake();
    $service = app(NotificationService::class);
    $service->notify([$scenario->hr()->id], message('lost-1'));

    expect($service->requeuePendingEmails(CarbonImmutable::now()->subMinutes(15)))->toBe(0);

    $this->travelTo(CarbonImmutable::now()->addHour());
    expect($service->requeuePendingEmails(CarbonImmutable::now()->subMinutes(15)))->toBe(1);
    Queue::assertPushed(SendNotificationEmail::class, 2);
});
