<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * Allowlist of score strategies. Only keys/versions registered here can be
 * stored on a KPI definition; there is no expression engine and no user
 * supplied code.
 */
final class ScoreStrategyRegistry
{
    /** @var array<string, ScoreStrategy> keyed by "key@version" */
    private array $strategies = [];

    public function __construct()
    {
        foreach ([
            new RatioToTargetStrategy,
            new RatioToTargetStrategy(inverse: true),
            new RangeBandStrategy,
            new BinaryStrategy,
            new MilestoneStageStrategy,
        ] as $strategy) {
            $this->strategies[$strategy->key().'@'.$strategy->version()] = $strategy;
        }
    }

    public function find(?string $key, ?int $version): ?ScoreStrategy
    {
        if ($key === null || $version === null) {
            return null;
        }

        return $this->strategies["{$key}@{$version}"] ?? null;
    }

    /**
     * Latest version of every strategy key, for new drafts.
     *
     * @return array<string, ScoreStrategy> keyed by strategy key
     */
    public function current(): array
    {
        $current = [];

        foreach ($this->strategies as $strategy) {
            $existing = $current[$strategy->key()] ?? null;

            if ($existing === null || $existing->version() < $strategy->version()) {
                $current[$strategy->key()] = $strategy;
            }
        }

        return $current;
    }
}
