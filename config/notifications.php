<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email channel
    |--------------------------------------------------------------------------
    |
    | In-app notifications are always delivered. The email copy stays off
    | until an email service and sender are approved (DEC-014). When on,
    | each user can switch email off per category.
    |
    */

    'email' => [
        'enabled' => (bool) env('NOTIFICATION_EMAIL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminder and escalation rules (DEC-009)
    |--------------------------------------------------------------------------
    |
    | Each rule is an approved decision: when to remind, about what, and whom
    | to escalate to. The system has no built-in rule. While DEC-009 is open
    | this list is empty and the scheduler sends no reminder or escalation.
    |
    | Shape (see App\Modules\Notification\Domain\ReminderRule):
    |   ['id' => '...', 'type' => 'period_deadline', 'signoff_reference' => '...',
    |    'offset_days' => -2, 'stage' => 'verification', 'audience_role' => 'team_leader']
    |   ['id' => '...', 'type' => 'corrective_action_due', 'signoff_reference' => '...', 'offset_days' => -1]
    |   ['id' => '...', 'type' => 'corrective_action_overdue', 'signoff_reference' => '...',
    |    'offset_days' => 3, 'escalate_to_role' => 'department_head']
    |
    */

    'reminder_rules' => [],

];
