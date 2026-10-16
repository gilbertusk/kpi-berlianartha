<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationException;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The integration run dashboard: per connection its status, why it cannot
 * run, last run and last success, watermark and freshness, counts,
 * duplicates, rejects, retries, dead letters, open exceptions, and the last
 * reconciliation difference.
 */
final readonly class IntegrationQuery
{
    public const SUCCESS = [IntegrationRun::STAGED, IntegrationRun::COMMITTED, IntegrationRun::DUPLICATE, IntegrationRun::DRY_RUN, IntegrationRun::APPLIED];

    public function __construct(private ConnectionRegistry $connections) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $rows = [];

        foreach ($this->connections->all() as $key => $connection) {
            $runs = IntegrationRun::query()->where('connection', $key);
            $success = (clone $runs)->whereIn('status', self::SUCCESS);
            $lastSuccess = (clone $success)->orderByDesc('id')->first();
            $watermark = (clone $success)->max('watermark');
            $watermark = $watermark === null ? null : CarbonImmutable::parse((string) $watermark, 'UTC');

            $rows[] = [
                'connection' => $connection,
                'blocked_because' => $connection->isPipeline() ? $connection->blockedBecause() : null,
                'last_run' => (clone $runs)->orderByDesc('id')->first(),
                'last_success' => $lastSuccess,
                'watermark' => $watermark,
                'freshness_hours' => $watermark === null ? null : (int) floor($watermark->diffInHours(CarbonImmutable::now(), true)),
                'runs' => (clone $runs)->count(),
                'rows' => (int) (clone $runs)->sum('row_count'),
                'accepted' => (int) (clone $runs)->sum('accepted_count'),
                'rejected' => (int) (clone $runs)->sum('rejected_count'),
                'duplicates' => (int) (clone $runs)->sum('duplicate_count'),
                'retries' => (int) (clone $runs)->where('attempts', '>', 1)->sum('attempts') - (clone $runs)->where('attempts', '>', 1)->count(),
                'dead_letters' => (clone $runs)->where('status', IntegrationRun::DEAD_LETTER)->count(),
                'open_exceptions' => IntegrationException::query()->where('connection', $key)->where('status', IntegrationException::OPEN)->count(),
                'reconciled' => $lastSuccess?->reconciled,
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, IntegrationRun>
     */
    public function recentRuns(int $limit = 50): Collection
    {
        return IntegrationRun::query()->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * @return Collection<int, IntegrationException>
     */
    public function exceptions(bool $openOnly = true, int $limit = 100): Collection
    {
        return IntegrationException::query()->with('resolvedBy')
            ->when($openOnly, fn ($query) => $query->where('status', IntegrationException::OPEN))
            ->orderByDesc('id')->limit($limit)->get();
    }
}
