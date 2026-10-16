<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reset link request that sent nothing (unknown address, or a link sent
 * moments ago) answers exactly like one that sent a link, so the form does
 * not reveal which addresses have an account (Step 18). The broker status
 * Fortify passes is deliberately ignored.
 */
final class UniformPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT])->toResponse($request);
    }
}
