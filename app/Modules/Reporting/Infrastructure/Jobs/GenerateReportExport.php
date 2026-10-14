<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Jobs;

use App\Modules\Reporting\Application\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Generates one report export outside the web request. Idempotent: an
 * export that is not queued any more is left alone, and two copies of the
 * job never work on the same export at once.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Below the queue connection's retry_after (90 s), so a run that is
     * still working is never handed to a second worker.
     */
    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $exportId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("report-export:{$this->exportId}"))->expireAfter(120)->dontRelease()];
    }

    public function handle(ExportService $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ExportService::class)->giveUp($this->exportId);
    }
}
