<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Infrastructure;

use App\Modules\Scoring\Application\ScorecardPolicyCatalog;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Approved policies from `config/scoring.php`, which is reviewed in git like
 * code. Each entry must be a complete, signed policy; versions are append
 * only and their effective periods may not overlap. Any invalid entry stops
 * scoring altogether instead of being skipped.
 */
final class ConfiguredScorecardPolicies implements ScorecardPolicyCatalog
{
    public function all(): array
    {
        $entries = Config::array('scoring.policies', []);

        if (! array_is_list($entries)) {
            throw new InvalidArgumentException('scoring.policies harus berupa daftar policy.');
        }

        $policies = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('scoring.policies['.$index.'] harus berupa array.');
            }

            $policy = ScorecardPolicy::fromArray($entry);

            foreach ($policies as $other) {
                if ($other->reference() === $policy->reference()) {
                    throw new InvalidArgumentException("Policy {$policy->reference()} terdaftar lebih dari sekali.");
                }

                if ($other->overlaps($policy)) {
                    throw new InvalidArgumentException("Masa berlaku policy {$policy->reference()} tumpang tindih dengan {$other->reference()}.");
                }
            }

            $policies[] = $policy;
        }

        return $policies;
    }

    public function inForceOn(string $businessDate): ?ScorecardPolicy
    {
        foreach ($this->all() as $policy) {
            if ($policy->isInForceOn($businessDate)) {
                return $policy;
            }
        }

        return null;
    }
}
