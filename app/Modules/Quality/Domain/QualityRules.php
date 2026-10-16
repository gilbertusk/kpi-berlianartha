<?php

declare(strict_types=1);

namespace App\Modules\Quality\Domain;

use InvalidArgumentException;

/**
 * The quality process settings the business approves. Nothing is assumed:
 * without an approved severity scale an NCR carries no severity, without a
 * defect or root-cause taxonomy codes are taken as the source gives them,
 * preventive action and effectiveness review are off until required, and
 * evidence is needed to close an NCR and wherever else the setting asks.
 */
final readonly class QualityRules
{
    /**
     * @param  list<string>  $severities
     * @param  list<string>  $defectCategories
     * @param  list<string>  $rootCauseMethods
     * @param  list<NcrStage>  $evidenceRequiredStages
     */
    private function __construct(
        public array $severities,
        public array $defectCategories,
        public array $rootCauseMethods,
        public bool $preventiveActionEnabled,
        public bool $effectivenessReviewRequired,
        public array $evidenceRequiredStages,
    ) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $stages = [];

        foreach (self::list($config, 'evidence_required_stages') as $value) {
            $stage = NcrStage::tryFrom($value);

            if ($stage === null || $stage === NcrStage::Closed) {
                throw new InvalidArgumentException("Tahap {$value} pada evidence_required_stages tidak dikenal.");
            }

            $stages[] = $stage;
        }

        // Closing an NCR always needs the verification evidence (PRD §10.3).
        if (! in_array(NcrStage::Verification, $stages, true)) {
            $stages[] = NcrStage::Verification;
        }

        return new self(
            self::codes($config, 'severities'),
            self::codes($config, 'defect_categories'),
            self::codes($config, 'root_cause_methods'),
            self::flag($config, 'preventive_action_enabled'),
            self::flag($config, 'effectiveness_review_required'),
            $stages,
        );
    }

    public function needsEvidence(NcrStage $stage): bool
    {
        return in_array($stage, $this->evidenceRequiredStages, true);
    }

    /**
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private static function codes(array $config, string $key): array
    {
        $codes = self::list($config, $key);

        foreach ($codes as $code) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $code) !== 1) {
                throw new InvalidArgumentException("Kode {$code} pada {$key} tidak valid.");
            }
        }

        if (count(array_unique($codes)) !== count($codes)) {
            throw new InvalidArgumentException("Kode pada {$key} harus unik.");
        }

        return $codes;
    }

    /**
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private static function list(array $config, string $key): array
    {
        $values = $config[$key] ?? [];

        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("Pengaturan {$key} harus berupa daftar.");
        }

        return array_map(static function (mixed $value) use ($key): string {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Pengaturan {$key} hanya berisi teks.");
            }

            return $value;
        }, $values);
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function flag(array $config, string $key): bool
    {
        $value = $config[$key] ?? false;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Pengaturan {$key} harus true atau false.");
        }

        return $value;
    }
}
