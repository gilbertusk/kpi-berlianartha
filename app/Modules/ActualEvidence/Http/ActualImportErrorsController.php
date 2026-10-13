<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Http;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualImportService;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportBatch;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the error rows of a staged import. A batch holds the rows of its
 * uploader's scope, so only the uploader, or an account that manages the
 * whole period, may read it.
 */
final readonly class ActualImportErrorsController
{
    public function __invoke(Request $request, AssessmentPeriod $period, ActualImportBatch $batch, ActualImportService $imports): StreamedResponse
    {
        Gate::authorize('enterActuals', $period);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($batch->assessment_period_id === $period->id, 404);
        abort_unless($batch->uploaded_by_user_id === $user->id || $user->can('update', $period), 404);

        $rows = $imports->errorRows($batch);

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
        }, 'aktual-error-'.strtolower($period->code).'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
