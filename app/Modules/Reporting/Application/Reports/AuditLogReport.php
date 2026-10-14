<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The audit trail for a date range: who did what to which record, with the
 * values before and after as the audit log stored them (already sanitized
 * when written). Audit entries are not tied to an organization unit, so the
 * report needs `audit_report.view` over the whole company.
 */
final class AuditLogReport implements ReportDefinition
{
    public const MAX_DAYS = 92;

    public function __construct(
        private readonly ReportSupport $support,
        private readonly BusinessTime $time,
    ) {}

    public function key(): string
    {
        return 'audit_log';
    }

    public function title(): string
    {
        return 'Laporan audit';
    }

    public function description(): string
    {
        return 'Jejak audit pada rentang tanggal: aksi, record, pelaku, role, alasan, serta nilai sebelum dan sesudah.';
    }

    public function columns(): array
    {
        return ['Waktu (UTC)', 'Aksi', 'Jenis record', 'ID record', 'Pelaku', 'Tipe pelaku', 'Role', 'Sumber', 'Alasan', 'Sebelum', 'Sesudah', 'Correlation ID'];
    }

    public function filters(): array
    {
        return ['from', 'to', 'entity'];
    }

    public function statuses(): array
    {
        return [];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function available(User $user): bool
    {
        return $this->support->access->unitScope($user, Capability::AuditReportView)->all;
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if (! $this->available($user)) {
            throw new AuthorizationException;
        }

        if ($filters->from === null || $filters->to === null) {
            throw new BusinessRuleViolation('date_range_required', 'Laporan audit memerlukan tanggal awal dan tanggal akhir.');
        }

        // Both dates are included, so the same day is a range of one day.
        if ((int) CarbonImmutable::parse($filters->from)->diffInDays(CarbonImmutable::parse($filters->to)) + 1 > self::MAX_DAYS) {
            throw new BusinessRuleViolation('date_range_too_long', 'Rentang laporan audit maksimal '.self::MAX_DAYS.' hari.');
        }

        return new ReportContext($user, $filters, null, null, 'Seluruh perusahaan (jejak audit)');
    }

    public function rows(ReportContext $context): iterable
    {
        $zone = $this->time->timezoneName();
        $from = CarbonImmutable::parse((string) $context->filters->from, $zone)->startOfDay()->utc();
        $until = CarbonImmutable::parse((string) $context->filters->to, $zone)->addDay()->startOfDay()->utc();

        $entries = AuditLog::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->when($context->filters->entity !== null, fn ($query) => $query->where('entity_type', $context->filters->entity))
            ->orderBy('id')
            ->lazyById(500);

        foreach ($entries as $entry) {
            yield [
                $entry->occurred_at->format('Y-m-d H:i:s'), (string) $entry->getAttribute('action'), (string) $entry->getAttribute('entity_type'),
                (string) $entry->getAttribute('entity_id'), self::text($entry->getAttribute('actor_identifier')), (string) $entry->getAttribute('actor_type'),
                self::text($entry->getAttribute('actor_role')), (string) $entry->getAttribute('source'), self::text($entry->getAttribute('reason')),
                $entry->before_values === null ? null : json_encode($entry->before_values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $entry->after_values === null ? null : json_encode($entry->after_values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                (string) $entry->getAttribute('correlation_id'),
            ];
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
