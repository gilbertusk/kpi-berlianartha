<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * The reports the system offers, by key.
 */
final class ReportCatalog
{
    /** @var array<string, ReportDefinition> */
    private array $reports = [];

    /**
     * @param  iterable<ReportDefinition>  $reports
     */
    public function __construct(iterable $reports)
    {
        foreach ($reports as $report) {
            if (isset($this->reports[$report->key()])) {
                throw new InvalidArgumentException("Duplicate report [{$report->key()}].");
            }

            $this->reports[$report->key()] = $report;
        }
    }

    /**
     * @return list<ReportDefinition>
     */
    public function availableTo(User $user): array
    {
        return array_values(array_filter($this->reports, static fn (ReportDefinition $report): bool => $report->available($user)));
    }

    /**
     * An unknown report and a report the account may not use are refused
     * the same way.
     */
    public function get(User $user, string $key): ReportDefinition
    {
        $report = $this->reports[$key] ?? null;

        if ($report === null || ! $report->available($user)) {
            throw new AuthorizationException;
        }

        return $report;
    }

    /**
     * @return array{0: ReportDefinition, 1: ReportContext}
     */
    public function authorize(User $user, string $key, ReportFilters $filters): array
    {
        $report = $this->get($user, $key);
        $filters->assertOnly($report->filters());

        if ($filters->status !== null && ! array_key_exists($filters->status, $report->statuses())) {
            throw new BusinessRuleViolation('invalid_filter', 'Status tidak dikenal untuk laporan ini.');
        }

        return [$report, $report->authorize($user, $filters)];
    }
}
