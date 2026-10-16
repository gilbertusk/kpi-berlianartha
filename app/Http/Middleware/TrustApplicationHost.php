<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outside local and tests only the APP_URL host is answered (any other Host
 * gets 400), so links built from the request (e-mail verification, signed
 * URLs) cannot be pointed at a host chosen by the client. `/health` stays
 * answerable by address for load balancer and container health checks; it
 * builds no links.
 */
final class TrustApplicationHost extends TrustHosts
{
    /**
     * @return array<int, string>
     */
    public function hosts(): array
    {
        $host = parse_url((string) Config::get('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? ['^'.preg_quote($host).'$'] : [];
    }

    /**
     * @param  \Closure  $next
     * @return Response
     */
    public function handle(Request $request, $next)
    {
        if ($request->getPathInfo() === '/health') {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
