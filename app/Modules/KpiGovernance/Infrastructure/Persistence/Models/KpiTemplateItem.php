<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Persistence\Models;

use App\Modules\KpiGovernance\Domain\ItemApplicability;
use App\Modules\KpiGovernance\Domain\Weight;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One KPI in a template version, pinned to a specific KPI definition version
 * so a published scorecard always reproduces the same definitions.
 *
 * @property int $id
 * @property string $public_id
 * @property int $kpi_template_version_id
 * @property int $kpi_definition_version_id
 * @property string|null $component
 * @property string $weight decimal string, 4 decimals
 * @property int $sort_order
 * @property bool $is_mandatory
 * @property array<string, mixed>|null $applicability
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read KpiTemplateVersion $templateVersion
 * @property-read KpiDefinitionVersion $definitionVersion
 */
final class KpiTemplateItem extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function weightValue(): Weight
    {
        return Weight::fromString($this->weight);
    }

    public function applicabilityRule(): ?ItemApplicability
    {
        return ItemApplicability::fromArray($this->applicability);
    }

    /**
     * @return BelongsTo<KpiTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(KpiTemplateVersion::class, 'kpi_template_version_id');
    }

    /**
     * @return BelongsTo<KpiDefinitionVersion, $this>
     */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(KpiDefinitionVersion::class, 'kpi_definition_version_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'sort_order' => 'integer',
            'applicability' => 'array',
            'weight' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
