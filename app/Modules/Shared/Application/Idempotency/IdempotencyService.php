<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Idempotency;

use App\Modules\Shared\Domain\Exceptions\IdempotencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\IdempotencyKey;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class IdempotencyService
{
    /**
     * @param  Closure(): array<string, mixed>  $operation
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public function execute(
        string $scope,
        string $key,
        string $requestFingerprint,
        Closure $operation,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $keyHash = hash('sha256', $key);
        $fingerprintHash = hash('sha256', $requestFingerprint);

        return DB::transaction(function () use (
            $scope,
            $keyHash,
            $fingerprintHash,
            $operation,
            $expiresAt,
        ): array {
            $record = IdempotencyKey::query()
                ->where('scope', $scope)
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if ($record !== null && $record->expires_at?->isPast()) {
                $record->delete();
                $record = null;
            }

            if ($record !== null) {
                if ($record->request_fingerprint !== $fingerprintHash || $record->status !== 'completed') {
                    throw new IdempotencyConflict($scope);
                }

                return $record->response_payload ?? [];
            }

            $record = IdempotencyKey::query()->create([
                'scope' => $scope,
                'key_hash' => $keyHash,
                'request_fingerprint' => $fingerprintHash,
                'status' => 'processing',
                'expires_at' => $expiresAt,
            ]);

            $result = $operation();

            $record->forceFill([
                'status' => 'completed',
                'response_payload' => $result,
                'completed_at' => now('UTC'),
            ])->save();

            return $result;
        }, 3);
    }
}
