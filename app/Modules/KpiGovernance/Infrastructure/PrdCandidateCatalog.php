<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure;

/**
 * PRD v0.1 Lampiran A "Kandidat KPI per Team" (scripts/build_prd.py),
 * keyed by the reference team codes of OrganizationReferenceSeeder. These are
 * candidates only: importing them creates draft definitions without formula,
 * target, owner, source, weight, or approver (DEC-005, DEC-006 OPEN).
 */
final class PrdCandidateCatalog
{
    public const SOURCE = 'PRD v0.1 (12 Oktober 2026) Lampiran A';

    /** @var array<string, list<string>> */
    public const CANDIDATES = [
        'COMSUP-SALES' => ['Revenue achievement', 'Sales volume', 'Gross margin', 'Customer baru', 'Repeat order', 'Customer retention', 'Quotation conversion rate', 'Forecast accuracy', 'Jumlah order batal', 'Kelengkapan informasi order', 'Jumlah complaint per customer', 'Collection support untuk invoice bermasalah'],
        'COMSUP-CS' => ['Response time kepada customer', 'Order entry accuracy', 'Kelengkapan purchase order dan spesifikasi', 'Kecepatan konfirmasi order', 'Order handover ke PPIC tepat waktu', 'Jumlah revisi akibat kesalahan input', 'Complaint response time', 'Complaint closure time', 'Customer satisfaction', 'Open request yang melewati SLA'],
        'COMSUP-RND-COSTING' => ['Costing lead time', 'Ketepatan estimasi biaya', 'Selisih estimated cost versus actual cost', 'Trial success rate', 'Sample completion lead time', 'Material usage optimization', 'Jumlah revisi costing', 'Kecepatan pembuatan spesifikasi', 'Produk baru selesai tepat waktu', 'Cost reduction dari improvement', 'Kelengkapan BOM dan routing', 'Handover ke produksi tanpa masalah'],
        'COMSUP-DESIGN-A' => ['Artwork lead time', 'First approval pass rate', 'Jumlah revisi internal', 'Jumlah revisi dari customer', 'Kesalahan artwork yang masuk produksi', 'Ketepatan teks ukuran warna dan barcode', 'File selesai sesuai jadwal', 'Kepatuhan terhadap versi artwork', 'Design backlog', 'Rework akibat kesalahan design', 'Produktivitas design per orang', 'Approval customer turnaround'],
        'COMSUP-DESIGN-B' => ['Artwork lead time', 'First approval pass rate', 'Jumlah revisi internal', 'Jumlah revisi dari customer', 'Kesalahan artwork yang masuk produksi', 'Ketepatan teks ukuran warna dan barcode', 'File selesai sesuai jadwal', 'Kepatuhan terhadap versi artwork', 'Design backlog', 'Rework akibat kesalahan design', 'Produktivitas design per orang', 'Approval customer turnaround'],
        'COMSUP-ADMIN-PPIC' => ['Production schedule attainment', 'Work order release tepat waktu', 'Plan versus actual production', 'Material readiness', 'Jumlah perubahan jadwal', 'Backlog order', 'Work order overdue', 'Keakuratan jadwal mesin', 'Kelengkapan dokumen produksi', 'Lead time perencanaan', 'Emergency job frequency', 'Koordinasi handover antarproses', 'Ketepatan update status order'],
        'COMSUP-PURCHASING' => ['Supplier on time delivery', 'Incoming acceptance rate', 'Purchase request to purchase order lead time', 'Material shortage', 'Emergency purchase', 'Purchase price variance', 'Purchase order accuracy', 'Supplier complaint closure', 'Supplier response time', 'Approved supplier compliance', 'Material datang sesuai kebutuhan PPIC', 'Supplier performance score'],
        'COMSUP-HR' => ['Recruitment lead time', 'Kehadiran karyawan', 'Ketepatan data absensi', 'Employee turnover', 'Training completion', 'Competency fulfillment', 'Performance review completion', 'Masa probation selesai tepat waktu', 'Kelengkapan dokumen karyawan', 'Employee complaint resolution', 'Disciplinary case closure', 'Manpower plan versus actual'],
        'COMSUP-GA' => ['Penyelesaian permintaan fasilitas sesuai SLA', 'Facility uptime', 'Kepatuhan 5R atau 5S', 'Utility consumption', 'Pengelolaan kendaraan', 'Kepatuhan perizinan', 'Pengelolaan keamanan', 'Kebersihan area kerja', 'General service request completion', 'Pengeluaran GA versus anggaran', 'Safety finding closure', 'Ketersediaan fasilitas produksi'],
        'ACC-ADMIN' => ['Ketepatan input jurnal', 'Closing bulanan tepat waktu', 'Reconciliation completion', 'Document completeness', 'Posting accuracy', 'Jumlah jurnal koreksi', 'Bank reconciliation accuracy', 'Expense verification lead time', 'Financial report timeliness', 'Open reconciliation items', 'Audit finding closure', 'Kelengkapan dokumen transaksi'],
        'ACC-AR' => ['Days Sales Outstanding', 'Collection rate', 'Overdue invoice percentage', 'Aging receivable', 'Invoice dispute resolution time', 'Customer payment allocation accuracy', 'Unapplied payment', 'Collection follow up completion', 'Bad debt exposure', 'Customer statement accuracy', 'Outstanding invoice tanpa tindak lanjut', 'Collection forecast accuracy'],
        'ACC-TAX-INVOICE' => ['Faktur pajak diterbitkan tepat waktu', 'Tax invoice accuracy', 'Jumlah faktur pajak yang direvisi', 'Reconciliation invoice versus faktur pajak', 'Kelengkapan data NPWP atau NIK customer', 'Kepatuhan deadline pelaporan', 'Rejected tax invoice', 'Penyelesaian dokumen pajak', 'Outstanding faktur pajak', 'Audit atau tax finding closure'],
        'PROD-WOVEN' => ['Good output', 'Output per jam', 'Production yield', 'Waste percentage', 'Reject rate', 'Rework rate', 'Machine utilization', 'Downtime', 'Schedule attainment', 'Setup atau changeover time', 'Productivity per operator', 'Kesalahan spesifikasi produk'],
        'PROD-WAREHOUSE' => ['Inventory accuracy', 'Picking accuracy', 'Receiving accuracy', 'Material issue lead time', 'Stockout frequency', 'FIFO atau FEFO compliance', 'Selisih stock opname', 'Damaged material', 'Material traceability', 'Slow moving stock', 'Finished goods accuracy', 'Kesalahan lot material'],
        'PROD-SCREEN-PRINT' => ['Good output per jam', 'First pass yield', 'Reject printing', 'Rework', 'Setup time', 'Changeover time', 'Waste material', 'Machine availability', 'Schedule attainment', 'Downtime', 'Defect berdasarkan jenis', 'Kepatuhan parameter produksi'],
        'PROD-QC' => ['Incoming rejection', 'In process rejection', 'Final rejection', 'First pass yield', 'Inspection lead time', 'Customer complaint rate', 'Customer return rate', 'Repeat defect', 'Corrective action closure', 'Release produk tepat waktu', 'Calibration compliance', 'Audit finding closure'],
        'PROD-PACKING-LOGISTIC' => ['Packing accuracy', 'Packing output per jam', 'Salah jumlah packing', 'Salah label atau karton', 'Damage saat packing', 'Packing lead time', 'Finished goods handover tepat waktu', 'Packing material waste', 'Repacking rate', 'Kelengkapan dokumen', 'Schedule attainment', 'Produk tertahan di area packing'],
        'PROD-LOGISTIC' => ['On Time Delivery', 'On Time In Full', 'Delivery accuracy', 'Proof of delivery completion', 'Damage during delivery', 'Salah customer atau alamat', 'Return akibat kesalahan delivery', 'Delivery cost', 'Transporter performance', 'Vehicle utilization', 'Delivery document completeness', 'Delivery complaint closure'],
        'PROD-HOT-CUT' => ['Output per jam', 'Good quantity', 'Reject cutting', 'Kesalahan ukuran', 'Waste', 'Setup time', 'Changeover time', 'Machine downtime', 'Schedule attainment', 'Rework', 'Productivity per operator', 'Kepatuhan standar proses'],
        'PROD-HANG-TAG' => ['Output per jam', 'First pass yield', 'Reject rate', 'Rework rate', 'Kesalahan ukuran dan finishing', 'Schedule attainment', 'Setup time', 'Waste material', 'Machine utilization', 'Order completion accuracy', 'Packing accuracy', 'Productivity per operator'],
        'PROD-FLEXO' => ['OEE', 'Good output', 'Production speed versus standard', 'Substrate waste', 'Ink waste', 'Setup waste', 'Register defect', 'Color defect', 'Printing defect', 'Changeover time', 'Downtime', 'Schedule attainment', 'First pass yield', 'Rework', 'Produktivitas per shift'],
    ];

    public static function count(): int
    {
        return array_sum(array_map('count', self::CANDIDATES));
    }

    public static function codeFor(string $teamCode, int $position): string
    {
        return sprintf('CAND-%s-%02d', $teamCode, $position);
    }
}
