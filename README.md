# servmon

Server monitoring self-hosted berbasis PHP 8.2+ dan MySQL 8.0, sesuai PRD fase 1-3.

## Struktur
- `auth/` login/logout admin
- `admin/` dashboard, server CRUD, detail, instruksi agent, audit logs
- `api/` endpoint push/status
- `agents/` `agent.sh` (push utama), `agent-cpanel-mail.sh` (khusus cPanel email host), `agent-disk-health.sh` (push disk health), `uptime.php` stub
- `agents/systemd/` template unit `servmon-agent*.service` + `servmon-agent*.timer`
- `includes/` bootstrap app, DB, auth, csrf, helper, layout
- `sql/` schema + seed
- `tests/` smoke/helper/API checks

## Setup cepat
1. Buat database dan tabel:
   - Jalankan `sql/schema.sql`
   - Lanjutkan `sql/seed.sql`
2. Set environment variable (opsional):
   - `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV`
3. Jalankan server:
   - `php -S 127.0.0.1:8000`
4. Buka:
   - Public: `http://127.0.0.1:8000/index.php`
   - Login admin: `http://127.0.0.1:8000/auth/login.php`
   - Seed user: `admin / admin123`
   - Seed viewer: `support / admin123`

## Setup via installer web
1. Jalankan aplikasi: `php -S 127.0.0.1:8000`
2. Buka: `http://127.0.0.1:8000/install.php`
3. Isi parameter aplikasi + database, lalu submit installer.
4. Setelah sukses, login ke `auth/login.php`.
5. Hapus atau rename `install.php` untuk keamanan.
6. Untuk upgrade schema pada instance terpasang, gunakan CLI: `php migrate.php` (web upgrade dinonaktifkan).

## Upgrade schema (Network Usage)
Jika Anda sudah deploy versi sebelumnya, jalankan:
- `sql/migrations/20260222_add_network_metrics.sql`
- `sql/migrations/20260222_add_alert_settings.sql`
- `sql/migrations/20260222_add_service_monitoring.sql`
- `sql/migrations/20260222_add_panel_profile.sql`
- `sql/migrations/20260222_add_service_alert_setting.sql`
- `sql/migrations/20260224_add_admin_audit_and_maintenance.sql`
- `sql/migrations/20260224_alert_quality_and_push_allowlist.sql`
- `sql/migrations/20260224_add_ping_monitoring.sql`
- `sql/migrations/20260224_add_ping_http_check.sql`
- `sql/migrations/20260225_add_latest_metric_id.sql`
- `sql/migrations/20260304_add_disk_health_tables.sql`

## Worker Cron (Phase Alert)
- Cek alert down/recovery + threshold:
  - `* * * * * /usr/bin/php /path/to/servmon/workers/alert-check.php >/dev/null 2>&1`
- Cek ping monitor IP/domain:
  - `* * * * * /usr/bin/php /path/to/servmon/workers/ping-check.php >/dev/null 2>&1`
  - Mendukung metode `ICMP` dan `HTTP Check`.
- Rollup histori disk health (harian):
  - `0 2 * * * /usr/bin/php /path/to/servmon/workers/disk-rollup.php >/dev/null 2>&1`
  - Mengisi tabel `disk_health_metrics_history` dari raw `disk_health_metrics`.
- Cleanup retensi data (harian):
  - `0 3 * * * /usr/bin/php /path/to/servmon/workers/cleanup.php >/dev/null 2>&1`
  - Membersihkan `service_metrics`, `metrics`, `alert_logs`, `login_attempts`, dan `admin_audit_logs` lama sesuai `retention_days`
- Cleanup retensi disk health (harian, terpisah):
  - `30 2 * * * /usr/bin/php /path/to/servmon/workers/disk-cleanup.php >/dev/null 2>&1`
  - Membersihkan `disk_health_metrics` dan `disk_health_metrics_history` sesuai `disk_retention_days`
