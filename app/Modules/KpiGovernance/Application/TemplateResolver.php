<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;

/**
 * Public read contract for later steps (period assignment, Step 07): which
 * published template version applies to a scope on a business date. Two
 * periods on different dates can therefore use different versions while
 * both versions stay unchanged.
 */
final class TemplateResolver
{
    public function versionOn(KpiTemplate $template, string $businessDate): ?KpiTemplateVersion
    {
        return KpiTemplateVersion::query()
            ->where('kpi_template_id', $template->id)
            ->effectiveOn($businessDate)
            ->first();
    }

    public function forScope(int $unitId, string $subject, ?string $positionTitle, string $businessDate): ?KpiTemplateVersion
    {
        $template = KpiTemplate::query()
            ->where('organization_unit_id', $unitId)
            ->where('subject', $subject)
            ->where('position_key', $positionTitle === null ? '' : mb_strtolower(trim($positionTitle)))
            ->first();

        return $template === null ? null : $this->versionOn($template, $businessDate);
    }
}
