<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Production\Application\ProductionImportService;
use App\Modules\Production\Application\ProductionRowParser;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Support\Facades\Config;

/**
 * Production batches for tests. The shift calendar is a NON-PRODUCTION
 * FIXTURE: DEC-015 is open, so no real shift pattern exists yet.
 */
final class ProductionHarness
{
    /**
     * 2026-10-14 10:00 Asia/Jakarta: the night shift of 2026-10-13 has ended.
     */
    public const NOW = '2026-10-14 03:00:00';

    /**
     * @return array<string, mixed>
     */
    public static function calendar(string $anchor = 'start'): array
    {
        return [
            'version' => 'fixture-3x8',
            'effective_from' => '2026-01-01',
            'label' => 'NON-PRODUCTION FIXTURE',
            'overnight_operational_date' => $anchor,
            'shifts' => [
                ['code' => 'S1', 'start' => '07:00', 'end' => '15:00'],
                ['code' => 'S2', 'start' => '15:00', 'end' => '23:00'],
                ['code' => 'S3', 'start' => '23:00', 'end' => '07:00'],
            ],
        ];
    }

    public static function useCalendar(string $anchor = 'start'): void
    {
        Config::set('production.shift_calendars', [self::calendar($anchor)]);
    }

    /**
     * @param  list<string>  $lines  CSV rows in the column order of the kind
     */
    public static function file(BatchKind $kind, array $lines): string
    {
        $path = storage_path('framework/testing/'.uniqid('production-', true).'.csv');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, implode(',', ProductionRowParser::COLUMNS[$kind->value])."\n".implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * @param  list<string>  $lines
     * @param  array{quantity?: string|null, reject?: string|null, rework?: string|null}  $controls
     */
    public static function stage(User $by, BatchKind $kind, array $lines, string $unit, string $date, ?string $extractedAt = null, array $controls = [], string $source = 'mes-fixture'): ProductionBatch
    {
        $path = self::file($kind, $lines);

        try {
            return app(ProductionImportService::class)->stage(self::actor($by), $by, AccessFixtures::unit($unit)->id, $kind, $source, $date, $extractedAt ?? '2026-10-14 09:30', 'batch.csv', $path, 'csv', $controls);
        } finally {
            @unlink($path);
        }
    }

    public static function commit(User $by, ProductionBatch $batch): ProductionBatch
    {
        return app(ProductionImportService::class)->commit(self::actor($by), $by, $batch->id);
    }

    /**
     * @param  list<string>  $lines
     * @param  array{quantity?: string|null, reject?: string|null, rework?: string|null}  $controls
     */
    public static function load(User $by, BatchKind $kind, array $lines, string $unit, string $date, array $controls = []): ProductionBatch
    {
        return self::commit($by, self::stage($by, $kind, $lines, $unit, $date, controls: $controls));
    }

    public static function actor(User $user): Actor
    {
        return new Actor($user->id, Actor::system('test')->audit);
    }

    /**
     * Output, downtime, and waste of Woven on operational date 2026-10-13,
     * including the night shift that ends on 2026-10-14.
     *
     * @return list<string>
     */
    public static function wovenDay(): array
    {
        // record_type,event_id,work_order,product_code,process_code,machine_code,operator_code,shift_code,started_at,ended_at,unit,good_qty,reject_qty,rework_qty,waste_qty,defect_code,reason_code
        return [
            'output,EV-1,WO-100,P-1,WEAVE,M-01,OP-7,S1,2026-10-13 08:00,,pcs,1000.5,20,5,,DF-01,',
            'output,EV-2,WO-100,P-1,WEAVE,M-01,,,2026-10-13 16:00,,pcs,800,0,0,,,',
            'output,EV-3,WO-101,P-2,WEAVE,M-02,,S3,2026-10-14 02:30,,pcs,300.25,10,0,,,',
            'output,EV-4,WO-102,P-3,WEAVE,M-02,,,2026-10-13 23:30,,m,150.125,0,2.5,,,RS-1',
            'downtime,EV-5,,,,M-01,,,2026-10-13 10:00,2026-10-13 10:45,,,,,,,RC-MECH',
            'downtime,EV-6,,,,M-02,,,2026-10-14 05:30,2026-10-14 06:30,,,,,,,',
            'waste,EV-7,WO-100,,,M-01,,,2026-10-13 12:00,,kg,,,,3.5,,RW-1',
        ];
    }
}
