# Incident Response

**Status:** Step 18 — 16 Oktober 2026. Kerangka proses; nama peran, kanal komunikasi, dan jam dukungan ditetapkan bersama DEC-014 (hosting/operasi) dan DEC-010 (availability, maintenance window). Insiden data personal juga mengikuti kebijakan HR/Legal yang berlaku.

## 1. Severity

| Severity | Definisi | Contoh | Respons awal (usulan) | Update |
|---|---|---|---|---|
| SEV-1 | Layanan tidak dapat dipakai seluruh perusahaan, kehilangan/kerusakan data, atau dugaan kebocoran data RESTRICTED/CONFIDENTIAL | `/health` mati, database hilang, bukti terunduh oleh pihak tak berwenang | 15 menit, 24×7 setelah go-live | tiap 30 menit |
| SEV-2 | Fungsi utama terganggu untuk banyak pengguna atau ada risiko integritas, ada jalan pintas | worker/scheduler mati, notifikasi berhenti, integrasi kritis basi menjelang deadline periode | 1 jam (jam kerja) | tiap 2 jam |
| SEV-3 | Fungsi terbatas terganggu, dampak kecil atau satu unit | satu koneksi integrasi dead-letter, ekspor gagal berulang | 1 hari kerja | harian |
| SEV-4 | Kosmetik, peringatan, atau perbaikan terencana | slow query, dependency Medium | backlog | — |

Waktu respons dan frekuensi update adalah usulan; berlaku setelah disetujui bersama availability dan jam dukungan (DEC-010, DEC-014). Bila ragu, pilih severity lebih tinggi lalu turunkan.

## 2. Peran

| Peran | Tugas |
|---|---|
| Incident Commander (IC) | Menetapkan severity, mengoordinasikan, memutuskan tindakan berisiko (restore, rollback, mematikan fitur) |
| Operator teknis | Diagnosa dan pemulihan sesuai runbook (`ALERTS.md`, `DR_RUNBOOK.md`) |
| Komunikasi | Update ke pengguna dan manajemen |
| Pemilik bisnis/data | Keputusan dampak data (koreksi, input ulang, periode) — HR Performance untuk data KPI; pemilik data sumber untuk integrasi (DEC-016) |
| IT Security | Wajib untuk dugaan kebocoran, akses tidak sah, atau secret terekspos |

## 3. Alur dan eskalasi

1. **Deteksi**: alert (`ALERTS.md`), laporan pengguna, atau review audit. Catat waktu dan `request_id` bila ada.
2. **Triage** (≤ waktu respons awal): tetapkan severity dan IC; buka catatan insiden.
3. **Eskalasi**: SEV-1 → IC + IT Security (bila menyangkut data) + manajemen IT segera; SEV-2 → IC + operator, manajemen bila > 4 jam; SEV-3/4 → tiket.
4. **Containment**: hentikan kerusakan dulu — maintenance mode, nonaktifkan koneksi integrasi (`INTEGRATION_<KONEKSI>_ENABLED=false`), cabut akun/role, rotasi kredensial, rollback rilis.
5. **Pemulihan**: runbook terkait; restore hanya lewat `DR_RUNBOOK.md`.
6. **Verifikasi**: `ops:readiness --strict`, alert normal, cek bisnis oleh pemilik data.
7. **Penutupan**: IC menyatakan selesai; PIR untuk SEV-1/SEV-2 dalam 5 hari kerja.

### Insiden keamanan dan data personal

- Jangan menghapus log, audit, atau container yang terdampak; ambil salinan untuk investigasi.
- Audit log (`audit_logs`) dan log terstruktur (`request_id`, `user` public_id) dipakai untuk menentukan siapa mengakses apa; isi log sudah disamarkan, jangan menyalin data personal ke kanal chat.
- Secret yang terekspos dianggap bocor: rotasi (`BACKUP_RESTORE.md` §6) dan cabut session (`sessions` table / ganti akun).
- Kewajiban notifikasi ke pihak terdampak/regulator mengikuti keputusan HR/Legal.

## 4. Komunikasi

| Audiens | Saat | Isi minimum |
|---|---|---|
| Pengguna terdampak | SEV-1/2 terkonfirmasi, tiap update, saat pulih | Apa yang tidak berfungsi, jalan pintas, perkiraan update berikutnya; tanpa detail teknis sensitif |
| HR Performance / pemilik periode | Bila deadline input/approval terdampak | Fitur dan periode terdampak, usulan perpanjangan deadline (diputuskan HR sesuai DEC-009) |
| Manajemen | SEV-1, SEV-2 > 4 jam, atau insiden data | Dampak, tindakan, risiko, keputusan yang dibutuhkan |
| IT Security / Legal | Dugaan kebocoran | Data dan akun terlibat, rentang waktu, bukti |

Template update singkat:

```
[SEV-x] <judul> — <status: investigasi | mitigasi | pulih>
Dampak: <siapa/fungsi apa>
Sejak: <waktu WIB>
Tindakan: <yang sedang dilakukan>
Jalan pintas: <bila ada>
Update berikutnya: <waktu WIB>
```

## 5. Template Post-Incident Review (PIR)

```
# PIR — <judul insiden>

- Severity / IC / tanggal:
- Ringkasan (3 kalimat):
- Dampak: pengguna/unit, fungsi, durasi, data hilang atau salah (RPO aktual), waktu pulih (RTO aktual)
- Timeline (WIB): deteksi, triage, eskalasi, containment, pemulihan, penutupan
- Deteksi: bagaimana diketahui; apakah alert bekerja; berapa lama sampai terdeteksi
- Akar masalah (teknis dan proses), dengan bukti (request_id, log, audit)
- Yang berjalan baik / yang tidak
- Tindakan perbaikan: | tindakan | pemilik | target tanggal | status |
- Perubahan runbook/alert yang diperlukan
- Data yang dikoreksi dan persetujuannya (alur koreksi teraudit)
```

PIR bersifat tanpa menyalahkan individu; fokus pada sistem dan proses.
