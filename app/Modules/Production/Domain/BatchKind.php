<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

/**
 * The kinds of source data a production or PPIC team loads. Each team loads
 * only the kinds it is responsible for: Packing Logistic prepares goods
 * before shipping, Logistic ships them and keeps the proof of delivery, and
 * Warehouse reports stock per location category (PRD Lampiran B).
 */
enum BatchKind: string
{
    case Production = 'production';
    case Packing = 'packing';
    case Delivery = 'delivery';
    case Warehouse = 'warehouse';
    case WorkOrders = 'work_orders';
    case Inspection = 'inspection';

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Aktual produksi (output, downtime, waste)',
            self::Packing => 'Packing',
            self::Delivery => 'Pengiriman dan proof of delivery',
            self::Warehouse => 'Stok per lokasi warehouse',
            self::WorkOrders => 'Work order dan jadwal (PPIC)',
            self::Inspection => 'Inspeksi QC (incoming, in-process, final)',
        };
    }

    /**
     * The date one batch covers: the operational date of a shift-based
     * batch, the shipping date of deliveries, or the as-of date of a
     * reference snapshot.
     */
    public function coverageLabel(): string
    {
        return match ($this) {
            self::Production, self::Packing => 'Tanggal operasional',
            self::Delivery => 'Tanggal kirim',
            self::Warehouse, self::WorkOrders => 'Tanggal as-of',
            self::Inspection => 'Tanggal inspeksi',
        };
    }

    /**
     * Rows carry a local timestamp whose shift and operational date come
     * from the shift calendar.
     */
    public function usesShifts(): bool
    {
        return $this === self::Production || $this === self::Packing;
    }

    /**
     * Events of a kind that counts flows (output, packing, deliveries) are
     * counted once across all batches; a reference snapshot (stock, work
     * orders) repeats every row on every date by design.
     */
    public function countsEvents(): bool
    {
        return in_array($this, [self::Production, self::Packing, self::Delivery, self::Inspection], true);
    }
}
