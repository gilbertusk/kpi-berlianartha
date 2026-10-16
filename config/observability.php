<?php

return [

    /*
    | One structured `http.request` entry per request: method, route name,
    | status, duration, and the pseudonymous user id. Never the query string,
    | body, headers, or cookies.
    */
    'request_log' => (bool) env('OBSERVABILITY_REQUEST_LOG', true),

    /*
    | Queries slower than this are logged as `db.slow_query` with their SQL
    | text and never their bindings (which may hold personal data).
    */
    'slow_query_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 500),

    /*
    | Context keys whose values are replaced by [REDACTED] in every log entry,
    | matched case-insensitively as substrings.
    */
    'redact_keys' => [
        'password', 'passwd', 'secret', 'token', 'cookie', 'authorization', 'api_key', 'apikey',
        'hmac', 'signature', 'session', 'credential', 'private_key', 'two_factor', 'recovery_code', 'otp',
    ],

    /*
    | Proxies whose X-Forwarded-* headers are trusted (comma list of IPs or
    | CIDRs, or `*` behind a managed load balancer). Needed so HTTPS behind a
    | TLS-terminating proxy is recognised for secure cookies and HSTS.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Content Security Policy. Livewire and Alpine need inline and eval script
    | execution; every source is limited to this origin.
    */
    'content_security_policy' => env('CONTENT_SECURITY_POLICY') ?: implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ]),

];
