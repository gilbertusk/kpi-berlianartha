<?php

/*
| Integration inventory (Step 17). DEC-008 (systems, access, owners, SLA)
| and DEC-016 (system of record per master) are OPEN, so no connection is
| PROVEN: file contracts are contract-tested on fixtures, and every runtime
| connection stays BLOCKED or DEFERRED until its source, owner, access,
| and credentials are approved. Credentials come only from the environment
| or secret store; nothing here holds a secret.
|
| A pipeline connection drops `<file>.csv` plus `<file>.csv.manifest.json`
| (contract kpi-inbound-v1, HMAC-SHA256 signed) into
| `<inbox_root>/inbox/<connection>/` on the integration disk.
*/

$pipeline = static fn (string $prefix): array => [
    'enabled' => (bool) env("INTEGRATION_{$prefix}_ENABLED", false),
    'hmac_key' => env("INTEGRATION_{$prefix}_HMAC_KEY"),
    'run_as' => env("INTEGRATION_{$prefix}_RUN_AS"),
];

$owners = [
    'production' => 'Team Leader team produksi terkait (usulan, DEC-016)',
    'ppic' => 'Team Leader Admin PPIC (usulan, DEC-016)',
    'warehouse' => 'Team Leader Warehouse (usulan, DEC-016)',
    'logistic' => 'Team Leader Logistic (usulan, DEC-016)',
    'packing' => 'Team Leader Packing Logistic (usulan, DEC-016)',
    'qc' => 'Team Leader Quality Control (usulan, DEC-016)',
    'ar' => 'Team Leader Account Receivable (usulan, DEC-016)',
    'hr' => 'HR Performance (usulan, DEC-016)',
];

$file = [
    'direction' => 'inbound',
    'protocol' => 'File CSV + manifest bertanda tangan (kpi-inbound-v1) pada drop zone; media transfer (SFTP/share) belum dipilih',
    'authentication' => 'HMAC-SHA256 manifest dengan kunci per koneksi dari environment/secret store; akses drop zone belum ditetapkan',
    'retry' => 'Kegagalan sementara diulang otomatis maksimal integrations.max_attempts kali dengan jeda bertambah, lalu dead-letter dan pemulihan manual (integration:retry)',
    'idempotency' => 'SHA-256 isi file per koneksi; file yang sama tidak diproses dua kali; batch tujuan juga idempotent per hash',
    'reconciliation' => 'Jumlah baris dan total kontrol manifest dibandingkan dengan batch; selisih menjadi exception',
    'error_handling' => 'Tanda tangan/checksum/manifest/skema salah → quarantine + exception; baris salah → batch staged dengan daftar masalah, tidak pernah commit sebagian',
    'sla' => 'Belum ditetapkan (DEC-008)',
];

