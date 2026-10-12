<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Outbox;

use InvalidArgumentException;

final class OutboxConsumerRegistry
{
    /** @var list<OutboxConsumer> */
    private array $consumers;

    /**
     * @param  iterable<OutboxConsumer>  $consumers
     */
    public function __construct(iterable $consumers = [])
    {
        $registered = [];
        $names = [];

        foreach ($consumers as $consumer) {
            if (isset($names[$consumer->name()])) {
                throw new InvalidArgumentException("Duplicate outbox consumer [{$consumer->name()}].");
            }

            $names[$consumer->name()] = true;
            $registered[] = $consumer;
        }

        $this->consumers = $registered;
    }

    /**
     * @return list<OutboxConsumer>
     */
    public function consumersFor(string $eventType, int $eventVersion): array
    {
        return array_values(array_filter(
            $this->consumers,
            fn (OutboxConsumer $consumer): bool => $consumer->supports($eventType, $eventVersion),
        ));
    }
}
