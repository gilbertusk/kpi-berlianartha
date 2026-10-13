<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A restricted note a team leader wrote about one employee (DEC-011).
 * Append-only: a note is never edited or deleted.
 *
 * @property int $id
 * @property string $public_id
 * @property int $employee_id
 * @property int $organization_unit_id
 * @property int $author_user_id
 * @property string $noted_on
 * @property string $note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Employee $employee
 * @property-read User $author
 */
final class CoachingNote extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'noted_on' => BusinessDate::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
