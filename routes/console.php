<?php

use Illuminate\Support\Facades\Schedule;

// Liveness heartbeat checked by the scheduler container healthcheck.
Schedule::command('ops:heartbeat scheduler')->everyMinute();

// Polling only enqueues due rows. Processing remains idempotent in the worker.
Schedule::command('ops:outbox-dispatch')
    ->everyMinute()
    ->withoutOverlapping(2);

// Authorization already ignores expired delegations; this only writes the
// expiry audit entry (Step 05).
Schedule::command('identity:expire-delegations')
    ->hourly()
    ->withoutOverlapping(10);

// Reminders and escalations of the approved rules (DEC-009). Idempotent:
// each notification is keyed by rule, subject, and date, so the hourly run
// only adds what a new business date or a new deadline makes due.
Schedule::command('notifications:send-reminders')
    ->hourly()
    ->withoutOverlapping(10);
