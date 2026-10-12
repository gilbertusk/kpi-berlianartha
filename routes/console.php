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
