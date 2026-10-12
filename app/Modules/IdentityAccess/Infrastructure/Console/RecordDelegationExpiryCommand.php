<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Console;

use App\Modules\IdentityAccess\Application\DelegationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class RecordDelegationExpiryCommand extends Command
{
    protected $signature = 'identity:expire-delegations';

    protected $description = 'Record an audit entry for every delegation whose end date has passed';

    public function handle(DelegationService $delegations): int
    {
        $recorded = $delegations->recordExpirations(CarbonImmutable::now());

        $this->components->info("Recorded {$recorded} expired delegation(s).");

        return self::SUCCESS;
    }
}
