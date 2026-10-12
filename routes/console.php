<?php

use Illuminate\Support\Facades\Schedule;

// Liveness heartbeat checked by the scheduler container healthcheck.
Schedule::command('ops:heartbeat scheduler')->everyMinute();

// Polling only enqueues due rows. Processing remains idempotent in the worker.
Schedule::command('ops:outbox-dispatch')
    ->everyMinute()
    ->withoutOverlapping(2);
