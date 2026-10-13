<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\RequestActorFactory;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScorecardQuery;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reconciliation export for HR and auditors: the latest calculation of each
 * scorecard the account may see, one row per KPI line. An account that can
 * only see its own scorecard does not export (ROLE_APPROVAL_MATRIX "Export":
 * C for employees, not activated). Every export is audited with its scope.
 */
final readonly class ScorecardExportController
{
    public function __invoke(
        Request $request,
        AssessmentPeriod $period,
        ScorePeriodAccess $access,
        ScorecardQuery $scorecards,
        TransactionBoundary $transactions,
        AuditRecorder $audit,
        RequestActorFactory $actors,
    ): StreamedResponse {
        Gate::authorize('viewScorecards', $period);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $scope = $access->viewScope($user);
        abort_unless($scope->reachesOthers(), 403);

        $actor = $actors->forCapability($user, Capability::ScorecardView);
        $transactions->run(fn () => $audit->record(new AuditEntry(
            action: 'scorecard.exported',
            entityType: 'assessment_period',
            entityId: $period->public_id,
            before: null,
            after: ['format' => 'csv', 'subjects_in_scope' => $scorecards->base($period, $scope)->count()],
            reason: null,
            context: $actor->audit,
        )));

        $rows = $scorecards->exportRows($period, $scope);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, 'scorecard-'.strtolower($period->code).'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
