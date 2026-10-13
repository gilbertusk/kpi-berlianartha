<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Application\PeriodSetup;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Target\Application\TargetEntry;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;

/**
 * Periods, templates, and targets built through the real services. The
 * default period is November 2026: it starts after {@see AccessFixtures::TODAY},
 * so KPI and template versions published "today" are in force on its
 * reference date.
 */
final class PeriodFixtures
{
    public const STARTS_ON = '2026-11-01';

    public const ENDS_ON = '2026-11-30';

    public function __construct(public KpiFixtures $kpi) {}

    public static function everything(): UnitScope
    {
        return new UnitScope(true, [], false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function setup(array $overrides = []): PeriodSetup
    {
        $values = [
            'code' => 'P-2026-11',
            'name' => 'November 2026',
            'type' => 'monthly',
            'startsOn' => self::STARTS_ON,
            'endsOn' => self::ENDS_ON,
            'unit' => 'BAL',
            'referenceDate' => null,
            'notes' => null,
            'deadlines' => [],
            ...$overrides,
        ];

        return new PeriodSetup(
            code: $values['code'],
            name: $values['name'],
            type: $values['type'],
            startsOn: $values['startsOn'],
            endsOn: $values['endsOn'],
            unitId: AccessFixtures::unit($values['unit'])->id,
            referenceDate: $values['referenceDate'],
            notes: $values['notes'],
            deadlines: $values['deadlines'],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function draft(array $overrides = []): AssessmentPeriod
    {
        return app(PeriodService::class)->createDraft($this->kpi->authorActor(), self::setup($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function opened(array $overrides = []): AssessmentPeriod
    {
        $period = $this->draft($overrides);

        return app(PeriodService::class)->open($this->kpi->authorActor(), $period->id, $period->lock_version);
    }

    /**
     * Publishes a template with the given KPI versions (weights must total 100).
     *
     * @param  array<int, string>  $weights  definition version id => weight
     * @param  array<int, list<string>>  $positionRules  definition version id => positions the item applies to
     */
    public function publishedTemplate(string $code, string $unitCode, array $weights, string $subject = 'employee', ?string $position = null, array $positionRules = [], string $effectiveFrom = AccessFixtures::TODAY): KpiTemplateVersion
    {
        $service = app(KpiTemplateService::class);
        $actor = $this->kpi->authorActor();

        $version = $service->createTemplate($actor, $code, "Template {$code}", AccessFixtures::unit($unitCode)->id, $subject, $position, null);
        $version = $service->updateDraftHeader($actor, $version->id, $version->lock_version, null, 'TEST-SIGNOFF-T1', $effectiveFrom);

        foreach ($weights as $definitionVersionId => $weight) {
            $rule = isset($positionRules[$definitionVersionId]) ? ['positions' => $positionRules[$definitionVersionId]] : null;
            $service->addItem($actor, $version->id, $version->fresh()?->lock_version ?? 1, $definitionVersionId, $weight, null, true, $rule);
        }

        return $this->kpi->publishTemplate($version->fresh() ?? $version);
    }

    /**
     * @param  array<string, mixed>  $overrides  KPI definition content overrides
     */
    public function kpi(string $code, string $unitCode = 'PROD-WOVEN', array $overrides = []): KpiDefinitionVersion
    {
        return $this->kpi->publishedDefinition($code, $unitCode, $overrides);
    }

    public function target(AssessmentPeriod $period, string $kpiCode, string $subjectCode): PeriodTarget
    {
        return PeriodTarget::query()
            ->where('assessment_period_id', $period->id)
            ->where('kpi_code', $kpiCode)
            ->where('subject_code', $subjectCode)
            ->firstOrFail();
    }

    public function entry(PeriodTarget $target, string $value, ?string $unit = null, ?int $expectedLock = null): TargetEntry
    {
        return new TargetEntry($target->public_id, $target->id, $value, $unit ?? (string) $target->definitionVersion->unit, null, $expectedLock);
    }

    /**
     * Saves, submits (author), and approves (reviewer) one target value.
     */
    public function approvedTarget(AssessmentPeriod $period, PeriodTarget $target, string $value, ?string $reason = null, bool $revision = false): void
    {
        $service = app(TargetService::class);

        $service->saveDrafts($this->kpi->authorActor(), $period->id, self::everything(), [$this->entry($target, $value)], $reason, $revision);
        $service->submit($this->kpi->authorActor(), $period->id, self::everything(), [$target->id]);
        $service->approve($this->kpi->reviewerActor(), $period->id, self::everything(), [$target->id]);
    }

    public function systemActor(): Actor
    {
        return Actor::system('test');
    }
}
