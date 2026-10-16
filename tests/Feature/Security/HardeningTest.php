<?php

declare(strict_types=1);

use App\Http\Middleware\RequestContext;
use App\Http\Middleware\TrustApplicationHost;
use App\Modules\IdentityAccess\Domain\Role;
use App\Support\Observability\ApplyLogRedaction;
use App\Support\Observability\RedactSensitiveData;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\AccessFixtures;

/*
 * Step 18 hardening: browser security headers, correlation id, log
 * redaction, route protection, download throttling, and the production
 * configuration gate.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
});

test('web pages carry the security headers and HSTS only over HTTPS', function (): void {
    $plain = $this->get(route('login'));
    $secure = $this->get(str_replace('http://', 'https://', route('login')));

    $plain->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($plain->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($plain->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($plain->headers->get('Content-Security-Policy'))->toContain("object-src 'none'")
        ->and($plain->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
});

test('every request gets a correlation id, a well-formed incoming one is kept, and a malformed one is replaced', function (): void {
    $generated = $this->get('/health')->headers->get(RequestContext::HEADER);
    $kept = $this->withHeader(RequestContext::HEADER, 'proxy-req-12345678')->get('/health')->headers->get(RequestContext::HEADER);
    $replaced = $this->withHeader(RequestContext::HEADER, "bad id\nInjected: header")->get('/health')->headers->get(RequestContext::HEADER);

    expect($generated)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($kept)->toBe('proxy-req-12345678')
        ->and($replaced)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

test('request entries are structured and never carry the query string, and secrets are redacted from every entry', function (): void {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    Log::getLogger()->pushProcessor(new RedactSensitiveData(config('observability.redact_keys')));

    $this->get('/health?token=sentinel-query-token');
    Log::warning('integration failed: password=hunter2 Authorization: Bearer abc.def.ghi', [
        'api_key' => 'sentinel-api-key',
        'nested' => ['session_id' => 'sentinel-session', 'count' => 3],
        'note' => 'secret=sentinel-inline',
    ]);

    $records = array_map(fn (LogRecord $record): string => $record->message.' '.json_encode($record->context), $handler->getRecords());
    $all = implode("\n", $records);
    $request = collect($handler->getRecords())->first(fn (LogRecord $record): bool => $record->message === 'http.request');

    expect($request?->context)->toMatchArray(['method' => 'GET', 'route' => 'health', 'status' => 200])
        ->and($request?->level)->toBe(Level::Info)
        ->and($all)->not->toContain('sentinel-query-token')
        ->and($all)->not->toContain('sentinel-api-key')
        ->and($all)->not->toContain('sentinel-session')
        ->and($all)->not->toContain('sentinel-inline')
        ->and($all)->not->toContain('hunter2')
        ->and($all)->not->toContain('abc.def.ghi')
        ->and($all)->toContain('"count":3');
});

test('exceptions are logged as summaries: database errors without bound values, messages without secrets', function (): void {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    Log::getLogger()->pushProcessor(new RedactSensitiveData(config('observability.redact_keys')));

    try {
        DB::insert('insert into missing_table (email, password) values (?, ?)', ['sentinel-person@example.test', 'sentinel-hash']);
    } catch (QueryException $exception) {
        report($exception);
    }

    Log::error('upstream said {"password":"sentinel-json"}', [
        'exception' => new RuntimeException('call to https://svc:sentinel-pass@host.test failed, X-Api-Key: sentinel-header'),
        'privateKey' => 'sentinel-camel',
        'headers' => "Authorization: Token sentinel-token\nCookie: a=sentinel-c1; b=sentinel-c2",
    ]);

    $records = $handler->getRecords();
    $all = implode("\n", array_map(fn (LogRecord $record): string => $record->message.' '.json_encode($record->context), $records));
    $database = collect($records)->first(fn (LogRecord $record): bool => str_starts_with($record->message, 'Database error SQLSTATE'));

    expect($database)->not->toBeNull()
        ->and($database?->context['exception']['class'] ?? null)->toBe(QueryException::class)
        ->and($database?->context['exception']['sql'] ?? '')->toContain('insert into missing_table')
        ->and($database?->context['exception']['trace'] ?? [])->not->toBeEmpty();

    foreach (['sentinel-person', 'sentinel-hash', 'sentinel-json', 'sentinel-pass', 'sentinel-header', 'sentinel-camel', 'sentinel-token', 'sentinel-c1', 'sentinel-c2'] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

test('every configured log channel that writes entries carries the redaction processor', function (): void {
    // Channels with a remote handler need an address to be built at all.
    config(['logging.channels.slack.url' => 'https://hooks.example.test/x', 'logging.channels.papertrail.handler_with.host' => '127.0.0.1', 'logging.channels.papertrail.handler_with.port' => 514]);
    $channels = array_diff(array_keys(config('logging.channels')), ['stack', 'null', 'emergency']);

    // Papertrail needs ext-sockets, which the images do not install; its
    // configuration carries the same tap.
    if (! extension_loaded('sockets')) {
        expect(config('logging.channels.papertrail.tap'))->toContain(ApplyLogRedaction::class);
        $channels = array_diff($channels, ['papertrail']);
    }

    foreach ($channels as $channel) {
        $processors = Log::channel($channel)->getLogger()->getProcessors();

        expect(collect($processors)->contains(fn ($processor): bool => $processor instanceof RedactSensitiveData))->toBeTrue("channel {$channel}");
    }
});

test('a request id with a trailing newline is replaced', function (): void {
    $id = $this->withHeader(RequestContext::HEADER, "abcdefgh\n")->get('/health')->headers->get(RequestContext::HEADER);

    expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

test('reset links always use APP_URL, and proxies never decide the host', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.19.'.random_int(0, 255).'.'.random_int(1, 254)]);
    Notification::fake();
    config(['app.url' => 'https://kpi.example.test', 'observability.trusted_proxies' => '*']);
    $user = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    Route::get('/_test/host', fn (Request $request): string => $request->getHost().'|'.$request->getScheme());

    $seen = $this->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])->get('/_test/host')->getContent();
    $this->flushHeaders()->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])
        ->post('http://evil.example/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://kpi.example.test/reset-password/') && ! str_contains($url, 'evil');
    });
    expect($seen)->toBe('localhost|https')
        ->and(app(TrustApplicationHost::class)->hosts())->toBe(['^kpi\.example\.test$']);
});

test('every route is authenticated except the reviewed public ones, and downloads are signed and throttled', function (): void {
    $public = [
        'home', 'health', 'login', 'login.store', 'password.request', 'password.email', 'password.reset', 'password.update',
        'two-factor.login', 'two-factor.login.store', 'passkey.login', 'passkey.login-options', 'well-known.passkeys',
        'default-livewire.update', 'livewire.upload-file', 'livewire.preview-file', '__flux.flag',
    ];
    $unprotected = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $name = $route->getName();
        $asset = str_starts_with($route->uri(), 'flux/') || str_contains($route->uri(), 'livewire-') && in_array('GET', $route->methods(), true) && $name === null;

        if ($asset || in_array($name, $public, true)) {
            continue;
        }

        if (array_filter($middleware, fn (string $item): bool => $item === 'auth' || str_starts_with($item, 'auth:')) === []) {
            $unprotected[] = $route->uri();
        }
    }

    expect($unprotected)->toBe([])
        ->and(Route::getRoutes()->getByName('evidence.download')?->gatherMiddleware())->toContain('signed', 'throttle:downloads')
        ->and(Route::getRoutes()->getByName('reports.exports.download')?->gatherMiddleware())->toContain('signed', 'throttle:downloads')
        ->and(collect(Route::getRoutes())->contains(fn ($route): bool => str_starts_with($route->uri(), 'storage/')))->toBeFalse();
});

test('downloads are rate limited per account, also for unknown files and invalid signatures', function (): void {
    $user = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $url = route('evidence.download', ['evidence' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'viewer' => $user->public_id]);
    // Limiter state lives in the cache store (Redis in the PostgreSQL suite) and
    // account ids repeat between runs: start from an empty bucket.
    RateLimiter::clear(md5('downloads'.$user->getAuthIdentifier()));

    // Unknown evidence: each attempt is counted before the lookup answers 404.
    foreach (range(1, 60) as $attempt) {
        $this->actingAs($user)->get($url)->assertNotFound();
    }

    $this->actingAs($user)->get($url)->assertTooManyRequests();
});

test('the password reset form answers the same for unknown and known addresses and is rate limited', function (): void {
    // Limiter state lives in the cache store (Redis in the PostgreSQL suite): a
    // fresh client address keeps this test independent of earlier ones.
    $this->withServerVariables(['REMOTE_ADDR' => '10.18.'.random_int(0, 255).'.'.random_int(1, 254)]);
    Notification::fake();
    $user = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $again = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);     // broker throttle
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@fixtures.example.test']);

    foreach ([$known, $again, $unknown] as $response) {
        $response->assertRedirect(route('password.request'))->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    }

    Notification::assertSentToTimes($user, ResetPassword::class, 1);

    foreach (range(1, 2) as $attempt) {
        $this->post(route('password.email'), ['email' => "probe{$attempt}@fixtures.example.test"])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'probe6@fixtures.example.test'])->assertTooManyRequests();

    // The reset form has its own bucket, so one does not lock the other.
    $reset = ['token' => 'x', 'email' => $user->email, 'password' => 'irrelevant', 'password_confirmation' => 'irrelevant'];

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.update'), $reset)->assertRedirect();
    }

    $this->post(route('password.update'), $reset)->assertTooManyRequests();
});

test('the production configuration gate fails on critical settings and never prints a value', function (): void {
    config(['app.debug' => true, 'app.url' => 'http://kpi.example.test', 'session.secure' => null, 'app.key' => 'base64:sentinel-app-key']);

    $this->artisan('ops:security-check')->assertSuccessful();             // not production: report only
    $this->artisan('ops:security-check', ['--strict' => true])
        ->expectsOutputToContain('APP_DEBUG mati')
        ->doesntExpectOutputToContain('sentinel-app-key')
        ->assertFailed();

    config(['app.debug' => false, 'app.url' => 'https://kpi.example.test', 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);

    $this->artisan('ops:security-check', ['--strict' => true])->assertSuccessful();
});
