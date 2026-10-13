<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignmentApprover;
use App\Modules\Shared\Application\Outbox\OutboxConsumer;
use App\Modules\Shared\Application\Outbox\OutboxEnvelope;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;

/**
 * Turns workflow events into notifications (PRD §12). Each notification's
 * deduplication key is derived from the event's subject, so reprocessing an
 * outbox message tells nobody twice. A record that no longer exists is
 * skipped rather than failing the message forever.
 */
final readonly class WorkflowNotificationConsumer implements OutboxConsumer
{
    private const EVENTS = [
        'ApprovalStageReady', 'ScorecardReturned', 'CorrectionRequested', 'CorrectionApproved', 'CorrectionRejected',
        'ActualRejected', 'CorrectiveActionAssigned', 'CorrectiveActionCompleted',
    ];

    public function __construct(
        private NotificationService $notifications,
        private RecipientResolver $recipients,
    ) {}

    public function name(): string
    {
        return 'workflow-notifications';
    }

    public function supports(string $eventType, int $eventVersion): bool
    {
        return $eventVersion === 1 && in_array($eventType, self::EVENTS, true);
    }

    public function handle(OutboxEnvelope $message): void
    {
        match ($message->eventType) {
            'ApprovalStageReady' => $this->stageReady($message),
            'ScorecardReturned' => $this->returned($message),
            'CorrectionRequested', 'CorrectionApproved', 'CorrectionRejected' => $this->correction($message),
            'ActualRejected' => $this->actualRejected($message),
            'CorrectiveActionAssigned', 'CorrectiveActionCompleted' => $this->correctiveAction($message),
            default => null,
        };
    }

    private function stageReady(OutboxEnvelope $message): void
    {
        $stage = ApprovalStage::tryFrom((string) ($message->payload['stage'] ?? ''));
        $period = AssessmentPeriod::query()->where('public_id', (string) ($message->payload['period'] ?? ''))->first();

        if ($stage === null || $period === null) {
            return;
        }

        $reviewId = $message->payload['review'] ?? null;

        $approvers = PeriodAssignmentApprover::query()
            ->where('stage', $stage->value)
            ->whereIn('period_assignment_id', ScorecardReview::query()
                ->where('assessment_period_id', $period->id)
                ->when(is_string($reviewId), fn ($query) => $query->where('public_id', $reviewId))
                ->select('period_assignment_id'))
            ->distinct()
            ->pluck('user_id');

        // Entering review announces the whole period in one message per
        // approver; later a message is about one scorecard and one round.
        $subject = is_string($reviewId) ? $reviewId : $period->public_id;
        $occasion = is_string($message->payload['occasion'] ?? null) ? $message->payload['occasion'] : 'opened';
        $this->notifications->notify($approvers->map(static fn ($id): int => (int) $id)->all(), new NotificationMessage(
            category: NotificationCategory::ApprovalTask,
            title: "Scorecard menunggu {$stage->label()} Anda",
            body: "Ada scorecard periode {$period->code} yang menunggu keputusan Anda pada tahap {$stage->label()}.",
            deduplicationKey: "stage-ready:{$subject}:{$stage->value}:{$occasion}",
            routeName: 'approvals.index',
        ));
    }

    private function returned(OutboxEnvelope $message): void
    {
        $review = ScorecardReview::query()->with(['assignment', 'period'])->where('public_id', $message->aggregateId)->first();

        if ($review === null) {
            return;
        }

        $this->notifications->notify(
            $this->recipients->withCapability(Capability::ScorecardCalculate, $review->assignment->organization_unit_id),
            new NotificationMessage(
                category: NotificationCategory::ScorecardReturned,
                title: 'Scorecard dikembalikan',
                body: "Scorecard {$review->assignment->subject_code} periode {$review->period->code} dikembalikan dan menunggu pengajuan ulang.",
                deduplicationKey: "scorecard-returned:{$review->public_id}:".(int) ($message->payload['round'] ?? 0),
                routeName: 'approvals.review',
                routeParameters: ['review' => $review->public_id],
            ),
        );
    }

    private function correction(OutboxEnvelope $message): void
    {
        $request = CorrectionRequest::query()->with(['actual.target', 'period'])->where('public_id', $message->aggregateId)->first();

        if ($request === null) {
            return;
        }

        $target = $request->actual->target;
        $about = "{$target->kpi_code} · {$target->subject_code} periode {$request->period->code}";

        if ($message->eventType === 'CorrectionRequested') {
            $recipients = array_values(array_diff(
                $this->recipients->withCapability(Capability::CorrectionApprove, $target->organization_unit_id),
                [$request->requested_by_user_id],
            ));
            $title = 'Permintaan koreksi menunggu keputusan';
            $body = "Koreksi aktual {$about} menunggu keputusan Anda.";
        } else {
            $recipients = [$request->requested_by_user_id];
            $title = $message->eventType === 'CorrectionApproved' ? 'Koreksi disetujui' : 'Koreksi ditolak';
            $body = "Permintaan koreksi aktual {$about} sudah diputuskan.";
        }

        $this->notifications->notify($recipients, new NotificationMessage(
            category: NotificationCategory::Correction,
            title: $title,
            body: $body,
            deduplicationKey: "{$message->eventType}:{$request->public_id}",
            routeName: 'approvals.corrections',
        ));
    }

    private function actualRejected(OutboxEnvelope $message): void
    {
        $actual = KpiActual::query()->with('target.period')->where('public_id', $message->aggregateId)->first();
        $submitter = $message->payload['submitted_by_user_id'] ?? null;

        if ($actual === null || ! is_int($submitter)) {
            return;
        }

        $this->notifications->notify([$submitter], new NotificationMessage(
            category: NotificationCategory::ActualRejected,
            title: 'Aktual ditolak',
            body: "Aktual {$actual->target->kpi_code} · {$actual->target->subject_code} ditolak dan perlu diperbaiki.",
            deduplicationKey: "actual-rejected:{$actual->public_id}:".(int) ($message->payload['version'] ?? 0).":{$message->messageId}",
            routeName: 'actuals.entry',
            routeParameters: ['period' => $actual->target->period->public_id],
        ));
    }

    private function correctiveAction(OutboxEnvelope $message): void
    {
        $action = CorrectiveAction::query()->with('issue')->where('public_id', $message->aggregateId)->first();

        if ($action === null) {
            return;
        }

        $assigned = $message->eventType === 'CorrectiveActionAssigned';

        $this->notifications->notify(
            $assigned ? [$action->owner_user_id] : array_values(array_diff([$action->created_by_user_id, $action->issue->reported_by_user_id], [$action->owner_user_id])),
            new NotificationMessage(
                category: NotificationCategory::CorrectiveAction,
                title: $assigned ? 'Corrective action ditugaskan kepada Anda' : 'Corrective action menunggu verifikasi',
                body: $assigned
                    ? "Anda menjadi PIC sebuah corrective action dengan tenggat {$action->due_on}."
                    : 'Sebuah corrective action dilaporkan selesai dan menunggu verifikasi Anda.',
                deduplicationKey: "{$message->eventType}:{$action->public_id}:".(string) ($message->payload['occasion'] ?? '1'),
                routeName: 'team-review.action',
                routeParameters: ['action' => $action->public_id],
            ),
        );
    }
}