return [

    'disk' => env('INTEGRATION_DISK', 'local'),

    'root' => env('INTEGRATION_ROOT', 'integration'),

    'max_attempts' => (int) env('INTEGRATION_MAX_ATTEMPTS', 3),

    // Files above this size are quarantined before they are read.
    'max_file_bytes' => (int) env('INTEGRATION_MAX_FILE_BYTES', 20 * 1024 * 1024),

    'connections' => [

        'hris-employees' => [
            'label' => 'HR/employee master',
            'system_of_record' => 'HRIS (belum ditetapkan, DEC-016)',
            'direction' => 'inbound (migrasi awal, lalu sinkronisasi)',
            'protocol' => 'File CSV lewat command migrasi; konektor HRIS belum ada',
            'authentication' => 'Operator menjalankan command di server; tidak ada kredensial sistem sumber',
            'file_pattern' => 'legacy-employees*.csv',
            'schema_version' => 'migration-employees-v1',
            'schedule' => 'Sekali saat cutover (dry run minimal dua kali)',
            'expected_volume' => 'Belum diketahui (DEC-007)',
            'owner' => 'IT (teknis), HR (bisnis)',
            'data_owner' => $owners['hr'],
            'sla' => 'Belum ditetapkan (DEC-008)',
            'retry' => 'Idempotent: menjalankan ulang tidak menggandakan pegawai',
            'idempotency' => 'Nomor induk pegawai',
            'reconciliation' => 'Jumlah baris, dibuat, tidak berubah, konflik, ditolak, dan jumlah per team',
            'error_handling' => 'Baris salah dan konflik masuk exception register; data yang sudah ada tidak ditimpa',
            'classification' => 'Restricted (data pegawai)',
            'status' => 'BLOCKED',
            'status_reason' => 'Sumber HRIS, field mapping, dan sign-off HR belum ada (DEC-008, DEC-016). Alat migrasi dan dry run diuji pada fixture sintetis.',
            'adapter' => null,
        ],

        'hris-organization' => [
            'label' => 'Struktur organisasi',
            'system_of_record' => 'Sistem KPI (Step 04) sampai HRIS ditetapkan',
            'direction' => 'Belum ada',
            'protocol' => 'Dikelola di aplikasi', 'authentication' => '—', 'file_pattern' => '—', 'schema_version' => '—',
            'schedule' => '—', 'expected_volume' => '—', 'owner' => 'HR', 'data_owner' => $owners['hr'], 'sla' => '—',
            'retry' => '—', 'idempotency' => '—', 'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Internal',
            'status' => 'DEFERRED',
            'status_reason' => 'Struktur dikelola di aplikasi dengan tanggal efektif; sinkronisasi menunggu keputusan system of record (DEC-016).',
            'adapter' => null,
        ],

        'attendance' => [
            'label' => 'Absensi',
            'system_of_record' => 'Belum ditetapkan', 'direction' => '—', 'protocol' => '—', 'authentication' => '—', 'file_pattern' => '—',
            'schema_version' => '—', 'schedule' => '—', 'expected_volume' => '—', 'owner' => 'HR', 'data_owner' => $owners['hr'], 'sla' => '—',
            'retry' => '—', 'idempotency' => '—', 'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Restricted',
            'status' => 'DEFERRED',
            'status_reason' => 'Belum ada KPI disetujui yang memakai data absensi (DEC-005, DEC-006).',
            'adapter' => null,
        ],

        'crm-sales-orders' => [
            'label' => 'Sales dan order',
            'system_of_record' => 'CRM/ERP (belum ditetapkan)', 'direction' => 'inbound', 'protocol' => 'Belum ditetapkan', 'authentication' => '—',
            'file_pattern' => '—', 'schema_version' => '—', 'schedule' => '—', 'expected_volume' => '—', 'owner' => 'Sales', 'data_owner' => 'Team Leader Sales (usulan)',
            'sla' => '—', 'retry' => '—', 'idempotency' => '—', 'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Restricted (margin, customer)',
            'status' => 'BLOCKED',
            'status_reason' => 'Tidak ada kontrak data internal yang disetujui (dashboard Sales BLOCKED sejak Step 13; DEC-005, DEC-006, DEC-008, DEC-010).',
            'adapter' => null,
        ],

        'erp-receivables' => [
            ...$file,
            ...$pipeline('ERP_RECEIVABLES'),
            'label' => 'Piutang dan faktur pajak',
            'system_of_record' => 'Sistem akuntansi (belum ditetapkan, DEC-016)',
            'file_pattern' => 'receivables-*.csv (kontrak ACCOUNTING_SOURCES.md)',
            'schema_version' => 'accounting-sources-v1',
            'schedule' => 'Harian setelah closing harian (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file',
            'owner' => 'IT (teknis), Accounting (bisnis)',
            'data_owner' => $owners['ar'],
            'classification' => 'Restricted (keuangan, customer)',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem akuntansi, akses, dan owner belum ditetapkan (DEC-008, DEC-016). Pipeline dan kontrak lulus contract test.',
            'adapter' => 'receivables',
            'control_totals' => ['invoice', 'allocation'],
            'column_map' => [],
            'auto_commit' => false,
        ],

        'erp-purchasing' => [
            'label' => 'Purchasing',
            'system_of_record' => 'ERP (belum ditetapkan)', 'direction' => '—', 'protocol' => '—', 'authentication' => '—', 'file_pattern' => '—',
            'schema_version' => '—', 'schedule' => '—', 'expected_volume' => '—', 'owner' => 'Purchasing', 'data_owner' => 'Team Leader Purchasing (usulan)', 'sla' => '—',
            'retry' => '—', 'idempotency' => '—', 'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Internal',
            'status' => 'DEFERRED',
            'status_reason' => 'Belum ada modul atau KPI disetujui yang memakai data purchasing.',
            'adapter' => null,
        ],

        'mes-production' => [
            ...$file,
            ...$pipeline('MES_PRODUCTION'),
            'label' => 'Aktual produksi (output, downtime, waste)',
            'system_of_record' => 'MES/ERP produksi (belum ditetapkan, DEC-016)',
            'file_pattern' => 'production-*.csv (kontrak PRODUCTION_SOURCES.md)',
            'schema_version' => 'production-sources-v1',
            'schedule' => 'Per tanggal operasional setelah shift terakhir (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file',
            'owner' => 'IT (teknis), Produksi (bisnis)',
            'data_owner' => $owners['production'],
            'classification' => 'Internal',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem sumber dan kalender shift belum disetujui (DEC-008, DEC-015, DEC-016). Pipeline lulus contract test.',
            'adapter' => 'production',
            'kind' => 'production',
            'control_totals' => ['quantity', 'reject', 'rework'],
            'column_map' => [],
            'auto_commit' => false,
        ],

        'mes-packing' => [
            ...$file,
            ...$pipeline('MES_PACKING'),
            'label' => 'Packing',
            'system_of_record' => 'MES/WMS (belum ditetapkan)',
            'file_pattern' => 'packing-*.csv', 'schema_version' => 'production-sources-v1', 'schedule' => 'Per tanggal operasional (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file', 'owner' => 'IT, Packing Logistic', 'data_owner' => $owners['packing'],
            'classification' => 'Internal',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem sumber dan kalender shift belum disetujui (DEC-008, DEC-015).',
            'adapter' => 'production', 'kind' => 'packing', 'control_totals' => ['quantity'], 'column_map' => [], 'auto_commit' => false,
        ],

        'tms-delivery' => [
            ...$file,
            ...$pipeline('TMS_DELIVERY'),
            'label' => 'Pengiriman dan proof of delivery',
            'system_of_record' => 'ERP/TMS (belum ditetapkan)',
            'file_pattern' => 'delivery-*.csv', 'schema_version' => 'production-sources-v1', 'schedule' => 'Harian (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file', 'owner' => 'IT, Logistic', 'data_owner' => $owners['logistic'],
            'classification' => 'Internal (customer code)',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem sumber belum ditetapkan (DEC-008, DEC-016).',
            'adapter' => 'production', 'kind' => 'delivery', 'control_totals' => ['quantity'], 'column_map' => [], 'auto_commit' => false,
        ],

        'erp-work-orders' => [
            ...$file,
            ...$pipeline('ERP_WORK_ORDERS'),
            'label' => 'Work order dan jadwal (PPIC)',
            'system_of_record' => 'ERP (belum ditetapkan)',
            'file_pattern' => 'work-orders-*.csv', 'schema_version' => 'production-sources-v1', 'schedule' => 'Beberapa kali sehari (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file', 'owner' => 'IT, Admin PPIC', 'data_owner' => $owners['ppic'],
            'classification' => 'Internal',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem sumber belum ditetapkan; write-back jadwal tidak diizinkan (DEC-008).',
            'adapter' => 'production', 'kind' => 'work_orders', 'control_totals' => ['quantity'], 'column_map' => [], 'auto_commit' => false,
        ],

        'wms-stock' => [
            ...$file,
            ...$pipeline('WMS_STOCK'),
            'label' => 'Stok warehouse per lokasi',
            'system_of_record' => 'WMS/ERP (belum ditetapkan)',
            'file_pattern' => 'stock-*.csv', 'schema_version' => 'production-sources-v1', 'schedule' => 'Harian (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file', 'owner' => 'IT, Warehouse', 'data_owner' => $owners['warehouse'],
            'classification' => 'Internal',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem sumber dan master lokasi belum ditetapkan (DEC-008, DEC-016).',
            'adapter' => 'production', 'kind' => 'warehouse', 'control_totals' => ['quantity'], 'column_map' => [], 'auto_commit' => false,
        ],

        'qms-inspection' => [
            ...$file,
            ...$pipeline('QMS_INSPECTION'),
            'label' => 'Inspeksi QC',
            'system_of_record' => 'QMS (belum ditetapkan)',
            'file_pattern' => 'inspection-*.csv', 'schema_version' => 'production-sources-v1', 'schedule' => 'Harian (usulan)',
            'expected_volume' => 'Maks. 20.000 baris per file', 'owner' => 'IT, Quality Control', 'data_owner' => $owners['qc'],
            'classification' => 'Internal',
            'status' => 'BLOCKED',
            'status_reason' => 'Sistem sumber dan taxonomy defect belum ditetapkan (DEC-008, DEC-016).',
            'adapter' => 'production', 'kind' => 'inspection', 'control_totals' => ['quantity', 'reject'], 'column_map' => [], 'auto_commit' => false,
        ],

        'qms-complaints' => [
            'label' => 'Complaint dan return customer',
            'system_of_record' => 'CRM/QMS (belum ditetapkan)', 'direction' => 'Rujukan nomor saja', 'protocol' => 'Nomor complaint/return dicatat pada NCR',
            'authentication' => '—', 'file_pattern' => '—', 'schema_version' => '—', 'schedule' => '—', 'expected_volume' => '—',
            'owner' => 'Customer Service, Quality Control', 'data_owner' => $owners['qc'], 'sla' => '—', 'retry' => '—', 'idempotency' => 'Unik per sumber dan kategori defect (Step 16)',
            'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Restricted (customer)',
            'status' => 'DEFERRED',
            'status_reason' => 'Record complaint/return tidak disalin; konektor menunggu sistem sumber (DEC-008).',
            'adapter' => null,
        ],

        'smtp-email' => [
            'label' => 'Email notifikasi',
            'system_of_record' => 'Sistem KPI (pengirim)', 'direction' => 'outbound',
            'protocol' => 'SMTP lewat antrean notifikasi (Step 11) dan transactional outbox',
            'authentication' => 'Kredensial SMTP dari environment (MAIL_*)', 'file_pattern' => '—', 'schema_version' => '—',
            'schedule' => 'Per event dan pengingat terjadwal', 'expected_volume' => 'Belum diketahui', 'owner' => 'IT', 'data_owner' => 'IT',
            'sla' => 'Belum ditetapkan', 'retry' => 'Antrean notifikasi dengan percobaan terbatas (Step 11)', 'idempotency' => 'Satu pengiriman per notifikasi dan channel',
            'reconciliation' => 'Status pengiriman per notifikasi', 'error_handling' => 'Kode error terakhir disimpan; tidak ada isi email di log',
            'classification' => 'Internal',
            'status' => 'BLOCKED',
            'status_reason' => 'Server SMTP produksi, domain, dan pengirim belum ditetapkan (DEC-014). Pengiriman lokal ke Mailpit terverifikasi di environment pengembangan.',
            'adapter' => null,
        ],

        'sso' => [
            'label' => 'SSO / identity provider',
            'system_of_record' => 'Identity provider perusahaan (belum ditetapkan)', 'direction' => 'inbound (autentikasi)', 'protocol' => 'Belum ditetapkan (OIDC/SAML)',
            'authentication' => '—', 'file_pattern' => '—', 'schema_version' => '—', 'schedule' => '—', 'expected_volume' => '—',
            'owner' => 'IT', 'data_owner' => 'IT', 'sla' => '—', 'retry' => '—', 'idempotency' => '—', 'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Restricted',
            'status' => 'BLOCKED',
            'status_reason' => 'Identity provider dan kebijakan MFA belum diputuskan (DEC-013). Login lokal dengan 2FA dipakai sementara.',
            'adapter' => null,
        ],

        'outbound-results' => [
            'label' => 'Hasil KPI ke payroll/HRIS',
            'system_of_record' => 'Sistem KPI', 'direction' => 'outbound', 'protocol' => 'Transactional outbox (tersedia)', 'authentication' => '—',
            'file_pattern' => '—', 'schema_version' => '—', 'schedule' => '—', 'expected_volume' => '—', 'owner' => 'HR', 'data_owner' => $owners['hr'],
            'sla' => '—', 'retry' => 'Outbox: percobaan terbatas, dead-letter, requeue manual (ops:outbox-requeue)', 'idempotency' => 'ID pesan outbox',
            'reconciliation' => '—', 'error_handling' => '—',
            'classification' => 'Restricted',
            'status' => 'DEFERRED',
            'status_reason' => 'Pemakaian skor untuk kompensasi di luar scope (DEC-012); tidak ada outbound feed yang disetujui.',
            'adapter' => null,
        ],
    ],

];
