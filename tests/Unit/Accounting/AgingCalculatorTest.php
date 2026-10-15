<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\AgedInvoice;
use App\Modules\Accounting\Domain\AgingBuckets;
use App\Modules\Accounting\Domain\AgingCalculator;

function prdBuckets(): AgingBuckets
{
    return AgingBuckets::fromArray(AgingBuckets::PRD_DEFAULT);
}

test('the default buckets are the PRD buckets', function (): void {
    expect(prdBuckets()->labels())->toBe([
        'current' => 'Belum jatuh tempo',
        'd1_30' => '1–30 hari',
        'd31_60' => '31–60 hari',
        'd61_90' => '61–90 hari',
        'over_90' => 'Lebih dari 90 hari',
    ])->and(prdBuckets()->version)->toBe('prd-10.2');
});

test('days past due fall into the right bucket at every boundary', function (string $due, string $bucket, int $days): void {
    $aged = AgingCalculator::age(prdBuckets(), '2026-10-31', 'INV-1', '100.00', $due, []);

    expect($aged->daysPastDue)->toBe($days)
        ->and($aged->bucket)->toBe($bucket)
        ->and($aged->state)->toBe(AgingCalculator::OPEN);
})->with([
    'due tomorrow' => ['2026-11-01', 'current', -1],
    'due on the as-of date' => ['2026-10-31', 'current', 0],
    '1 day' => ['2026-10-30', 'd1_30', 1],
    '30 days' => ['2026-10-01', 'd1_30', 30],
    '31 days' => ['2026-09-30', 'd31_60', 31],
    '60 days' => ['2026-09-01', 'd31_60', 60],
    '61 days' => ['2026-08-31', 'd61_90', 61],
    '90 days' => ['2026-08-02', 'd61_90', 90],
    '91 days' => ['2026-08-01', 'over_90', 91],
]);

test('partial payments lower the outstanding exactly; full payment and over-allocation are not aged', function (): void {
    $partial = AgingCalculator::age(prdBuckets(), '2026-10-31', 'INV-1', '1000.10', '2026-10-01', ['0.10', '300.00']);
    $paid = AgingCalculator::age(prdBuckets(), '2026-10-31', 'INV-2', '0.30', '2026-10-01', ['0.10', '0.20']);
    $over = AgingCalculator::age(prdBuckets(), '2026-10-31', 'INV-3', '100.00', '2026-10-01', ['120.00']);

    expect($partial->outstanding)->toBe('700.00')
        ->and($partial->allocated)->toBe('300.10')
        ->and($partial->bucket)->toBe('d1_30')
        // 0.1 + 0.2 is exactly 0.3: no floating point.
        ->and($paid->state)->toBe(AgedInvoice::PAID)
        ->and($paid->bucket)->toBeNull()
        ->and($over->state)->toBe(AgedInvoice::OVER_ALLOCATED)
        ->and($over->outstanding)->toBe('-20.00')
        ->and($over->bucket)->toBeNull();

    // A very large amount keeps every cent.
    expect(AgingCalculator::age(prdBuckets(), '2026-10-31', 'INV-4', '99999999999999.99', '2026-10-01', ['0.01'])->outstanding)->toBe('99999999999999.98');
});

test('another bucket set needs its own version and valid bounds', function (array $config): void {
    expect(fn () => AgingBuckets::fromArray($config))->toThrow(InvalidArgumentException::class);
})->with([
    'no version' => [['upper_bounds' => [30]]],
    'empty bounds' => [['version' => 'v2', 'upper_bounds' => []]],
    'not ascending' => [['version' => 'v2', 'upper_bounds' => [30, 30]]],
    'zero bound' => [['version' => 'v2', 'upper_bounds' => [0, 30]]],
    'text bound' => [['version' => 'v2', 'upper_bounds' => ['30']]],
]);

test('a versioned bucket set changes the labels and the assignment', function (): void {
    $buckets = AgingBuckets::fromArray(['version' => 'dec-2026-01', 'upper_bounds' => [15, 45]]);

    expect(array_values($buckets->labels()))->toBe(['Belum jatuh tempo', '1–15 hari', '16–45 hari', 'Lebih dari 45 hari'])
        ->and($buckets->bucketFor(16))->toBe('d16_45')
        ->and($buckets->bucketFor(46))->toBe('over_45');
});

test('days are counted in calendar days across month ends and leap days', function (): void {
    expect(AgingCalculator::days('2028-02-28', '2028-03-01'))->toBe(2)
        ->and(AgingCalculator::days('2026-02-28', '2026-03-01'))->toBe(1)
        ->and(AgingCalculator::days('2026-12-31', '2027-01-01'))->toBe(1)
        ->and(AgingCalculator::days('2026-10-31', '2026-10-01'))->toBe(-30);
});