- Catatan:
  - Evaluasi alert berjalan via worker cron (`workers/alert-check.php`), bukan dari endpoint status read-only.

## Path Canonical
- Gunakan path worker canonical:
  `workers/alert-check.php`, `workers/ping-check.php`, `workers/disk-rollup.php`, `workers/cleanup.php`, `workers/disk-cleanup.php`.
- Gunakan path agent canonical:
  `agents/agent.sh`, `agents/agent-cpanel-mail.sh`.

## Agent Scheduler (Recommended)
- Gunakan `systemd timer` 1 menit untuk agent push (lebih stabil dan ringan dibanding daemon loop).
- Template unit tersedia:
  - `agents/systemd/servmon-agent.service`
  - `agents/systemd/servmon-agent.timer`
  - `agents/systemd/servmon-agent-cpanel-email.service`
  - `agents/systemd/servmon-agent-cpanel-email.timer`
- Khusus host cPanel email, gunakan `agents/agent-cpanel-mail.sh` yang mengirim `panel_profile=cpanel_email`.
- Panduan lengkap install + uninstall service:
  - `docs/agent-systemd-installation.md`

## Agent Deploy Checklist
- Pastikan `MASTER_URL` mengarah ke endpoint benar: `https://YOUR_SERVMON_DOMAIN/api/push.php`.
- Pastikan `SERVER_TOKEN` valid 64 karakter heksadesimal (sesuai token server di panel admin).
- Pastikan `SERVER_ID` sama dengan ID server yang terikat dengan token (wajib match).
- Jika backend mengaktifkan signature wajib, set `SIGN_REQUESTS=1` di agent.
- Untuk audit non-produksi, set `DEBUG_PAYLOAD=1` (khusus `agents/agent-improve.sh`) agar payload + HTTP response tercatat ringkas (jangan aktifkan permanen di produksi).
- Untuk disk health agent, arahkan `MASTER_URL` ke `https://YOUR_SERVMON_DOMAIN/api/push-disk.php`.
- Jika binary `hdsentinel-018c-x64` berada di lokasi custom, set `HDSENTINEL_BIN=/path/to/hdsentinel-018c-x64`.

## Redis Config (opsional)
Tambahkan di env atau `includes/local.php`:
- `REDIS_ENABLED=1`
- `REDIS_HOST=127.0.0.1`
- `REDIS_PORT=6379`
- `REDIS_PASSWORD=`
- `REDIS_DB=0`
- `REDIS_PREFIX=servmon:`

## Cache TTL (dapat diatur di UI)
Di `admin/settings.php`, bagian `Cache TTL (detik)`:
- `Status List`
- `Status Single`
- `History 24h`
- `History 7d`
- `History 30d`

## Test
- Windows PowerShell: `pwsh tests/smoke.ps1`
- Linux/macOS: `bash tests/smoke.sh`
- Schema consistency: `php tests/schema_consistency_test.php`
- API smoke (butuh app running): `BASE_URL=http://127.0.0.1:8000 bash tests/api_smoke.sh`
- AuthZ matrix (opsional viewer creds): `BASE_URL=http://127.0.0.1:8000 bash tests/authz_matrix.sh`
- Health check endpoint: `GET /api/health.php` (200 = healthy, 503 = degraded)
- E2E scenario checklist: `tests/e2e_scenarios.md`

## Optional Push Request Signing
Untuk hardening tambahan pada `api/push.php`, agent dapat mengirim header:
- `X-Server-Timestamp`: Unix timestamp (detik)
- `X-Server-Signature`: `sha256` HMAC dari string `{timestamp}.{raw_json_payload}` dengan key token server

Jika header signing tidak dikirim, mode token-only tetap didukung untuk kompatibilitas.

## API History Payload Control
Endpoint history `api/status.php?id={id}&history=24h` mendukung parameter opsional:
- `points` (100-5000, default 2000) untuk membatasi jumlah titik data respons.

## Operations
- Release checklist: `docs/release-checklist.md`
- Rollback playbook: `docs/rollback-playbook.md`
