<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Http;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\EvidenceFile;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\RequestActorFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one evidence file. The route is signed and short-lived, the link
 * is bound to the account it was issued to, and the account's right to see
 * the actual is checked again at download time.
 */
final readonly class EvidenceDownloadController
{
    public function __invoke(Request $request, EvidenceFile $evidence, string $viewer, ActualPeriodAccess $access, EvidenceService $files, RequestActorFactory $actors): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && hash_equals($user->public_id, $viewer), 403);
        abort_unless($access->allowsTarget($user, Capability::ActualView, $evidence->actual->target), 403);

        $stream = $files->open($actors->forCapability($user, Capability::ActualView), $evidence);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $evidence->original_name, [
            'Content-Type' => $evidence->detected_mime,
            'Content-Length' => (string) $evidence->byte_size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
