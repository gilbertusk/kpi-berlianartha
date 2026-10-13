<?php

declare(strict_types=1);

use App\Modules\Scoring\Domain\Exact;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\KpiLineCalculator;
use App\Modules\Scoring\Domain\ScorecardCalculator;
use App\Modules\Scoring\Domain\Strategy\CalculatorRegistry;
use Brick\Math\BigDecimal;
use Tests\Support\ScoringFixtures as F;

/*
 * Property tests over generated inputs. Seeds are fixed, so a failure is
 * reproducible: the dataset name carries the seed.
 */

const SCORING_PROPERTY_CASES = 200;

function scoringRandomDecimal(int $maxWhole, int $decimals = 4): string
{
    $whole = mt_rand(0, $maxWhole);
    $fraction = $decimals === 0 ? '' : '.'.str_pad((string) mt_rand(0, 10 ** $decimals - 1), $decimals, '0', STR_PAD_LEFT);

    return $whole.$fraction;
}

dataset('seeds', ['seed 1' => [1], 'seed 2' => [2], 'seed 3' => [3], 'seed 4' => [4], 'seed 5' => [5]]);

test('ratio scores always stay within the signed floor and cap', function (int $seed): void {
    mt_srand($seed);
    $lines = new KpiLineCalculator(new CalculatorRegistry);

    for ($i = 0; $i < SCORING_PROPERTY_CASES; $i++) {
        $scale = mt_rand(0, 4);
        $floor = scoringRandomDecimal(50, $scale);
        $cap = (string) (100 + mt_rand(0, 100));
        $line = F::line([
            'strategyKey' => mt_rand(0, 1) === 1 ? 'ratio_to_target' : 'inverse_ratio_to_target',
            'targetValue' => scoringRandomDecimal(1000),
            'parameters' => F::ratioParameters(['score_floor' => $floor, 'score_cap' => $cap, 'zero_target_rule' => 'score_floor', 'zero_actual_rule' => 'score_cap', 'rounding_scale' => $scale]),
            'entries' => [F::entry(scoringRandomDecimal(2000))],
        ]);

        $result = $lines->calculate($line, 'include');

        expect($result->status)->toBe(KpiDataStatus::Scored)
            ->and($result->score->isGreaterThanOrEqualTo(BigDecimal::of($floor)))->toBeTrue("seed {$seed} case {$i}")
            ->and($result->score->isLessThanOrEqualTo(BigDecimal::of($cap)))->toBeTrue("seed {$seed} case {$i}");
    }
})->with('seeds');

test('a better actual never lowers the score', function (int $seed): void {
    mt_srand($seed);
    $lines = new KpiLineCalculator(new CalculatorRegistry);

    for ($i = 0; $i < SCORING_PROPERTY_CASES; $i++) {
        $target = (string) mt_rand(1, 500);
        $low = scoringRandomDecimal(800);
        $high = Exact::describe(Exact::parse($low)->plus(Exact::parse(scoringRandomDecimal(50))));
        $score = fn (string $strategy, string $actual) => $lines->calculate(F::line([
            'strategyKey' => $strategy,
            'targetValue' => $target,
            'parameters' => F::ratioParameters(['zero_actual_rule' => 'score_cap']),
            'entries' => [F::entry($actual)],
        ]), 'include')->score;

        expect($score('ratio_to_target', $high)->isGreaterThanOrEqualTo($score('ratio_to_target', $low)))->toBeTrue("higher, seed {$seed} case {$i}")
            ->and($score('inverse_ratio_to_target', $low)->isGreaterThanOrEqualTo($score('inverse_ratio_to_target', $high)))->toBeTrue("lower, seed {$seed} case {$i}");
    }
})->with('seeds');

test('complete scorecards reconcile: contributions sum to the total and the final is its rounding', function (int $seed): void {
    mt_srand($seed);
    $engine = F::engine();

    for ($i = 0; $i < SCORING_PROPERTY_CASES; $i++) {
        $weights = [mt_rand(1, 60), mt_rand(1, 30)];
        $weights[] = 100 - array_sum($weights);
        $lines = [];

        foreach ($weights as $index => $weight) {
            $lines[] = F::line([
                'kpiCode' => "K-{$index}",
                'weight' => "{$weight}.0000",
                'targetValue' => (string) mt_rand(1, 300),
                'parameters' => F::ratioParameters(['rounding_scale' => mt_rand(0, 4)]),
                'entries' => [F::entry(scoringRandomDecimal(400), '2026-11-01'), F::entry(mt_rand(0, 3) === 0 ? null : scoringRandomDecimal(400), '2026-11-02')],
            ]);
        }

        $policy = F::policy(['contribution_rounding' => ['mode' => 'half_even', 'scale' => mt_rand(2, 4)], 'final_rounding' => ['mode' => 'half_up', 'scale' => mt_rand(0, 2)]]);
        $result = $engine->calculate(F::card($lines), $policy);
        $sum = BigDecimal::zero();

        foreach ($result->lines as $line) {
            $sum = $sum->plus($line->contribution ?? BigDecimal::zero());
        }

        expect($result->isComplete())->toBeTrue("seed {$seed} case {$i}")
            ->and($sum->isEqualTo($result->contributionTotal))->toBeTrue()
            ->and($result->finalScore->isEqualTo($policy->finalRounding->apply($result->contributionTotal->toBigRational())))->toBeTrue()
            ->and($result->rating)->not->toBeNull();
    }
})->with('seeds');

test('calculation is deterministic and independent of entry order', function (int $seed): void {
    mt_srand($seed);
    $engine = F::engine();
    $policy = F::policy();

    for ($i = 0; $i < 50; $i++) {
        $entries = [];
        $days = mt_rand(1, 10);

        for ($day = 1; $day <= $days; $day++) {
            $entries[] = F::entry(mt_rand(0, 5) === 0 ? null : scoringRandomDecimal(150), sprintf('2026-11-%02d', $day));
        }

        $shuffled = $entries;
        shuffle($shuffled);
        $aggregation = ['sum', 'average', 'last_value', 'minimum', 'maximum', 'count'][mt_rand(0, 5)];
        $line = fn (array $list) => F::line(['aggregation' => $aggregation, 'entries' => $list]);
        $card = F::card([$line($entries)]);

        expect($engine->calculate(F::card([$line($shuffled)]), $policy)->toArray())->toBe($engine->calculate($card, $policy)->toArray())
            ->and(ScorecardCalculator::fingerprint(F::card([$line($shuffled)]), $policy))->toBe(ScorecardCalculator::fingerprint($card, $policy));
    }
})->with('seeds');

test('missing data is never scored as zero unless the KPI rule says so', function (int $seed): void {
    mt_srand($seed);
    $lines = new KpiLineCalculator(new CalculatorRegistry);

    for ($i = 0; $i < 50; $i++) {
        $rule = ['mark_not_applicable', 'block_until_provided', 'score_zero'][mt_rand(0, 2)];
        $result = $lines->calculate(F::line(['parameters' => F::ratioParameters(['missing_data_rule' => $rule])]), mt_rand(0, 1) === 1 ? 'include' : 'exclude');

        expect($result->score !== null)->toBe($rule === 'score_zero');
    }
})->with('seeds');
