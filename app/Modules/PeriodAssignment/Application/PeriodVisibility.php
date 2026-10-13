<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;

/**
 * A period concerns a viewer when their scope and the period scope share at
 * least one unit: HR for a department sees the company period, and the other
 * way round. What they see inside it is still filtered by their own scope.
 */
final readonly class PeriodVisibility
{
    public function __construct(private OrganizationDirectory $directory) {}

    public function concerns(UnitScope $scope, AssessmentPeriod $period, string $businessToday): bool
    {
        if ($scope->all) {
            return true;
        }

        if ($scope->unitIds === []) {
            return false;
        }

        if ($scope->coversUnit($period->organization_unit_id)) {
            return true;
        }

        $periodUnits = $this->directory->snapshot($businessToday)->subtreeIds($period->organization_unit_id);

        return array_intersect($scope->unitIds, $periodUnits) !== [];
    }
}
