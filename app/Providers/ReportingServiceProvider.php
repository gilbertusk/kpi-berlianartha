<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Reporting\Application\ExportService;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\Reports\ApprovalHistoryReport;
use App\Modules\Reporting\Application\Reports\ApprovalStatusReport;
use App\Modules\Reporting\Application\Reports\ArAgingReport;
use App\Modules\Reporting\Application\Reports\ArAgingSummaryReport;
use App\Modules\Reporting\Application\Reports\AuditLogReport;
use App\Modules\Reporting\Application\Reports\CalculationReport;
use App\Modules\Reporting\Application\Reports\CompletenessReport;
use App\Modules\Reporting\Application\Reports\CorrectionReport;
use App\Modules\Reporting\Application\Reports\CorrectiveActionReport;
use App\Modules\Reporting\Application\Reports\CostingComparisonReport;
use App\Modules\Reporting\Application\Reports\DesignRevisionAttributionReport;
use App\Modules\Reporting\Application\Reports\DesignRevisionReport;
use App\Modules\Reporting\Application\Reports\KpiDefinitionReport;
use App\Modules\Reporting\Application\Reports\KpiLineReport;
use App\Modules\Reporting\Application\Reports\PpicWorkOrderReport;
use App\Modules\Reporting\Application\Reports\ProductionActualsReport;
use App\Modules\Reporting\Application\Reports\QualityNcrReport;
use App\Modules\Reporting\Application\Reports\RatingDistributionReport;
use App\Modules\Reporting\Application\Reports\ScorecardReport;
use App\Modules\Reporting\Application\Reports\TaxInvoiceReport;
use App\Modules\Reporting\Application\Reports\WarehouseStockReport;
use App\Modules\Reporting\Infrastructure\Console\PurgeExpiredExportsCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    private const REPORTS = [
        ScorecardReport::class,
        KpiLineReport::class,
        RatingDistributionReport::class,
        CompletenessReport::class,
        ApprovalStatusReport::class,
        ApprovalHistoryReport::class,
        CorrectionReport::class,
        CorrectiveActionReport::class,
        KpiDefinitionReport::class,
        CalculationReport::class,
        AuditLogReport::class,
        DesignRevisionReport::class,
        DesignRevisionAttributionReport::class,
        CostingComparisonReport::class,
        ArAgingReport::class,
        ArAgingSummaryReport::class,
        TaxInvoiceReport::class,
        ProductionActualsReport::class,
        WarehouseStockReport::class,
        PpicWorkOrderReport::class,
        QualityNcrReport::class,
    ];

    public function register(): void
    {
        $this->app->tag(self::REPORTS, 'reporting.reports');
        $this->app->singleton(ReportCatalog::class, fn (Application $app): ReportCatalog => new ReportCatalog($app->tagged('reporting.reports')));

        $this->app->when(ExportService::class)->needs('$disk')->giveConfig('reports.disk');
        $this->app->when(ExportService::class)->needs('$linkTtlSeconds')->giveConfig('reports.link_ttl_seconds');
        $this->app->when(ExportService::class)->needs('$retentionHours')->giveConfig('reports.retention_hours');
        $this->app->when(ExportService::class)->needs('$maxRows')->giveConfig('reports.max_rows');
        $this->app->when(ExportService::class)->needs('$pdfMaxRows')->giveConfig('reports.pdf_max_rows');
        $this->app->when(ExportService::class)->needs('$maxInFlight')->giveConfig('reports.max_in_flight_per_user');
        $this->app->when(ExportService::class)->needs('$staleAfterMinutes')->giveConfig('reports.stale_after_minutes');
    }

    public function boot(): void
    {
        Gate::define('reports.view', fn (User $user): bool => $this->app->make(ReportCatalog::class)->availableTo($user) !== []);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredExportsCommand::class]);
        }
    }
}
