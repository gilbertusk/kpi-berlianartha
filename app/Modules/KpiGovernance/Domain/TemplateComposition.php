<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain;

/**
 * Publish rules for the items of one template version. PRD §7.4: the total
 * weight of a scorecard must be exactly 100% before publishing. Changing that
 * rule requires a PRD revision and a formal business decision.
 */
final class TemplateComposition
{
    /**
     * @param  list<array{definition_id: int, weight_units: int}>  $items
     * @return list<string>
     */
    public static function publishErrors(array $items): array
    {
        if ($items === []) {
            return ['Template wajib memiliki minimal satu KPI.'];
        }

        $errors = [];
        $definitionIds = array_column($items, 'definition_id');

        if (count($definitionIds) !== count(array_unique($definitionIds))) {
            $errors[] = 'Satu KPI hanya boleh muncul sekali dalam satu template.';
        }

        $total = array_sum(array_column($items, 'weight_units'));

        if ($total !== Weight::TOTAL_REQUIRED_UNITS) {
            $errors[] = 'Total bobot harus tepat 100%. Total saat ini '.Weight::formatUnits($total).'%.';
        }

        return $errors;
    }
}
