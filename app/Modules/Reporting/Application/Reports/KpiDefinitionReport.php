<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\PeriodAssignment\Application\PeriodVisibility;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The KPI definition versions a period is locked to (through its targets),
 * with the rules that decide a score. Readable with `kpi_definition.view`
 * in the `target.view` scope.
 */
final class KpiDefinitionReport implements ReportDefinition
{
    public function __construct(
        private readonly ReportSupport $support,
        private readonly PeriodVisibility $visibility,
    ) {}

    public function key(): string
    {
        return 'kpi_definitions';
    }

    public function title(): string
    {
        return 'Definisi dan versi KPI periode';
    }

    public function description(): string
    {
        return 'Versi definisi KPI yang dikunci periode: satuan, arah, agregasi, strategi skor dan parameternya, sumber data, dan sign-off.';
    }

    public function columns(): array
    {
        return [
            'Periode', 'Kode KPI', 'Versi', 'Nama', 'Status versi', 'Subjek penilaian', 'Satuan', 'Tipe nilai', 'Arah', 'Frekuensi', 'Agregasi',
            'Strategi skor', 'Parameter skor', 'Sumber data', 'Metode input', 'Kebutuhan bukti', 'Process owner', 'Data owner', 'Referensi sign-off',
            'Jumlah target pada periode',
        ];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'kpi'];
    }

    public function statuses(): array
    {
        return [];
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && $this->support->access->allows($user, Capability::KpiDefinitionView) && ! $this->scope($user)->isEmpty();
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if (! $this->available($user)) {
            throw new AuthorizationException;
        }

        $period = $this->support->period($filters);
        $scope = $this->scope($user);

        if (! $this->visibility->concerns($scope, $period, $this->support->access->businessToday())) {
            throw new AuthorizationException;
        }

        return new ReportContext($user, $filters, $period, $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    public function rows(ReportContext $context): iterable
    {
        $scope = $this->scope($context->user);

        $counts = PeriodTarget::query()
            ->where('assessment_period_id', $context->period?->id)
            ->when(! $scope->all, fn ($query) => $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds))
            ->when($context->unitIds !== null, fn ($query) => $query->whereIn('organization_unit_id', $context->unitIds ?? []))
            ->when($context->filters->kpi !== null, fn ($query) => $query->where('kpi_code', $context->filters->kpi))
            ->toBase()
            ->selectRaw('kpi_definition_version_id, COUNT(*) AS total')
            ->groupBy('kpi_definition_version_id')
            ->pluck('total', 'kpi_definition_version_id');

        $versions = KpiDefinitionVersion::query()
            ->with('definition')
            ->whereIn('id', $counts->keys()->all())
            ->orderBy('id')
            ->lazyById(200);

        foreach ($versions as $version) {
            yield [
                $context->period?->code, $version->definition->code, (string) $version->version_number, $version->name, $version->status->value,
                $version->evaluation_subject, $version->unit, $version->value_type, $version->direction, $version->frequency, $version->aggregation,
                $version->score_strategy === null ? null : "{$version->score_strategy}@{$version->score_strategy_version}",
                $version->score_parameters === null ? null : json_encode($version->score_parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $version->data_source, $version->input_method, $version->evidence_requirement, $version->process_owner, $version->data_owner,
                $version->signoff_reference, (string) $counts[$version->id],
            ];
        }
    }

    private function scope(User $user): UnitScope
    {
        return $this->support->access->unitScope($user, Capability::TargetView);
    }
}
