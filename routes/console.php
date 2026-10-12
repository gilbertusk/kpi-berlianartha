<?php

use Illuminate\Support\Facades\Schedule;

// Liveness heartbeat checked by the scheduler container healthcheck.
Schedule::command('ops:heartbeat scheduler')->everyMinute();
