<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id and request metrics. The id comes from a well-formed
 * `X-Request-Id` header (set by the proxy) or is generated; it is shared
 * with every log entry of the request and with the queued jobs it
 * dispatches (Laravel Context), and returned in the response.
 */
final class RequestContext
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $incoming) === 1 ? $incoming : (string) Str::ulid();
        Context::add('request_id', $id);
        $request->attributes->set('request_started_at', hrtime(true));

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! Config::get('observability.request_log', true)) {
            return;
        }

        $started = $request->attributes->get('request_started_at');
        $user = $request->user();

        Log::info('http.request', [
            'method' => $request->getMethod(),
            'route' => $request->route()?->getName() ?? 'unnamed',
            'status' => $response->getStatusCode(),
            'duration_ms' => is_int($started) ? round((hrtime(true) - $started) / 1e6, 1) : null,
            'user' => $user?->getAttribute('public_id'),
        ]);
    }
}
