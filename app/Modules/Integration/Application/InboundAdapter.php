<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Models\User;
use App\Modules\Integration\Domain\Manifest;
use App\Modules\Shared\Application\Audit\Actor;

/**
 * Anti-corruption boundary: takes a file already mapped to the internal
 * import contract and hands it to the owning module's staged import. The
 * modules never see a vendor payload, and the adapter never writes their
 * tables itself.
 */
interface InboundAdapter
{
    public function stage(Connection $connection, Manifest $manifest, string $path, User $runAs, Actor $actor): StagedTarget;

    public function commit(StagedTarget $target, User $runAs, Actor $actor): void;
}
