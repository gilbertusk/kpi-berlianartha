<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Http\RequestActorFactory;
use App\Modules\Reporting\Application\ExportService;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportExport;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one export. The route is signed and short-lived, the link is
 * bound to the account it was issued to, the export must not be expired,
 * and the account's right to the report is checked again.
 */
final readonly class ExportDownloadController
{
    public function __invoke(Request $request, ReportExport $export, string $viewer, ExportService $exports, RequestActorFactory $actors): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && hash_equals($user->public_id, $viewer) && $export->user_id === $user->id, 403);

        try {
            $stream = $exports->open($actors->forUser($user), $user, $export);
        } catch (BusinessRuleViolation) {
            abort(410);
        }

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, (string) $export->file_name, [
            'Content-Type' => $export->format->mime(),
            'Content-Length' => (string) $export->byte_size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
