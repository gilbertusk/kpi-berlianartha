<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One report of the catalog. The same definition feeds the on-screen
 * preview and every export, so a file can never show something the screen
 * would not: both go through {@see authorize()} and {@see rows()}.
 */
interface ReportDefinition
{
    public function key(): string;

    public function title(): string;

    public function description(): string;

    /**
     * @return list<string> column headings
     */
    public function columns(): array;

    /**
     * @return list<string> names of the {@see ReportFilters} fields this report offers
     */
    public function filters(): array;

    /**
     * @return array<string, string> allowed values of the status filter => label
     */
    public function statuses(): array;

    public function classification(): Classification;

    /**
     * Whether the account may open this report at all (catalog visibility).
     */
    public function available(User $user): bool;

    /**
     * Resolves the request against the account's current access. Everything
     * the rows depend on (period, unit, scope) is decided here.
     *
     * @throws AuthorizationException when the account may not see the report or a filter value
     * @throws BusinessRuleViolation when the request is not valid
     */
    public function authorize(User $user, ReportFilters $filters): ReportContext;

    /**
     * Rows in a stable order, produced lazily in bounded chunks.
     *
     * @return iterable<int, list<string|null>>
     */
    public function rows(ReportContext $context): iterable;
}
