<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Notification\Application\RecipientResolver;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CoachingNote;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\TeamReviewIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of the team review. Every list is restricted in the query to
 * the units the account may see, plus the actions it owns.
 */
final readonly class TeamReviewQuery
{
    public const PER_PAGE = 20;

    public const ACTION_FILTERS = [
        'outstanding' => 'Belum selesai',
        'overdue' => 'Melewati tenggat',
        'to_verify' => 'Menunggu verifikasi',
        'mine' => 'PIC saya',
        'done' => 'Verified / Closed',
    ];

    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
        private RecipientResolver $recipients,
    ) {}

    /**
     * @return LengthAwarePaginator<int, TeamReviewIssue>
     */
    public function issues(UnitScope $scope, ?int $unitId, int $page): LengthAwarePaginator
    {
        $query = TeamReviewIssue::query()->with(['unit', 'reportedBy', 'kpis'])->withCount('actions');
        self::restrict($query, $scope);

        return $query
            ->when($unitId !== null, fn (Builder $issues) => $issues->where('organization_unit_id', $unitId))
            ->orderByDesc('review_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'masalah', $page);
    }

    /**
     * @return LengthAwarePaginator<int, CorrectiveAction>
     */
    public function actions(User $user, UnitScope $scope, ?string $filter, int $page): LengthAwarePaginator
    {
        $today = $this->access->businessToday();
        $query = $this->visibleActions($user, $scope)->with(['owner', 'unit', 'issue']);

        match ($filter) {
            'outstanding' => $query->whereIn('status', self::outstanding()),
            'overdue' => $query->whereIn('status', self::outstanding())->where('due_on', '<', $today),
            'to_verify' => $query->where('status', ActionStatus::InProgress->value)->whereNotNull('completed_at'),
            'mine' => $query->where('owner_user_id', $user->id),
            'done' => $query->whereIn('status', [ActionStatus::Verified->value, ActionStatus::Closed->value]),
            default => null,
        };

        return $query->orderBy('due_on')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'tindakan', $page);
    }

    /**
     * Counts for dashboards, by one aggregate query.
     *
     * @return array{outstanding: int, overdue: int, to_verify: int}
     */
    public function actionCounts(User $user, UnitScope $scope): array
    {
        $today = $this->access->businessToday();
        $outstanding = self::outstanding();

        $row = $this->visibleActions($user, $scope)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) AS outstanding', $outstanding)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) AND due_on < ? THEN 1 ELSE 0 END) AS overdue', [...$outstanding, $today])
            ->selectRaw('SUM(CASE WHEN status = ? AND completed_at IS NOT NULL THEN 1 ELSE 0 END) AS to_verify', [ActionStatus::InProgress->value])
            ->toBase()
            ->first();

        return [
            'outstanding' => (int) ($row->outstanding ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'to_verify' => (int) ($row->to_verify ?? 0),
        ];
    }

    /**
     * Accounts that can be made PIC of an action of the unit: its members
     * with an active account, and the accounts that manage its review.
     *
     * @return array<int, string> user id => name
     */
    public function ownerCandidates(int $unitId): array
    {
        $today = $this->access->businessToday();
        $userIds = [];

        foreach ($this->directory->homeAssignmentsOn($this->directory->snapshot($today)->subtreeIds($unitId), $today) as $member) {
            if ($member->userId !== null) {
                $userIds[] = $member->userId;
            }
        }

        $userIds = [...$userIds, ...$this->recipients->withCapability(Capability::TeamReviewManage, $unitId)];

        return User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->orderBy('name')
            ->get()
            ->filter(static fn (User $candidate): bool => $candidate->isActive())
            ->mapWithKeys(static fn (User $candidate): array => [$candidate->id => $candidate->name])
            ->all();
    }

    /**
     * Notes about one employee that the account may read.
     *
     * @return Collection<int, CoachingNote>
     */
    public function coachingNotes(User $user, Employee $employee, UnitScope $coachingScope): Collection
    {
        if ($employee->user_id === $user->id) {
            return new Collection;
        }

        return CoachingNote::query()
            ->with('author')
            ->where('employee_id', $employee->id)
            ->where(function (Builder $readable) use ($user, $coachingScope): void {
                $readable->where('author_user_id', $user->id);

                if ($coachingScope->all) {
                    $readable->orWhereNotNull('id');
                } elseif ($coachingScope->unitIds !== []) {
                    $readable->orWhereIn('organization_unit_id', $coachingScope->unitIds);
                }
            })
            ->orderByDesc('noted_on')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return Builder<CorrectiveAction>
     */
    private function visibleActions(User $user, UnitScope $scope): Builder
    {
        return CorrectiveAction::query()->where(function (Builder $visible) use ($user, $scope): void {
            $visible->where('owner_user_id', $user->id);

            if ($scope->all) {
                $visible->orWhereNotNull('id');
            } elseif ($scope->unitIds !== []) {
                $visible->orWhereIn('organization_unit_id', $scope->unitIds);
            }
        });
    }

    /**
     * @param  Builder<TeamReviewIssue>  $query
     */
    private static function restrict(Builder $query, UnitScope $scope): void
    {
        if (! $scope->all) {
            $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }
    }

    /**
     * @return list<string>
     */
    private static function outstanding(): array
    {
        return [ActionStatus::Open->value, ActionStatus::InProgress->value, ActionStatus::Waiting->value];
    }
}
