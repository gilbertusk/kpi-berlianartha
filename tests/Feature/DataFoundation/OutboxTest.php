<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Outbox\OutboxConsumerRegistry;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxProcessor;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Domain\Exceptions\IdempotencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxDelivery;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Tests\Support\RecordingOutboxConsumer;

test('outbox payload is sanitized and successful delivery is processed once', function (): void {
    $message = app(TransactionBoundary::class)->run(fn () => app(OutboxPublisher::class)->publish(
        new OutboxEvent(
            eventType: 'TestEvent',
            eventVersion: 1,
            aggregateType: 'test',
            aggregateId: 'aggregate-1',
            payload: ['status' => 'ready', 'api_token' => 'hidden'],
            deduplicationKey: 'event-1',
        ),
    ));

    $consumer = new RecordingOutboxConsumer;
    $processor = new OutboxProcessor(new OutboxConsumerRegistry([$consumer]));

    $processor->process($message->public_id);
    $processor->process($message->public_id);

    $message->refresh();

    expect($message->payload)->toBe([
        'status' => 'ready',
        'api_token' => '[REDACTED]',
    ])->and($message->processed_at)->not->toBeNull()
        ->and($consumer->handled)->toBe(1)
        ->and($consumer->messageIds)->toBe([$message->public_id])
        ->and(OutboxDelivery::query()->count())->toBe(1);
});

test('outbox retry stores only a safe failure code and schedules retry', function (): void {
    $message = app(TransactionBoundary::class)->run(fn () => app(OutboxPublisher::class)->publish(
        new OutboxEvent(
            eventType: 'TestEvent',
            eventVersion: 1,
            aggregateType: 'test',
            aggregateId: 'aggregate-2',
            payload: [],
        ),
    ));

    $processor = new OutboxProcessor(new OutboxConsumerRegistry([
        new RecordingOutboxConsumer(shouldFail: true),
    ]));

    expect(fn () => $processor->process($message->public_id))
        ->toThrow(RuntimeException::class);

    $message->refresh();

    expect($message->attempts)->toBe(1)
        ->and($message->processed_at)->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error_code)->toBe('consumer_failure')
        ->and($message->available_at->isFuture())->toBeTrue()
        ->and(OutboxDelivery::query()->count())->toBe(0);
});

test('outbox deduplication returns the same event and rejects conflicting payload', function (): void {
    $publisher = app(OutboxPublisher::class);
    $boundary = app(TransactionBoundary::class);

    $first = $boundary->run(fn () => $publisher->publish(new OutboxEvent(
        eventType: 'TestEvent',
        eventVersion: 1,
        aggregateType: 'test',
        aggregateId: 'aggregate-3',
        payload: ['value' => 1],
        deduplicationKey: 'same-key',
    )));

    $same = $boundary->run(fn () => $publisher->publish(new OutboxEvent(
        eventType: 'TestEvent',
        eventVersion: 1,
        aggregateType: 'test',
        aggregateId: 'aggregate-3',
        payload: ['value' => 1],
        deduplicationKey: 'same-key',
    )));

    expect($same->is($first))->toBeTrue()
        ->and(OutboxMessage::query()->count())->toBe(1);

    expect(fn () => $boundary->run(fn () => $publisher->publish(new OutboxEvent(
        eventType: 'TestEvent',
        eventVersion: 1,
        aggregateType: 'test',
        aggregateId: 'aggregate-3',
        payload: ['value' => 2],
        deduplicationKey: 'same-key',
    ))))->toThrow(IdempotencyConflict::class);
});
