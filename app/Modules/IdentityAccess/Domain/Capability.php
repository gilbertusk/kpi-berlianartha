<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain;

/**
 * Server-side capabilities checked by policies. A role grants a capability
 * ceiling; the assignment scope and later workflow rules narrow it further.
 */
enum Capability: string
{
    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';
    case EmployeeView = 'employee.view';
    case EmployeeManage = 'employee.manage';
    case AccountView = 'account.view';
    case AccountManage = 'account.manage';
    case RoleView = 'role.view';
    case RoleManage = 'role.manage';
    case DelegationView = 'delegation.view';
    case DelegationManage = 'delegation.manage';
    case AccessReviewView = 'access_review.view';

    /**
     * Reserved for the KPI modules (Step 06–10). Step 05 only fixes the role
     * ceiling from ROLE_APPROVAL_MATRIX.md; ownership, workflow state, and
     * "C" conditions are enforced by the owning module's policies.
     */
    case KpiValueModify = 'kpi.value.modify';

    case ApprovalDecide = 'approval.decide';

    public function isReadOnly(): bool
    {
        return str_ends_with($this->value, '.view');
    }

    /**
     * Target, actual, score, calibration, and approval changes. System Admin
     * and Auditor may never hold these (PRD §14 segregation of duties).
     */
    public function changesKpiValues(): bool
    {
        return $this === self::KpiValueModify || $this === self::ApprovalDecide;
    }

    /**
     * Delegation transfers read access and approval authority only; master
     * data, account, role, and value-entry duties stay with the holder.
     */
    public function isDelegable(): bool
    {
        return $this->isReadOnly() || $this === self::ApprovalDecide;
    }

    /**
     * The actor may not exercise this capability on their own record, even
     * with a company-wide or delegated grant (no self-approval).
     */
    public function requiresIndependence(): bool
    {
        return $this === self::ApprovalDecide;
    }
}
