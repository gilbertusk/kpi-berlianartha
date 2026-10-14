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

    /** KPI dictionary and scorecard templates (Step 06). */
    case KpiDefinitionView = 'kpi_definition.view';
    case KpiDefinitionManage = 'kpi_definition.manage';
    case KpiDefinitionPublish = 'kpi_definition.publish';
    case KpiTemplateView = 'kpi_template.view';
    case KpiTemplateManage = 'kpi_template.manage';
    case KpiTemplatePublish = 'kpi_template.publish';

    /** Assessment periods, assignment snapshots, and targets (Step 07). */
    case PeriodView = 'period.view';
    case PeriodManage = 'period.manage';
    case TargetView = 'target.view';
    case TargetManage = 'target.manage';
    case TargetApprove = 'target.approve';

    /** Actuals and evidence (Step 08). */
    case ActualView = 'actual.view';
    case ActualEnter = 'actual.enter';
    case ActualVerify = 'actual.verify';

    /**
     * Scorecards (Step 09). `scorecard.view` covers unit scorecards in scope
     * and the holder's own scorecard; another employee's scorecard needs
     * `individual_scorecard.view` (ROLE_APPROVAL_MATRIX "Melihat score
     * pribadi": C for managers, not activated).
     */
    case ScorecardView = 'scorecard.view';
    case IndividualScorecardView = 'individual_scorecard.view';
    case ScorecardCalculate = 'scorecard.calculate';

    /**
     * Approval routing and corrections (Step 10). Who may request and who may
     * approve a correction is a conservative default until DEC-002 is signed.
     */
    case ApprovalRouteManage = 'approval_route.manage';
    case CorrectionRequest = 'correction.request';
    case CorrectionApprove = 'correction.approve';

    /**
     * Daily team review, corrective actions, and coaching notes (Step 11).
     * Coaching notes are restricted personal data (DEC-011): reading them
     * needs `coaching_note.manage`, which is never delegable.
     */
    case TeamReviewView = 'team_review.view';
    case TeamReviewManage = 'team_review.manage';
    case CoachingNoteManage = 'coaching_note.manage';

    /**
     * Reports and exports (Step 12). A report shows what its own scope
     * allows; exporting is a rendering of the same rows, so one read
     * capability covers both. The audit report covers the whole audit trail.
     */
    case ReportView = 'report.view';
    case AuditReportView = 'audit_report.view';

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
        return in_array($this, [
            self::KpiValueModify,
            self::ApprovalDecide,
            self::TargetManage,
            self::TargetApprove,
            self::ActualEnter,
            self::ActualVerify,
            self::ScorecardCalculate,
            self::CorrectionRequest,
            self::CorrectionApprove,
        ], true);
    }

    /**
     * Authoring or publishing KPI definitions and templates, and setting up
     * assessment periods, changes how scores are computed, so technical and
     * audit roles may never hold it.
     */
    public function configuresKpis(): bool
    {
        return in_array($this, [
            self::KpiDefinitionManage,
            self::KpiDefinitionPublish,
            self::KpiTemplateManage,
            self::KpiTemplatePublish,
            self::PeriodManage,
            self::ApprovalRouteManage,
        ], true);
    }

    /**
     * Delegation transfers read access and approval authority only; master
     * data, account, role, and value-entry duties stay with the holder.
     */
    public function isDelegable(): bool
    {
        // The audit trail is read by the audit and HR roles themselves, never on someone's behalf.
        if ($this === self::AuditReportView) {
            return false;
        }

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
