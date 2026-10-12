<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Transactions;

use Closure;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction($operation, $attempts);
    }
}
