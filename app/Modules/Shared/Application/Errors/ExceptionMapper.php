<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Errors;

use App\Modules\Shared\Domain\ErrorCode;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ExceptionMapper
{
    public function map(Throwable $exception): ErrorPresentation
    {
        if ($exception instanceof SafeDomainException) {
            return new ErrorPresentation(
                $exception->errorCode,
                $exception->safeMessage(),
                $exception->httpStatus(),
                $exception->safeContext(),
            );
        }

        return match (true) {
            $exception instanceof ModelNotFoundException => new ErrorPresentation(
                ErrorCode::NOT_FOUND,
                'Data tidak ditemukan.',
                404,
            ),
            $exception instanceof AuthorizationException => new ErrorPresentation(
                ErrorCode::FORBIDDEN,
                'Anda tidak memiliki akses untuk tindakan ini.',
                403,
            ),
            $exception instanceof ValidationException => new ErrorPresentation(
                ErrorCode::VALIDATION_FAILED,
                'Data yang diberikan tidak valid.',
                422,
            ),
            default => new ErrorPresentation(
                ErrorCode::UNEXPECTED,
                'Terjadi kesalahan. Silakan coba kembali.',
                500,
            ),
        };
    }
}
