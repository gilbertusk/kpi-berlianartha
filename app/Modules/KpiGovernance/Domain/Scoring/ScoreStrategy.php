<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * A whitelisted score rule. A strategy only declares and validates its
 * parameters here; the deterministic calculation is added by the scoring
 * engine (Step 09) under the same key and version. A version's meaning never
 * changes: different behaviour requires a new version number.
 */
interface ScoreStrategy
{
    public function key(): string;

    public function version(): int;

    public function label(): string;

    /**
     * @return list<string> direction keys from Vocabulary::DIRECTIONS
     */
    public function supportedDirections(): array;

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<string> safe, user-facing validation messages
     */
    public function validate(array $parameters): array;

    /**
     * Canonical form stored with the definition version (decimal strings,
     * known keys only). Call only after validate() returned no errors.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function normalize(array $parameters): array;

    /**
     * @param  array<string, mixed>  $parameters  normalized parameters
     */
    public function describe(array $parameters): string;
}
