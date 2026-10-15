<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Operations\Domain\RecordType;
use App\Modules\Organization\Application\OrganizationDirectory;

/**
 * Who may read and keep operational records.
 *
 * Reading needs `operational_record.view` through a grant whose role the
 * record type accepts (cost figures are not readable by every reader of
 * design revisions), over the record's unit. Keeping records needs
 * `operational_record.manage` over the unit.
 */
final readonly class OperationalAccess
{
    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
    ) {}

    public function readScope(User $user, RecordType $type): UnitScope
    {
        $grants = $this->access->grants($user);
        $snapshot = $this->directory->snapshot($grants->businessDate);
        $all = false;
        $unitIds = [];

        foreach ($grants->withCapability(Capability::OperationalRecordView) as $grant) {
            if (! in_array($grant->role->value, $type->readerRoles(), true)) {
                continue;
            }

            if ($grant->companyWide) {
                $all = true;
            } elseif ($grant->unitId !== null) {
                $unitIds = [...$unitIds, ...$snapshot->subtreeIds($grant->unitId)];
            }
        }

        return new UnitScope($all, array_values(array_unique($unitIds)), false);
    }

    public function manageScope(User $user): UnitScope
    {
        return $this->access->unitScope($user, Capability::OperationalRecordManage);
    }

    public function canManage(User $user, int $unitId): bool
    {
        return $this->access->allows($user, Capability::OperationalRecordManage, $this->access->unitTarget($unitId));
    }
}
