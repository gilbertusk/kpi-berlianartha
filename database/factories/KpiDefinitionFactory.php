<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data only. Definitions created here bypass the lifecycle service;
 * use KpiDefinitionService in tests that exercise publishing rules.
 *
 * @extends Factory<KpiDefinition>
 */
final class KpiDefinitionFactory extends Factory
{
    protected $model = KpiDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST-'.strtoupper(fake()->unique()->bothify('??##??')),
            'origin' => KpiDefinition::ORIGIN_AUTHORED,
            'owner_unit_id' => fn (): int => (int) OrganizationUnit::query()->where('unit_type', 'team')->value('id'),
        ];
    }

    /**
     * A complete, publishable content set (higher is better, ratio to
     * target). Values are fictitious test parameters, not business decisions.
     *
     * @return array<string, mixed>
     */
    public static function completeContent(string $name = 'KPI uji', string $effectiveFrom = '2026-10-12'): array
    {
        return [
            'name' => $name,
            'purpose' => 'Tujuan uji',
            'description' => 'Deskripsi uji',
            'process_owner' => 'Pemilik proses uji',
            'data_owner' => 'Pemilik data uji',
            'evaluation_subject' => 'employee',
            'unit' => 'percent',
            'value_type' => 'percentage',
            'direction' => 'higher_is_better',
            'frequency' => 'monthly',
            'aggregation' => 'average',
            'formula_description' => 'Aktual dibagi target dikali 100',
            'numerator_definition' => 'Aktual',
            'denominator_definition' => 'Target',
            'score_strategy' => 'ratio_to_target',
            'score_strategy_version' => 1,
            'score_parameters' => [
                'missing_data_rule' => 'block_until_provided',
                'rounding_mode' => 'half_up',
                'rounding_scale' => 2,
                'score_floor' => '0',
                'score_cap' => '120',
                'zero_target_rule' => 'not_applicable',
            ],
            'data_source' => 'Laporan uji',
            'input_method' => 'manual',
            'evidence_requirement' => 'required',
            'evidence_description' => 'Lampiran laporan',
            'exception_policy' => 'Missing data ditahan; N/A dengan persetujuan HR',
            'target_rule' => 'Target bulanan ditetapkan TL',
            'approval_chain' => 'TL → Head → HR → Management',
            'signoff_reference' => 'TEST-SIGNOFF-001',
            'effective_from' => $effectiveFrom,
        ];
    }

    /**
     * Adds a complete draft v1. Database triggers only accept new versions
     * as drafts; publish through KpiDefinitionService.
     */
    public function withDraftVersion(string $effectiveFrom = '2026-10-12'): static
    {
        return $this->afterCreating(function (KpiDefinition $definition) use ($effectiveFrom): void {
            KpiDefinitionVersion::query()->create([
                ...self::completeContent('KPI '.$definition->code, $effectiveFrom),
                'kpi_definition_id' => $definition->id,
                'version_number' => 1,
                'status' => LifecycleStatus::Draft,
            ]);
        });
    }
}
