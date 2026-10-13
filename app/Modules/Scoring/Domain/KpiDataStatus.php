<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

/**
 * What the engine could conclude for one KPI line. Only Scored and
 * MissingScoredZero carry a score; the N/A statuses leave the line out of
 * the total under the signed N/A rule; every other status blocks the
 * scorecard. Missing data and N/A are never turned into zero silently:
 * MissingScoredZero happens only when the KPI's signed rule says so.
 */
enum KpiDataStatus: string
{
    case Scored = 'scored';
    case MissingScoredZero = 'missing_scored_zero';
    case NotApplicable = 'not_applicable';
    case MissingNotApplicable = 'missing_not_applicable';
    case RuleNotApplicable = 'rule_not_applicable';
    case MissingBlocked = 'missing_blocked';
    case PendingVerification = 'pending_verification';
    case TargetMissing = 'target_missing';
    case InvalidInput = 'invalid_input';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Scored => 'Dinilai',
            self::MissingScoredZero => 'Missing — skor nol (aturan KPI)',
            self::NotApplicable => 'N/A (dinyatakan pada aktual)',
            self::MissingNotApplicable => 'Missing — N/A (aturan KPI)',
            self::RuleNotApplicable => 'N/A (aturan nilai nol KPI)',
            self::MissingBlocked => 'Missing — ditahan sampai data tersedia',
            self::PendingVerification => 'Menunggu verifikasi aktual',
            self::TargetMissing => 'Target final belum ada',
            self::InvalidInput => 'Data tidak dapat dinilai',
            self::Unsupported => 'Aturan belum didukung',
        };
    }

    public function hasScore(): bool
    {
        return $this === self::Scored || $this === self::MissingScoredZero;
    }

    /**
     * Left out of the total; what happens to its weight is the signed
     * {@see ScorecardPolicy::$notApplicableWeightRule}.
     */
    public function isExcluded(): bool
    {
        return in_array($this, [self::NotApplicable, self::MissingNotApplicable, self::RuleNotApplicable], true);
    }

    public function blocks(): bool
    {
        return ! $this->hasScore() && ! $this->isExcluded();
    }

    /**
     * success, info, warning, danger, or neutral (status badge tone).
     */
    public function tone(): string
    {
        return match (true) {
            $this === self::Scored => 'success',
            $this === self::MissingScoredZero => 'warning',
            $this->isExcluded() => 'neutral',
            $this === self::PendingVerification => 'info',
            default => 'danger',
        };
    }
}
