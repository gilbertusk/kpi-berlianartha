<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Application\ActualQuery;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Completeness of actuals per required target, in the `actual.view` scope.
 * A target is classified by the least advanced state among its entries,
 * exactly as the completeness summary of the actuals page does, so the
 * report and that summary reconcile.
 */
final class CompletenessReport implements ReportDefinition
{
    private const CHUNK = 500;

    private const STATES = ['missing' => 'Belum diisi', 'draft' => 'Draft', 'submitted' => 'Menunggu verifikasi', 'rejected' => 'Ditolak', 'verified' => 'Terverifikasi'];

    public function __construct(
        private readonly ReportSupport $support,
        private readonly ActualPeriodAccess $actualAccess,
        private readonly ActualQuery $actuals,
    ) {}

    public function key(): string
    {
        return 'completeness';
    }

    public function title(): string
    {
        return 'Kelengkapan dan data yang belum masuk';
    }

    public function description(): string
    {
        return 'Status aktual per target wajib: belum diisi, draft, menunggu verifikasi, ditolak, atau terverifikasi, dengan sumber dan keterlambatan.';
    }

    public function columns(): array
    {
        return ['Periode', 'Kode KPI', 'Nama KPI', 'Jenis subjek', 'Kode subjek', 'Nama subjek', 'Unit', 'Status', 'Jumlah entri', 'Entri terlambat', 'Sumber'];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'kpi', 'status', 'source'];
    }

    public function statuses(): array
    {
        return self::STATES;
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && ! $this->scope($user)->isEmpty();
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        $this->support->assertMayUseReports($user);
        $period = $this->support->period($filters);

        if (! $this->actualAccess->concerns($user, Capability::ActualView, $period)) {
            throw new AuthorizationException;
        }

        if ($filters->source !== null && ! in_array($filters->source, ['manual', 'import', 'correction'], true)) {
            throw new AuthorizationException;
        }

        $scope = $this->support->access->unitScope($user, Capability::ActualView);

        return new ReportContext($user, $filters, $period, $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    public function rows(ReportContext $context): iterable
    {
        $period = $context->period ?? throw new AuthorizationException;
        $filters = $context->filters;

        $targets = $this->actuals->base($period, $this->scope($context->user))
            ->where('is_required', true)
            ->when($context->unitIds !== null, fn ($query) => $query->whereIn('organization_unit_id', $context->unitIds ?? []))
            ->when($filters->kpi !== null, fn ($query) => $query->where('kpi_code', $filters->kpi))
            ->orderBy('id')
            ->lazyById(self::CHUNK);

        foreach ($targets->chunk(self::CHUNK) as $chunk) {
            $versions = KpiActualVersion::query()
                ->join('kpi_actuals', 'kpi_actuals.id', '=', 'kpi_actual_versions.kpi_actual_id')
                ->whereIn('kpi_actuals.period_target_id', array_values($chunk->map(static fn (PeriodTarget $target): int => $target->id)->all()))
                ->toBase()
                ->get(['kpi_actuals.period_target_id', 'kpi_actual_versions.status', 'kpi_actual_versions.is_late', 'kpi_actual_versions.source']);

            /** @var array<int, array{states: array<string, bool>, entries: int, late: int, sources: array<string, bool>}> $perTarget */
            $perTarget = [];

            foreach ($versions as $version) {
                $targetId = (int) $version->period_target_id;
                $entry = $perTarget[$targetId] ?? ['states' => [], 'entries' => 0, 'late' => 0, 'sources' => []];
                $entry['states'][(string) $version->status] = true;
                $entry['entries']++;
                $entry['late'] += (bool) $version->is_late ? 1 : 0;
                $entry['sources'][(string) $version->source] = true;
                $perTarget[$targetId] = $entry;
            }

            foreach ($chunk as $target) {
                $entry = $perTarget[$target->id] ?? ['states' => [], 'entries' => 0, 'late' => 0, 'sources' => []];
                $state = 'missing';

                foreach ([ActualStatus::Rejected, ActualStatus::Draft, ActualStatus::Submitted, ActualStatus::Verified] as $status) {
                    if (isset($entry['states'][$status->value])) {
                        $state = $status->value;

                        break;
                    }
                }

                if (($filters->status !== null && $filters->status !== $state) || ($filters->source !== null && ! isset($entry['sources'][$filters->source]))) {
                    continue;
                }

                $sources = array_keys($entry['sources']);
                sort($sources);

                yield [
                    $period->code, $target->kpi_code, $target->kpi_name, $target->subject_type->value, $target->subject_code, $target->subject_name,
                    $target->unit_code, self::STATES[$state], (string) $entry['entries'], (string) $entry['late'], implode(', ', $sources),
                ];
            }
        }
    }

    private function scope(User $user): ActualAccess
    {
        return $this->actualAccess->scope($user, Capability::ActualView);
    }
}
