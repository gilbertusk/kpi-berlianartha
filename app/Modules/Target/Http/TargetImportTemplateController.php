<?php

declare(strict_types=1);

namespace App\Modules\Target\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Target\Application\TargetImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the fill-in CSV for a period: only targets inside the caller's
 * target.manage scope that a file may still set.
 */
final readonly class TargetImportTemplateController
{
    public function __invoke(Request $request, AssessmentPeriod $period, AccessControl $access, TargetImportService $imports): StreamedResponse
    {
        Gate::authorize('manageTargets', $period);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $rows = $imports->templateRows($period, $access->unitScope($user, Capability::TargetManage));

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            // UTF-8 BOM so spreadsheet applications detect the encoding.
            fwrite($output, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, 'target-'.strtolower($period->code).'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
