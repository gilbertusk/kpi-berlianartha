<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Domain\ReminderRule;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriodDeadline;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Sends the reminders and escalations of the approved rules for today's
 * business date. Running it again on the same day sends nothing new: every
 * notification is keyed by rule, subject, and the date it is about.
 */
final readonly class ReminderService
{
    public function __construct(
        private NotificationService $notifications,
        private RecipientResolver $recipients,
        private BusinessTime $time,
    ) {}

    /**
     * @return list<ReminderRule>
     *
     * @throws InvalidArgumentException when the configuration is not valid
     */
    public function rules(): array
    {
        $rules = Config::get('notifications.reminder_rules', []);

        return ReminderRule::listFrom(is_array($rules) ? $rules : throw new InvalidArgumentException('Aturan pengingat tidak valid: notifications.reminder_rules harus berupa array.'));
    }

    /**
     * @return array{rules: int, sent: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $rules = $this->rules();
        $today = $this->time->businessDate($now);
        $sent = 0;

        foreach ($rules as $rule) {
            $sent += $rule->type === 'period_deadline' ? $this->periodDeadline($rule, $today) : $this->correctiveActions($rule, $today);
        }

        return ['rules' => count($rules), 'sent' => $sent];
    }

    private function periodDeadline(ReminderRule $rule, string $today): int
    {
        // The deadline this rule fires for today: due_on + offset = today.
        $dueOn = CarbonImmutable::parse($today)->subDays($rule->offsetDays)->toDateString();
        $sent = 0;

        $deadlines = AssessmentPeriodDeadline::query()
            ->where('stage', (string) $rule->stage)
            ->where('due_on', $dueOn)
            ->whereIn('assessment_period_id', AssessmentPeriod::query()
                ->whereIn('status', [PeriodStatus::Open->value, PeriodStatus::InProgress->value, PeriodStatus::Review->value])
                ->select('id'))
            ->get();

        foreach ($deadlines as $deadline) {
            $period = AssessmentPeriod::query()->findOrFail($deadline->assessment_period_id);
            $role = Role::from((string) $rule->audienceRole);

            $recipients = $role === Role::Employee
                ? Employee::query()->whereNotNull('user_id')->whereIn('id', PeriodAssignment::query()->where('assessment_period_id', $period->id)->whereNotNull('employee_id')->select('employee_id'))->pluck('user_id')->map(static fn ($id): int => (int) $id)->all()
                : $this->recipients->withRoleInside($role, $period->organization_unit_id);

            $when = match (true) {
                $rule->offsetDays < 0 => abs($rule->offsetDays).' hari lagi',
                $rule->offsetDays === 0 => 'hari ini',
                default => "sudah lewat {$rule->offsetDays} hari",
            };

            $sent += $this->notifications->notify($recipients, new NotificationMessage(
                category: $rule->offsetDays > 0 ? NotificationCategory::Escalation : NotificationCategory::Reminder,
                title: "Tenggat {$deadline->stage->label()} periode {$period->code}",
                body: "Tenggat tahap {$deadline->stage->label()} periode {$period->code} adalah {$deadline->due_on} ({$when}).",
                deduplicationKey: "reminder:{$rule->id}:{$period->public_id}:{$deadline->due_on}",
                routeName: $role === Role::Employee ? 'dashboard' : 'periods.show',
                routeParameters: $role === Role::Employee ? [] : ['period' => $period->public_id],
            ));
        }

        return $sent;
    }

    private function correctiveActions(ReminderRule $rule, string $today): int
    {
        $dueOn = CarbonImmutable::parse($today)->subDays($rule->offsetDays)->toDateString();
        $sent = 0;

        $actions = CorrectiveAction::query()
            ->whereIn('status', [ActionStatus::Open->value, ActionStatus::InProgress->value, ActionStatus::Waiting->value])
            ->where('due_on', $dueOn)
            ->orderBy('id')
            ->get();

        foreach ($actions as $action) {
            $overdue = $rule->type === 'corrective_action_overdue';
            $key = "reminder:{$rule->id}:{$action->public_id}:{$action->due_on}";

            $sent += $this->notifications->notify([$action->owner_user_id], new NotificationMessage(
                category: NotificationCategory::Reminder,
                title: $overdue ? 'Corrective action melewati tenggat' : 'Corrective action mendekati tenggat',
                body: $overdue
                    ? "Corrective action Anda melewati tenggat {$action->due_on} sebanyak {$rule->offsetDays} hari."
                    : "Corrective action Anda jatuh tempo pada {$action->due_on}.",
                deduplicationKey: $key,
                routeName: 'team-review.action',
                routeParameters: ['action' => $action->public_id],
            ));

            if ($overdue && $rule->escalateToRole !== null) {
                $sent += $this->notifications->notify(
                    array_values(array_diff($this->recipients->withRole(Role::from($rule->escalateToRole), $action->organization_unit_id), [$action->owner_user_id])),
                    new NotificationMessage(
                        category: NotificationCategory::Escalation,
                        title: 'Eskalasi: corrective action melewati tenggat',
                        body: "Sebuah corrective action di unit Anda melewati tenggat {$action->due_on} sebanyak {$rule->offsetDays} hari.",
                        deduplicationKey: "{$key}:escalation",
                        routeName: 'team-review.action',
                        routeParameters: ['action' => $action->public_id],
                    ),
                );
            }
        }

        return $sent;
    }
}
