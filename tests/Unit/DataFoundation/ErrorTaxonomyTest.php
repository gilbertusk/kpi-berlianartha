<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Audit\AuditPayloadSanitizer;
use App\Modules\Shared\Application\Errors\ExceptionMapper;
use App\Modules\Shared\Domain\ErrorCode;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;

test('error mapper exposes only the safe concurrency presentation', function (): void {
    $presentation = (new ExceptionMapper)->map(
        new ConcurrencyConflict('object', 3),
    );

    expect($presentation->code)->toBe(ErrorCode::CONCURRENCY_CONFLICT)
        ->and($presentation->httpStatus)->toBe(409)
        ->and($presentation->context)->toBe([
            'entity_type' => 'object',
            'expected_version' => 3,
        ]);
});

test('unexpected exception details are never presented to the user', function (): void {
    $presentation = (new ExceptionMapper)->map(
        new RuntimeException('database password=do-not-leak'),
    );

    expect($presentation->code)->toBe(ErrorCode::UNEXPECTED)
        ->and($presentation->httpStatus)->toBe(500)
        ->and($presentation->message)->not->toContain('do-not-leak');
});

test('audit sanitizer redacts nested secrets and truncates oversized strings', function (): void {
    $sanitized = (new AuditPayloadSanitizer)->sanitize([
        'email' => 'employee@example.test',
        'password' => 'secret-value',
        'nested' => [
            'api_token' => 'token-value',
            'note' => str_repeat('a', 4100),
        ],
    ]);

    expect($sanitized['password'])->toBe('[REDACTED]')
        ->and($sanitized['nested']['api_token'])->toBe('[REDACTED]')
        ->and($sanitized['nested']['note'])->toEndWith('…[TRUNCATED]');
});
