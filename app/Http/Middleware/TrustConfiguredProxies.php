<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Trusts the proxies named in `observability.trusted_proxies`
 * (TRUSTED_PROXIES), so HTTPS behind a TLS-terminating proxy is recognised
 * for secure cookies, HSTS, and signed URLs. Nothing is trusted by default.
 * Only the client address, port, and scheme are taken from the proxy; the
 * host always comes from the request itself and is checked by TrustHosts.
 */
final class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $proxies = Config::get('observability.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return null;
        }

        return trim($proxies) === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies))));
    }
}
