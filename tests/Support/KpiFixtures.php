<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\Shared\Application\Audit\Actor;
use Database\Factories\KpiDefinitionFactory;

/**
 * KPI definitions and templates built through the real lifecycle: one HR
 * account authors and submits, a second HR account reviews and publishes.
 */
final class KpiFixtures
{
    public User $author;

    public User $reviewer;

    public function __construct()
    {
        $this->author = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
        $this->reviewer = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    }

    public function authorActor(): Actor
    {
        return new Actor($this->author->id, Actor::system('test')->audit);
    }

    public function reviewerActor(): Actor
    {
        return new Actor($this->reviewer->id, Actor::system('test')->audit);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function draftDefinition(string $code, string $unitCode = 'PROD-WOVEN', array $overrides = []): KpiDefinitionVersion
    {
        return app(KpiDefinitionService::class)->createDraft(
            $this->authorActor(),
            $code,
            AccessFixtures::unit($unitCode)->id,
            [...KpiDefinitionFactory::completeContent("KPI {$code}", AccessFixtures::TODAY), ...$overrides],
        );
    }

    public function submitAndPublish(KpiDefinitionVersion $version): KpiDefinitionVersion
    {
        $service = app(KpiDefinitionService::class);
        $submitted = $service->submitForReview($this->authorActor(), $version->id, $version->fresh()?->lock_version ?? 1);

        return $service->publish($this->reviewerActor(), $submitted->id, $submitted->lock_version);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function publishedDefinition(string $code, string $unitCode = 'PROD-WOVEN', array $overrides = []): KpiDefinitionVersion
    {
        return $this->submitAndPublish($this->draftDefinition($code, $unitCode, $overrides));
    }

    /**
     * @param  array<string, string>  $weights  definition version id => weight
     */
    public function draftTemplate(string $code, array $weights, string $unitCode = 'PROD-WOVEN', string $effectiveFrom = AccessFixtures::TODAY, ?string $position = null): KpiTemplateVersion
    {
        $service = app(KpiTemplateService::class);
        $version = $service->createTemplate($this->authorActor(), $code, "Template {$code}", AccessFixtures::unit($unitCode)->id, 'employee', $position, null);
        $version = $service->updateDraftHeader($this->authorActor(), $version->id, $version->lock_version, null, 'TEST-SIGNOFF-T1', $effectiveFrom);

        foreach ($weights as $definitionVersionId => $weight) {
            $service->addItem($this->authorActor(), $version->id, $version->fresh()?->lock_version ?? 1, (int) $definitionVersionId, $weight, null, true, null);
        }

        return $version->fresh() ?? $version;
    }

    public function publishTemplate(KpiTemplateVersion $version): KpiTemplateVersion
    {
        $service = app(KpiTemplateService::class);
        $submitted = $service->submitForReview($this->authorActor(), $version->id, $version->fresh()?->lock_version ?? 1);

        return $service->publish($this->reviewerActor(), $submitted->id, $submitted->lock_version);
    }
}
