<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustApplicationHost;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Modules\IdentityAccess\Http\Middleware\EnsureAccountIsActive;
use App\Modules\IdentityAccess\Http\Middleware\RequireMultiFactor;
use App\Modules\IdentityAccess\Http\Middleware\ThrottlePasswordResets;
use App\Modules\Shared\Application\Errors\ExceptionMapper;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Registered outside the "web" group: no session, cookies, or CSRF.
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id and request metrics for every request (Step 18).
        $middleware->prepend(RequestContext::class);

        // Behind a TLS-terminating proxy, HTTPS is only recognised when the
        // proxy is trusted (secure cookies, HSTS, signed URLs): TRUSTED_PROXIES.
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        // Only the APP_URL host is answered (host header poisoning).
        $middleware->prepend(TrustApplicationHost::class);

        // Every web request (including Livewire updates) re-checks that the
        // signed-in account is still active (PRD §5.1, ADR-0009).
        $middleware->web(append: [EnsureAccountIsActive::class, SecurityHeaders::class, ThrottlePasswordResets::class]);

        $middleware->alias([
            'mfa' => RequireMultiFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (SafeDomainException $exception, Request $request): ?JsonResponse {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $error = app(ExceptionMapper::class)->map($exception);

            return response()->json([
                'error' => [
                    'code' => $error->code->value,
                    'message' => $error->message,
                    'context' => $error->context,
                ],
            ], $error->httpStatus);
        });
    })->create();
