<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Concurrency;

use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use LogicException;

final class OptimisticLock
{
    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(Model $model, int $expectedVersion, array $attributes): Model
    {
        if (! array_key_exists('lock_version', $model->getAttributes())) {
            throw new LogicException('The model does not expose a lock_version attribute.');
        }

        unset($attributes['lock_version'], $attributes[$model->getKeyName()]);
        $attributes['lock_version'] = $expectedVersion + 1;

        if ($model->usesTimestamps()) {
            $attributes[$model->getUpdatedAtColumn()] = Date::now();
        }

        $affected = $model->newQuery()
            ->where($model->getKeyName(), $model->getKey())
            ->where('lock_version', $expectedVersion)
            ->update($attributes);

        if ($affected !== 1) {
            throw new ConcurrencyConflict(
                $model->getMorphClass(),
                (string) $model->getKey(),
                $expectedVersion,
            );
        }

        /** @var TModel $model */
        return $model->refresh();
    }
}
