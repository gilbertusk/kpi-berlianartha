<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Audit\AuditContext;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('business audit captures context and redacts secrets inside the transaction', function (): void {
    $context = new AuditContext(
        actorIdentifier: 'user-12',
        actorType: 'user',
        actorRole: 'team_leader',
        actorScope: ['team_id' => 'team-1', 'access_token' => 'hidden'],
        source: 'web',
        sourceReference: 'request-123',
        correlationId: '01KCORRELATION000000000000',
        requestId: 'request-123',
    );

    app(TransactionBoundary::class)->run(fn () => app(AuditRecorder::class)->record(
        new AuditEntry(
            action: 'object.registered',
            entityType: 'stored_object_metadata',
            entityId: 'object-1',
            before: null,
            after: ['status' => 'pending', 'password' => 'hidden'],
            reason: 'Test fixture',
            context: $context,
        ),
    ));

    $audit = AuditLog::query()->firstOrFail();

    expect($audit->actor_scope)->toBe([
        'team_id' => 'team-1',
        'access_token' => '[REDACTED]',
    ])->and($audit->after_values)->toBe([
        'status' => 'pending',
        'password' => '[REDACTED]',
    ])->and($audit->correlation_id)->toBe('01KCORRELATION000000000000');
});

test('audit is immutable through Eloquent and bulk SQL', function (): void {
    $audit = app(TransactionBoundary::class)->run(fn () => app(AuditRecorder::class)->record(
        new AuditEntry(
            action: 'test.created',
            entityType: 'test',
            entityId: '1',
            before: null,
            after: ['status' => 'created'],
            reason: null,
            context: AuditContext::system('test'),
        ),
    ));

    expect(fn () => $audit->forceFill(['reason' => 'changed'])->save())
        ->toThrow(LogicException::class);

    expect(fn () => $audit->delete())
        ->toThrow(LogicException::class);

    expect(fn () => DB::table('audit_logs')->where('id', $audit->id)->update(['reason' => 'changed']))
        ->toThrow(QueryException::class);
});

test('failed transaction leaves no domain row audit or outbox event', function (): void {
    $operation = function (): void {
        app(TransactionBoundary::class)->run(function (): void {
            StoredObjectMetadata::factory()->create();

            app(AuditRecorder::class)->record(new AuditEntry(
                action: 'object.registered',
                entityType: 'stored_object_metadata',
                entityId: 'temporary',
                before: null,
                after: ['status' => 'pending'],
                reason: null,
                context: AuditContext::system('test'),
            ));

            app(OutboxPublisher::class)->publish(new OutboxEvent(
                eventType: 'TestEvent',
                eventVersion: 1,
                aggregateType: 'test',
                aggregateId: 'temporary',
                payload: ['status' => 'pending'],
            ));

            throw new RuntimeException('force rollback');
        });
    };

    expect($operation)->toThrow(RuntimeException::class)
        ->and(StoredObjectMetadata::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(DB::table('outbox_messages')->count())->toBe(0);
});

