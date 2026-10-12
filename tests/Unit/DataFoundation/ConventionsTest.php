<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Domain\EffectivePeriod;
use App\Modules\Shared\Domain\Exceptions\InvalidStateTransition;
use App\Modules\Shared\Domain\StateTransitionGuard;

test('UTC timestamps cross the Asia Jakarta business date boundary explicitly', function (): void {
    $time = new BusinessTime('Asia/Jakarta');
    $beforeBoundary = new DateTimeImmutable('2026-10-11T16:59:59+00:00');
    $atBoundary = new DateTimeImmutable('2026-10-11T17:00:00+00:00');

    expect($time->businessDate($beforeBoundary))->toBe('2026-10-11')
        ->and($time->businessDate($atBoundary))->toBe('2026-10-12')
        ->and($time->businessMidnightToUtc('2026-10-12')->format(DATE_ATOM))
        ->toBe('2026-10-11T17:00:00+00:00');
});

test('effective periods are half open and reject overlap correctly', function (): void {
    $first = new EffectivePeriod('2026-01-01', '2026-02-01');
    $adjacent = new EffectivePeriod('2026-02-01', '2026-03-01');
    $overlap = new EffectivePeriod('2026-01-31', null);

    expect($first->contains('2026-01-01'))->toBeTrue()
        ->and($first->contains('2026-01-31'))->toBeTrue()
        ->and($first->contains('2026-02-01'))->toBeFalse()
        ->and($first->overlaps($adjacent))->toBeFalse()
        ->and($first->overlaps($overlap))->toBeTrue();
});

test('state transition guard owns no workflow and rejects an unknown edge', function (): void {
    $guard = new StateTransitionGuard;
    $transitions = ['draft' => ['submitted'], 'submitted' => ['approved', 'rejected']];

    $guard->assertAllowed('draft', 'submitted', $transitions);

    expect(fn () => $guard->assertAllowed('draft', 'approved', $transitions))
        ->toThrow(InvalidStateTransition::class);
});
