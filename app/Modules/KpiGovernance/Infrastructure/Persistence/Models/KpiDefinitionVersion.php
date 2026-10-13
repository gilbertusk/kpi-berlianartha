<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\KpiGovernance\Domain\DefinitionContent;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Concerns\HasGovernanceLifecycle;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $kpi_definition_id
 * @property int $version_number
 * @property LifecycleStatus $status
 * @property string $name
 * @property string|null $purpose
 * @property string|null $description
 * @property string|null $process_owner
 * @property string|null $data_owner
 * @property string|null $evaluation_subject
 * @property string|null $unit
 * @property string|null $value_type
 * @property string|null $direction
 * @property string|null $frequency
 * @property string|null $aggregation
 * @property int|null $decimal_places
 * @property string|null $min_valid_value canonical decimal string
 * @property string|null $max_valid_value canonical decimal string
 * @property string|null $formula_description
 * @property string|null $numerator_definition
 * @property string|null $denominator_definition
 * @property string|null $score_strategy
 * @property int|null $score_strategy_version
 * @property array<string, mixed>|null $score_parameters
 * @property string|null $data_source
 * @property string|null $input_method
 * @property string|null $evidence_requirement
 * @property string|null $evidence_description
 * @property string|null $exception_policy
 * @property string|null $target_rule
 * @property string|null $approval_chain
 * @property string|null $signoff_reference
 * @property string|null $effective_from
 * @property string|null $effective_until
 * @property string|null $change_reason
 * @property int|null $previous_version_id
 * @property int|null $created_by_user_id
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $returned_by_user_id
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_reason
 * @property int|null $published_by_user_id
 * @property CarbonImmutable|null $published_at
 * @property int|null $retired_by_user_id
 * @property CarbonImmutable|null $retired_at
 * @property string|null $retire_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read KpiDefinition $definition
 * @property-read User|null $createdBy
 * @property-read User|null $submittedBy
 * @property-read User|null $publishedBy
 * @property-read User|null $returnedBy
 * @property-read User|null $retiredBy
 */
final class KpiDefinitionVersion extends Model
{
    use HasGovernanceLifecycle, HasPublicUlid;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    public function content(): DefinitionContent
    {
        return DefinitionContent::fromArray($this->only(array_keys(DefinitionContent::FIELDS)));
    }

    /**
     * @return BelongsTo<KpiDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->lifecycleCasts(),
            'score_parameters' => 'array',
            'score_strategy_version' => 'integer',
            'decimal_places' => 'integer',
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
        ];
    }
}
