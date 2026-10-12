<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Public liveness probe.
 *
 * Reports only that the application process can serve requests. It must never
 * expose environment, version, configuration, or dependency details; deeper
 * database/Redis/storage readiness checks belong to Step 03/18 and must not be
 * public.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['status' => 'ok'])
            ->header('Cache-Control', 'no-store');
    }
}
