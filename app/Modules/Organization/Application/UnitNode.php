<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\UnitType;

/**
 * An organization unit as it existed on one business date.
 */
final readonly class UnitNode
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public UnitType $type,
        public string $name,
        public ?int $parentId,
    ) {}
}
