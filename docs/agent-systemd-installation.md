# Dokumentasi Service Agent ServMon (systemd)

Dokumen ini menjelaskan cara instalasi dan penghapusan service agent ServMon berbasis `systemd`.

## Ringkasan
- Role umum: gunakan `agent.sh` + `servmon-agent.service` + `servmon-agent.timer`
- Role cPanel email: gunakan `agent-cpanel-mail.sh` + `servmon-agent-cpanel-email.service` + `servmon-agent-cpanel-email.timer`
- Interval default: 1 menit via timer
- Jangan aktifkan `cron` dan `systemd timer` bersamaan untuk agent yang sama

## Prasyarat
- Linux dengan `systemd`
- Akses `root` (atau sudo setara)
- `curl`, `bash`, `awk`, `df`, `free`
- Endpoint ServMon aktif (`api/push.php`)
- Token server dari panel admin ServMon

## 1) Instalasi Role Umum (agent.sh)
Jalankan di server target:

```bash
wget https://YOUR_SERVMON_DOMAIN/agents/agent.sh -O /opt/agent.sh
chmod +x /opt/agent.sh

sed -i 's|^MASTER_URL=.*|MASTER_URL="https://YOUR_SERVMON_DOMAIN/api/push.php"|' /opt/agent.sh
sed -i 's|^SERVER_TOKEN=.*|SERVER_TOKEN="YOUR_64HEX_TOKEN"|' /opt/agent.sh
sed -i 's|^SERVER_ID=.*|SERVER_ID=YOUR_SERVER_ID|' /opt/agent.sh

# Optional: nonaktifkan log file lokal agar I/O lebih ringan
sed -i 's|^LOG_FILE=.*|LOG_FILE=""|' /opt/agent.sh

# Uji manual
/opt/agent.sh

# Optional debug non-produksi:
# Jika Anda memakai /opt/agent-improve.sh, set DEBUG_PAYLOAD=1 untuk log payload/response.
```

Install unit:

```bash
wget https://YOUR_SERVMON_DOMAIN/agents/systemd/servmon-agent.service -O /etc/systemd/system/servmon-agent.service
wget https://YOUR_SERVMON_DOMAIN/agents/systemd/servmon-agent.timer -O /etc/systemd/system/servmon-agent.timer

systemctl daemon-reload
systemctl enable --now servmon-agent.timer
```

Verifikasi:

```bash
systemctl status servmon-agent.timer --no-pager
systemctl status servmon-agent.service --no-pager
systemctl list-timers --all | grep servmon-agent
journalctl -u servmon-agent.service -n 50 --no-pager
```

## 2) Instalasi Role cPanel Email (agent-cpanel-mail.sh)
Jalankan di server target:

```bash
wget https://YOUR_SERVMON_DOMAIN/agents/agent-cpanel-mail.sh -O /opt/agent-cpanel-mail.sh
chmod +x /opt/agent-cpanel-mail.sh

sed -i 's|^MASTER_URL=.*|MASTER_URL="https://YOUR_SERVMON_DOMAIN/api/push.php"|' /opt/agent-cpanel-mail.sh
sed -i 's|^SERVER_TOKEN=.*|SERVER_TOKEN="YOUR_64HEX_TOKEN"|' /opt/agent-cpanel-mail.sh
sed -i 's|^SERVER_ID=.*|SERVER_ID=YOUR_SERVER_ID|' /opt/agent-cpanel-mail.sh

# Optional: nonaktifkan log file lokal agar I/O lebih ringan
sed -i 's|^LOG_FILE=.*|LOG_FILE=""|' /opt/agent-cpanel-mail.sh

# Uji manual
/opt/agent-cpanel-mail.sh

# Optional debug non-produksi:
# Jika Anda memakai /opt/agent-improve.sh, set DEBUG_PAYLOAD=1 untuk log payload/response.
```

Install unit:

```bash
wget https://YOUR_SERVMON_DOMAIN/agents/systemd/servmon-agent-cpanel-email.service -O /etc/systemd/system/servmon-agent-cpanel-email.service
wget https://YOUR_SERVMON_DOMAIN/agents/systemd/servmon-agent-cpanel-email.timer -O /etc/systemd/system/servmon-agent-cpanel-email.timer

systemctl daemon-reload
systemctl enable --now servmon-agent-cpanel-email.timer
```

Verifikasi:

```bash
systemctl status servmon-agent-cpanel-email.timer --no-pager
systemctl status servmon-agent-cpanel-email.service --no-pager
systemctl list-timers --all | grep servmon-agent-cpanel-email
journalctl -u servmon-agent-cpanel-email.service -n 50 --no-pager
```

Catatan:
- Script `agent-cpanel-mail.sh` mengirim `panel_profile=cpanel_email`, sehingga service key email/security sesuai whitelist backend.

## 3) Migrasi dari Cron ke systemd (jika sebelumnya pakai cron)
Lihat cron aktif:

```bash
crontab -l
```

Hapus baris agent lama agar tidak double push (contoh):

```bash
crontab -l | grep -v '/opt/agent.sh' | grep -v '/opt/agent-cpanel-mail.sh' | crontab -
```

## 4) Cara Menghapus Service/Timer (Uninstall)
### Uninstall role umum

```bash
systemctl disable --now servmon-agent.timer
systemctl stop servmon-agent.service 2>/dev/null || true

rm -f /etc/systemd/system/servmon-agent.timer
rm -f /etc/systemd/system/servmon-agent.service

systemctl daemon-reload
systemctl reset-failed
```

Optional hapus script dan state:

```bash
rm -f /opt/agent.sh
rm -f /tmp/servmon-agent.lock /tmp/servmon-agent-net.state
```

### Uninstall role cPanel email

```bash
systemctl disable --now servmon-agent-cpanel-email.timer
systemctl stop servmon-agent-cpanel-email.service 2>/dev/null || true

rm -f /etc/systemd/system/servmon-agent-cpanel-email.timer
rm -f /etc/systemd/system/servmon-agent-cpanel-email.service

systemctl daemon-reload
systemctl reset-failed
```

Optional hapus script dan state:

```bash
rm -f /opt/agent-cpanel-mail.sh
rm -f /tmp/servmon-agent-email.lock /tmp/servmon-agent-email-net.state
```

## 5) Troubleshooting Singkat
- Pre-flight checklist:
  - `MASTER_URL` wajib ke `/api/push.php`.
  - `SERVER_TOKEN` harus 64 hex.
  - `SERVER_ID` harus cocok dengan server pemilik token.
  - Jika server mewajibkan request signature, aktifkan `SIGN_REQUESTS=1`.
- Timer aktif tapi data tidak masuk:
  - Cek token dan `SERVER_ID` pada script.
  - Cek koneksi keluar ke `api/push.php`.
  - Cek log unit via `journalctl -u <unit> -n 100 --no-pager`.
- Status HTTP bukan 200:
  - Biasanya token tidak valid, `server_id` mismatch, atau endpoint salah.
- Data dobel:
  - Pastikan hanya satu scheduler aktif: `cron` atau `systemd timer`.
