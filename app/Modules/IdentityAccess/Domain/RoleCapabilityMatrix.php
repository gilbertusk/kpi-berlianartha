<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain;

use LogicException;

/**
 * Capability ceiling per role, derived from ROLE_APPROVAL_MATRIX.md and PRD §5.
 * The constructor enforces the segregation-of-duties invariants so a future
 * edit cannot silently give System Admin or Auditor write access to KPI values.
 */
final class RoleCapabilityMatrix
{
    /** @var array<string, list<Capability>> */
    private array $grants;

    public function __construct()
    {
        $this->grants = [
            Role::Management->value => [
                Capability::OrganizationView,
                Capability::EmployeeView,
                Capability::RoleView,
                Capability::DelegationView,
                Capability::AccessReviewView,
                Capability::KpiDefinitionView,
                Capability::KpiTemplateView,
                Capability::PeriodView,
                Capability::TargetView,
                Capability::ActualView,
                Capability::ScorecardView,
                Capability::ApprovalDecide,
                Capability::CorrectionApprove,
                Capability::TeamReviewView,
                Capability::ReportView,
                Capability::OperationalRecordView,
                Capability::AccountingDataView,
                Capability::ProductionDataView,
            ],
            Role::DepartmentHead->value => [
                Capability::OrganizationView,
                Capability::EmployeeView,
                Capability::KpiDefinitionView,
                Capability::KpiTemplateView,
                Capability::PeriodView,
                Capability::TargetView,
                Capability::ActualView,
                Capability::ScorecardView,
                Capability::KpiValueModify,
                Capability::ApprovalDecide,
                Capability::TeamReviewView,
                Capability::TeamReviewManage,
                Capability::ReportView,
                Capability::OperationalRecordView,
                Capability::OperationalRecordManage,
                Capability::AccountingDataView,
                Capability::AccountingDataManage,
                Capability::ProductionDataView,
                Capability::ProductionDataManage,
                Capability::PpicBoardManage,
            ],
            Role::TeamLeader->value => [
                Capability::OrganizationView,
                Capability::EmployeeView,
                Capability::KpiDefinitionView,
                Capability::KpiTemplateView,
                Capability::PeriodView,
                Capability::TargetView,
                Capability::ActualView,
                Capability::ActualVerify,
                Capability::ScorecardView,
                Capability::KpiValueModify,
                Capability::ApprovalDecide,
                Capability::TeamReviewView,
                Capability::TeamReviewManage,
                Capability::CoachingNoteManage,
                Capability::ReportView,
                Capability::OperationalRecordView,
                Capability::OperationalRecordManage,
                Capability::AccountingDataView,
                Capability::AccountingDataManage,
                Capability::ProductionDataView,
                Capability::ProductionDataManage,
                Capability::PpicBoardManage,
            ],
            Role::Employee->value => [
                Capability::EmployeeView,
                Capability::ActualView,
                Capability::ActualEnter,
                Capability::ScorecardView,
                Capability::KpiValueModify,
            ],
            Role::HrPerformance->value => [
                Capability::OrganizationView,
                Capability::OrganizationManage,
                Capability::EmployeeView,
                Capability::EmployeeManage,
                Capability::RoleView,
                Capability::DelegationView,
                Capability::DelegationManage,
                Capability::AccessReviewView,
                Capability::KpiDefinitionView,
                Capability::KpiDefinitionManage,
                Capability::KpiDefinitionPublish,
                Capability::KpiTemplateView,
                Capability::KpiTemplateManage,
                Capability::KpiTemplatePublish,
                Capability::PeriodView,
                Capability::PeriodManage,
                Capability::TargetView,
                Capability::TargetManage,
                Capability::TargetApprove,
                Capability::ActualView,
                Capability::ActualEnter,
                Capability::ScorecardView,
                Capability::IndividualScorecardView,
                Capability::ScorecardCalculate,
                Capability::KpiValueModify,
                Capability::ApprovalDecide,
                Capability::ApprovalRouteManage,
                Capability::CorrectionRequest,
                Capability::TeamReviewView,
                Capability::ReportView,
                Capability::AuditReportView,
                Capability::OperationalRecordView,
                Capability::ProductionDataView,
            ],
            Role::Auditor->value => [
                Capability::OrganizationView,
                Capability::EmployeeView,
                Capability::AccountView,
                Capability::RoleView,
                Capability::DelegationView,
                Capability::AccessReviewView,
                Capability::KpiDefinitionView,
                Capability::KpiTemplateView,
                Capability::PeriodView,
                Capability::TargetView,
                Capability::ActualView,
                Capability::ScorecardView,
                Capability::IndividualScorecardView,
                Capability::TeamReviewView,
                Capability::ReportView,
                Capability::AuditReportView,
                Capability::OperationalRecordView,
                Capability::AccountingDataView,
                Capability::ProductionDataView,
            ],
            Role::SystemAdmin->value => [
                Capability::OrganizationView,
                Capability::AccountView,
                Capability::AccountManage,
                Capability::RoleView,
                Capability::RoleManage,
                Capability::DelegationView,
                Capability::AccessReviewView,
            ],
        ];

        $this->assertInvariants();
    }

    /**
     * @return list<Capability>
     */
    public function capabilitiesFor(Role $role): array
    {
        return $this->grants[$role->value];
    }

    /**
     * @return list<Capability>
     */
    public function delegableCapabilitiesFor(Role $role): array
    {
        if (! $role->isDelegable()) {
            return [];
        }

        return array_values(array_filter(
            $this->capabilitiesFor($role),
            static fn (Capability $capability): bool => $capability->isDelegable(),
        ));
    }

    public function allows(Role $role, Capability $capability): bool
    {
        return in_array($capability, $this->capabilitiesFor($role), true);
    }

    private function assertInvariants(): void
    {
        foreach (Role::cases() as $role) {
            if (! array_key_exists($role->value, $this->grants)) {
                throw new LogicException("Role [{$role->value}] has no capability row.");
            }
        }

        foreach ($this->capabilitiesFor(Role::Auditor) as $capability) {
            if (! $capability->isReadOnly()) {
                throw new LogicException("Auditor must stay read-only; [{$capability->value}] is not.");
            }
        }

        foreach ([Role::SystemAdmin, Role::Auditor] as $role) {
            foreach ($this->capabilitiesFor($role) as $capability) {
                if ($capability->changesKpiValues() || $capability->configuresKpis()) {
                    throw new LogicException("[{$role->value}] must not change KPI values or configuration ([{$capability->value}]).");
                }
            }
        }
    }
}
