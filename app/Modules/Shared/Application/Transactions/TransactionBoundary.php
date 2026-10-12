<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Transactions;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TransactionBoundary
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation, int $attempts = 3): mixed
    {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Transaction attempts must be at least one.');
        }

        return DB::transaction($operation, $attempts);
    }
}
