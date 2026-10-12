<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scoped list queries for the KPI dictionary and templates. Filtering by the
 * viewer's unit scope happens in SQL so pagination never leaks rows.
 */
final class KpiCatalogQuery
{
    private const PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, KpiDefinition>
     */
    public function definitions(UnitScope $scope, string $search, ?string $status, ?string $origin, ?int $unitId, int $page): LengthAwarePaginator
    {
        $latest = fn (string $column) => KpiDefinitionVersion::query()
            ->select($column)
            ->whereColumn('kpi_definition_versions.kpi_definition_id', 'kpi_definitions.id')
            ->orderByDesc('version_number')
            ->limit(1);

        $query = KpiDefinition::query()
            ->select('kpi_definitions.*')
            ->addSelect([
                'latest_name' => $latest('name'),
                'latest_status' => $latest('status'),
                'latest_version_number' => $latest('version_number'),
            ])
            ->with('ownerUnit');

        $this->applyScope($query, $scope, 'owner_unit_id');

        if ($status !== null) {
            $query->whereHas('versions', fn (Builder $versions) => $versions
                ->where('status', $status)
                ->whereRaw('version_number = (select max(v2.version_number) from kpi_definition_versions as v2 where v2.kpi_definition_id = kpi_definition_versions.kpi_definition_id)'));
        }

        if ($origin !== null) {
            $query->where('origin', $origin);
        }

        if ($unitId !== null) {
            $query->where('owner_unit_id', $unitId);
        }

        $needle = trim($search);

        if ($needle !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(code) like ?', [$pattern])
                ->orWhereHas('versions', fn (Builder $versions) => $versions->whereRaw('lower(name) like ?', [$pattern])));
        }

        return $query->orderBy('code')->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * @return LengthAwarePaginator<int, KpiTemplate>
     */
    public function templates(UnitScope $scope, string $search, ?string $subject, int $page): LengthAwarePaginator
    {
        $latest = fn (string $column) => KpiTemplateVersion::query()
            ->select($column)
            ->whereColumn('kpi_template_versions.kpi_template_id', 'kpi_templates.id')
            ->orderByDesc('version_number')
            ->limit(1);

        $query = KpiTemplate::query()
            ->select('kpi_templates.*')
            ->addSelect([
                'latest_status' => $latest('status'),
                'latest_version_number' => $latest('version_number'),
            ])
            ->with('unit');

        $this->applyScope($query, $scope, 'organization_unit_id');

        if ($subject !== null) {
            $query->where('subject', $subject);
        }

        $needle = trim($search);

        if ($needle !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';
            $query->where(fn (Builder $match) => $match->whereRaw('lower(code) like ?', [$pattern])->orWhereRaw('lower(name) like ?', [$pattern]));
        }

        return $query->orderBy('code')->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * Version counts by status inside the scope, split by origin, so the
     * difference between candidates and approved KPIs stays visible.
     *
     * @return array<string, array<string, int>>
     */
    public function definitionVersionCounts(UnitScope $scope): array
    {
        $query = KpiDefinitionVersion::query()
            ->join('kpi_definitions', 'kpi_definitions.id', '=', 'kpi_definition_versions.kpi_definition_id')
            ->selectRaw('kpi_definitions.origin as origin, kpi_definition_versions.status as status, count(*) as total')
            ->groupBy('kpi_definitions.origin', 'kpi_definition_versions.status');

        if (! $scope->all) {
            $query->whereIn('kpi_definitions.owner_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }

        $counts = [];

        foreach ([KpiDefinition::ORIGIN_AUTHORED, KpiDefinition::ORIGIN_PRD_CANDIDATE] as $origin) {
            $counts[$origin] = array_fill_keys(array_map(fn (LifecycleStatus $status): string => $status->value, LifecycleStatus::cases()), 0);
        }

        foreach ($query->toBase()->get() as $row) {
            $counts[(string) $row->origin][(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Template versions that pin a KPI definition version, newest first.
     *
     * @return list<array{template: string, code: string, version: int, status: string, effective_from: string|null, effective_until: string|null}>
     */
    public function templatesPinning(UnitScope $scope, int $definitionVersionId): array
    {
        $query = KpiTemplateVersion::query()
            ->with('template')
            ->whereHas('items', fn (Builder $items) => $items->where('kpi_definition_version_id', $definitionVersionId))
            ->whereHas('template', function (Builder $templates) use ($scope): void {
                $this->applyScope($templates, $scope, 'organization_unit_id');
            })
            ->orderByDesc('id');

        return array_values($query
            ->get()
            ->map(fn (KpiTemplateVersion $version): array => [
                'template' => $version->template->public_id,
                'code' => $version->template->code,
                'version' => $version->version_number,
                'status' => $version->status->value,
                'effective_from' => $version->effective_from,
                'effective_until' => $version->effective_until,
            ])
            ->all());
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function auditTrail(string $entityType, string $publicId, int $page): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $publicId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'auditPage', $page);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyScope(Builder $query, UnitScope $scope, string $column): void
    {
        if (! $scope->all) {
            $query->whereIn($column, $scope->unitIds === [] ? [0] : $scope->unitIds);
        }
    }
}
