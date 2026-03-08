#!/usr/bin/env bash
# ============================================================
#  SERVSTATS - Bash Push Agent
#  Version: 1.0
#  Created by: Arief Efriyan
#  Description: This script is used to push metrics to the web monitor API.
# ============================================================
set -euo pipefail

# Cron biasanya punya PATH minimal, sertakan sbin agar binary MTA tetap ketemu.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

# configuration servmon agent
MASTER_URL="${MASTER_URL:-https://status.yourdomain.com/api/push.php}"
SERVER_TOKEN="${SERVER_TOKEN:-CHANGE_ME_TOKEN}"
SERVER_ID="${SERVER_ID:-1}"
CURL_TIMEOUT="${CURL_TIMEOUT:-10}"
LOG_FILE="${LOG_FILE:-}" #/tmp/servmon-agent.log
NET_STATE_FILE="${NET_STATE_FILE:-/tmp/servmon-agent-net.state}"
LOCK_FILE="${LOCK_FILE:-/tmp/servmon-agent.lock}"
LOCK_WAIT_SECONDS="${LOCK_WAIT_SECONDS:-0}"
PANEL_PROFILE="${PANEL_PROFILE:-auto}"
DEBUG_PAYLOAD="${DEBUG_PAYLOAD:-0}"


SIGN_REQUESTS="${SIGN_REQUESTS:-0}"
# 0 Agent kirim biasa, hanya header X-Server-Token
# 1 Agent menambahkan: X-Server-Timestamp X-Server-Signature (HMAC-SHA256 dari timestamp.payload, key = SERVER_TOKEN)

MAIL_QUEUE_SPOOL_FALLBACK="${MAIL_QUEUE_SPOOL_FALLBACK:-0}"
# 0 = nonaktifkan fallback hitung spool via find (lebih ringan)
# 1 = aktifkan fallback via find jika command utama tidak tersedia}"

resolve_cmd() {
  local candidate
  for candidate in "$@"; do
    if [[ "${candidate}" == */* ]]; then
      if [[ -x "${candidate}" ]]; then
        echo "${candidate}"
        return 0
      fi
      continue
    fi

    if command -v "${candidate}" >/dev/null 2>&1; then
      command -v "${candidate}"
      return 0
    fi
  done

  return 1
}

log() {
  if [[ -n "${LOG_FILE}" ]]; then
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "${LOG_FILE}" 2>/dev/null || true
  fi
}

debug_log() {
  [[ "${DEBUG_PAYLOAD}" != "1" ]] && return 0
  log "DEBUG: $1"
  printf '%s\n' "DEBUG: $1" >&2
}

json_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

acquire_lock() {
  local flock_bin
  flock_bin="$(resolve_cmd flock /usr/bin/flock /bin/flock || true)"
  if [[ -z "${flock_bin}" ]]; then
    return 0
  fi

  exec 9>"${LOCK_FILE}"
  if ! "${flock_bin}" -w "${LOCK_WAIT_SECONDS}" 9; then
    log "WARN: lock sedang dipakai, skip run ini"
    exit 0
  fi
}

detect_mta() {
  local postfix_bin exim_bin
  postfix_bin="$(resolve_cmd postfix /usr/sbin/postfix /usr/lib/postfix/postfix || true)"
  exim_bin="$(resolve_cmd exim4 exim /usr/sbin/exim4 /usr/sbin/exim || true)"

  if [[ -n "${postfix_bin}" ]] && (pgrep -x master >/dev/null 2>&1 || [[ -d /var/spool/postfix ]]); then
    echo "postfix"; return
  fi
  if [[ -n "${exim_bin}" ]] || [[ -d /var/spool/exim4 ]] || [[ -d /var/spool/exim ]]; then
    echo "exim"; return
  fi
  if [[ -d /var/qmail ]] || pgrep -x qmail-send >/dev/null 2>&1; then
    echo "qmail"; return
  fi
  echo "none"
}

read_queue_total() {
  local mta="$1"
  local total=0
  local postqueue_bin exim_bin qmail_qstat_bin

  case "$mta" in
    postfix)
      postqueue_bin="$(resolve_cmd postqueue /usr/sbin/postqueue /usr/lib/postfix/sbin/postqueue || true)"
      if [[ -n "${postqueue_bin}" ]]; then
        total=$("${postqueue_bin}" -p 2>/dev/null | grep -cE '^[A-F0-9]{10,}' || true)
      fi
      if [[ "$total" -eq 0 && "${MAIL_QUEUE_SPOOL_FALLBACK}" == "1" && -z "${postqueue_bin}" ]]; then
        total=$(find /var/spool/postfix/active /var/spool/postfix/deferred /var/spool/postfix/hold /var/spool/postfix/incoming -type f 2>/dev/null | wc -l | awk '{print $1}')
      fi
      ;;
    exim)
      exim_bin="$(resolve_cmd exim4 exim /usr/sbin/exim4 /usr/sbin/exim || true)"
      if [[ -n "${exim_bin}" ]]; then
        total=$("${exim_bin}" -bpc 2>/dev/null || echo 0)
      fi
      if [[ "$total" -eq 0 && "${MAIL_QUEUE_SPOOL_FALLBACK}" == "1" && -z "${exim_bin}" ]]; then
        total=$(find /var/spool/exim4/input /var/spool/exim/input -name '*-H' -type f 2>/dev/null | wc -l | awk '{print $1}')
      fi
      ;;
    qmail)
      total=$(find /var/qmail/queue/mess -type f 2>/dev/null | wc -l | awk '{print $1}')
      qmail_qstat_bin="$(resolve_cmd qmail-qstat /var/qmail/bin/qmail-qstat || true)"
      if [[ "$total" -eq 0 ]] && [[ -n "${qmail_qstat_bin}" ]]; then
        total=$("${qmail_qstat_bin}" 2>/dev/null | awk '/^messages in queue:/ {print $4}')
      fi
      ;;
    *)
      total=0
      ;;
  esac

  echo "${total:-0}"
}

detect_service_status() {
  local pgrep_pattern="$1"
  shift
  local aliases=("$@")
  local alias
  local source="pgrep"
  local detected_any=0
  local detected_name=""
  local load_state=""
  local service_output=""
  local service_code=0

  if command -v systemctl >/dev/null 2>&1; then
    for alias in "${aliases[@]}"; do
      load_state="$(systemctl show "${alias}" -p LoadState --value 2>/dev/null || true)"
      if [[ -z "${load_state}" || "${load_state}" == "not-found" ]]; then
        continue
      fi
      detected_any=1
      detected_name="${alias}"
      if systemctl is-active --quiet "${alias}" 2>/dev/null; then
        echo "up|systemctl|${alias}"
        return
      fi
    done
    if [[ "${detected_any}" -eq 1 ]]; then
      echo "down|systemctl|${detected_name}"
      return
    fi
  fi

  if command -v service >/dev/null 2>&1; then
    detected_any=0
    detected_name=""
    for alias in "${aliases[@]}"; do
      set +e
      service_output="$(service "${alias}" status 2>&1)"
      service_code=$?
      set -e

      if echo "${service_output}" | grep -Eiq "unrecognized service|not-found|could not be found|unknown service|does not exist"; then
        continue
      fi

      detected_any=1
      detected_name="${alias}"
      if [[ "${service_code}" -eq 0 ]]; then
        echo "up|service|${alias}"
        return
      fi
    done
    if [[ "${detected_any}" -eq 1 ]]; then
      echo "down|service|${detected_name}"
      return
    fi
  fi

  for alias in "${aliases[@]}"; do
    if pgrep -x "${alias}" >/dev/null 2>&1; then
      echo "up|pgrep|${alias}"
      return
    fi
  done
  if [[ -n "${pgrep_pattern}" ]] && pgrep -f "${pgrep_pattern}" >/dev/null 2>&1; then
    echo "up|pgrep|${aliases[0]}"
    return
  fi

  echo "unknown|${source}|${aliases[0]}"
}

service_alias_declared() {
  # Return 0 jika alias service terdeteksi/terdaftar di init system.
  # Return 1 jika tidak terdaftar.
  # Jika tidak bisa dipastikan (tanpa systemctl & service), return 0 (safe default).
  local aliases=("$@")
  local alias load_state service_output service_code
  local has_systemctl=0 has_service=0

  if command -v systemctl >/dev/null 2>&1; then
    has_systemctl=1
    for alias in "${aliases[@]}"; do
      load_state="$(systemctl show "${alias}" -p LoadState --value 2>/dev/null || true)"
      if [[ -n "${load_state}" && "${load_state}" != "not-found" ]]; then
        return 0
      fi
    done
  fi

  if command -v service >/dev/null 2>&1; then
    has_service=1
    for alias in "${aliases[@]}"; do
      set +e
      service_output="$(service "${alias}" status 2>&1)"
      service_code=$?
      set -e

      if echo "${service_output}" | grep -Eiq "unrecognized service|not-found|could not be found|unknown service|does not exist"; then
        continue
      fi

      if [[ -n "${service_output}" || "${service_code}" -ge 0 ]]; then
        return 0
      fi
    done
  fi

  if [[ "${has_systemctl}" -eq 0 && "${has_service}" -eq 0 ]]; then
    return 0
  fi

  return 1
}

build_services_json() {
  local panel_profile="$1"
  local rows=()
  local record status source unit group key pgrep_pattern
  local json="["
  local first=1

  add_service() {
    group="$1"
    key="$2"
    pgrep_pattern="$3"
    shift 3
    record="$(detect_service_status "${pgrep_pattern}" "$@")"
    IFS='|' read -r status source unit <<< "${record}"
    rows+=("${group}|${key}|${unit}|${status}|${source}")
  }

  append_imunify_services() {
    local panel="$1"
    local can_fallback=0
    local record status source unit

    case "${panel}" in
      cpanel|plesk|directadmin|cyberpanel) can_fallback=1 ;;
    esac

    record="$(detect_service_status "imunify360|imunify-agent" "imunify360" "imunify-agent")"
    IFS='|' read -r status source unit <<< "${record}"

    if [[ "${status}" == "unknown" ]] && ! service_alias_declared "imunify360" "imunify-agent"; then
      debug_log "skip service imunify360: not declared"

      if [[ "${can_fallback}" -eq 1 ]]; then
        record="$(detect_service_status "imunify-antivirus|imunifyav|imunify-av" "imunify-antivirus" "imunifyav")"
        IFS='|' read -r status source unit <<< "${record}"
        if [[ "${status}" == "unknown" ]] && ! service_alias_declared "imunify-antivirus" "imunifyav"; then
          debug_log "skip service imunifyav: not declared"
          return
        fi
        rows+=("firewall|imunifyav|${unit}|${status}|${source}")
      fi
      return
    fi

    rows+=("firewall|imunify360|${unit}|${status}|${source}")
  }

  case "${panel_profile}" in
    cpanel)
      add_service "webserver" "apache" "httpd|apache2" "httpd" "apache2"
      add_service "webserver" "litespeed" "lsws|lshttpd|openlitespeed|litespeed" "lsws" "lshttpd" "openlitespeed" "litespeed"
      add_service "firewall" "csf" "lfd|csf" "csf" "lfd"
      append_imunify_services "cpanel"
      add_service "database" "mariadb" "mariadbd|mysqld|mariadb" "mariadb" "mysql" "mysqld"
      add_service "ftp" "pureftpd" "pure-ftpd|pureftpd" "pure-ftpd" "pureftpd"
      add_service "mail_access" "dovecot" "dovecot" "dovecot"
      add_service "mail_mta" "exim" "exim|exim4" "exim" "exim4"
      add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"
      add_service "mail_mta" "postfix" "postfix|master" "postfix"
      ;;
    plesk)
      add_service "webserver" "apache" "httpd|apache2" "httpd" "apache2"
      add_service "webserver" "nginx" "nginx" "nginx"
      add_service "database" "mariadb" "mariadbd|mysqld|mariadb" "mariadb" "mysql" "mysqld"
      add_service "mail_mta" "postfix" "postfix|master" "postfix"
      add_service "mail_access" "dovecot" "dovecot" "dovecot"
      add_service "ftp" "pureftpd" "pure-ftpd|pureftpd" "pure-ftpd" "pureftpd"
      add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"
      append_imunify_services "plesk"
      add_service "firewall" "fail2ban" "fail2ban-server|fail2ban" "fail2ban" "fail2ban-server"
      ;;
    directadmin)
      add_service "webserver" "apache" "httpd|apache2" "httpd" "apache2"
      add_service "webserver" "nginx" "nginx" "nginx"
      add_service "webserver" "litespeed" "lsws|lshttpd|openlitespeed|litespeed" "lsws" "lshttpd" "openlitespeed" "litespeed"
      add_service "database" "mariadb" "mariadbd|mysqld|mariadb" "mariadb" "mysql" "mysqld"
      add_service "mail_mta" "exim" "exim|exim4" "exim" "exim4"
      add_service "mail_mta" "postfix" "postfix|master" "postfix"
      add_service "mail_access" "dovecot" "dovecot" "dovecot"
      add_service "ftp" "pureftpd" "pure-ftpd|pureftpd" "pure-ftpd" "pureftpd"
      add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"
      add_service "firewall" "csf" "lfd|csf" "csf" "lfd"
      append_imunify_services "directadmin"
      add_service "firewall" "fail2ban" "fail2ban-server|fail2ban" "fail2ban" "fail2ban-server"
      ;;
    cyberpanel)
      add_service "webserver" "litespeed" "lsws|lshttpd|openlitespeed|litespeed|lscpd" "lsws" "lscpd" "openlitespeed" "litespeed"
      add_service "database" "mariadb" "mariadbd|mysqld|mariadb" "mariadb" "mysql" "mysqld"
      add_service "mail_mta" "postfix" "postfix|master" "postfix"
      add_service "mail_access" "dovecot" "dovecot" "dovecot"
      add_service "ftp" "pureftpd" "pure-ftpd|pureftpd" "pure-ftpd" "pureftpd"
      add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"
      append_imunify_services "cyberpanel"
      add_service "firewall" "fail2ban" "fail2ban-server|fail2ban" "fail2ban" "fail2ban-server"
      ;;
    aapanel)
      add_service "webserver" "apache" "httpd|apache2" "httpd" "apache2"
      add_service "webserver" "nginx" "nginx" "nginx"
      add_service "database" "mariadb" "mariadbd|mysqld|mariadb" "mariadb" "mysql" "mysqld"
      add_service "ftp" "pureftpd" "pure-ftpd|pureftpd" "pure-ftpd" "pureftpd"
      add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"
      add_service "firewall" "fail2ban" "fail2ban-server|fail2ban" "fail2ban" "fail2ban-server"
      ;;
    *)
      add_service "webserver" "apache" "httpd|apache2" "httpd" "apache2"
      add_service "webserver" "nginx" "nginx" "nginx"
      add_service "database" "mariadb" "mariadbd|mysqld|mariadb" "mariadb" "mysql" "mysqld"
      add_service "mail_mta" "postfix" "postfix|master" "postfix"
      add_service "mail_mta" "exim" "exim|exim4" "exim" "exim4"
      add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"
      ;;
  esac

  local row safe_group safe_key safe_unit safe_status safe_source
  for row in "${rows[@]}"; do
    IFS='|' read -r safe_group safe_key safe_unit safe_status safe_source <<< "${row}"
    safe_group="$(json_escape "${safe_group}")"
    safe_key="$(json_escape "${safe_key}")"
    safe_unit="$(json_escape "${safe_unit}")"
    safe_status="$(json_escape "${safe_status}")"
    safe_source="$(json_escape "${safe_source}")"

    if [[ "${first}" -eq 0 ]]; then
      json+=","
    fi
    first=0
    json+=$(cat <<EOF
{"group":"${safe_group}","service_key":"${safe_key}","unit_name":"${safe_unit}","status":"${safe_status}","source":"${safe_source}"}
EOF
)
  done

  json+="]"
  echo "${json}"
}

detect_panel_profile() {
  local forced
  forced="$(echo "${PANEL_PROFILE}" | tr '[:upper:]' '[:lower:]')"
  if [[ "${forced}" != "auto" && "${forced}" != "" ]]; then
    echo "${forced}"
    return
  fi

  if [[ -x /usr/local/cpanel/cpanel ]] || [[ -d /usr/local/cpanel ]]; then
    echo "cpanel"; return
  fi
  if [[ -x /usr/sbin/plesk ]] || [[ -d /opt/psa ]]; then
    echo "plesk"; return
  fi
  if [[ -x /usr/local/directadmin/directadmin ]] || [[ -d /usr/local/directadmin ]]; then
    echo "directadmin"; return
  fi
  if [[ -d /usr/local/CyberCP ]] || pgrep -x lscpd >/dev/null 2>&1; then
    echo "cyberpanel"; return
  fi
  if [[ -d /www/server/panel ]] || pgrep -f BT-Panel >/dev/null 2>&1; then
    echo "aapanel"; return
  fi

  echo "generic"
}

collect_uptime() {
  awk '{print int($1)}' /proc/uptime
}

collect_ram() {
  free -b | awk '/^Mem:/ {print $2" "$3}'
}

collect_disk() {
  df -B1 / | awk 'NR==2 {print $2" "$3}'
}

collect_cpu_load() {
  awk '{print $1}' /proc/loadavg
}

collect_network_totals() {
  local rx tx
  read -r rx tx < <(
    awk '
      NR <= 2 { next }
      {
        iface = $1
        sub(/:$/, "", iface)
        if (iface == "" || iface == "lo") {
          next
        }
        rx += $2
        tx += $10
      }
      END { printf "%.0f %.0f\n", rx + 0, tx + 0 }
    ' /proc/net/dev 2>/dev/null
  )
  echo "${rx:-0} ${tx:-0}"
}

calculate_network_bps() {
  local now_ts current_rx current_tx prev_ts prev_rx prev_tx delta_s delta_rx delta_tx in_bps out_bps
  now_ts="$(date +%s)"
  read -r current_rx current_tx < <(collect_network_totals)

  if [[ -f "${NET_STATE_FILE}" ]]; then
    read -r prev_ts prev_rx prev_tx < "${NET_STATE_FILE}" || true
  else
    prev_ts=0
    prev_rx=0
    prev_tx=0
  fi

  in_bps=0
  out_bps=0
  if [[ "${prev_ts:-0}" -gt 0 && "${now_ts}" -gt "${prev_ts}" && "${prev_rx:-0}" -gt 0 && "${prev_tx:-0}" -gt 0 ]]; then
    delta_s=$((now_ts - prev_ts))
    delta_rx=$((current_rx - prev_rx))
    delta_tx=$((current_tx - prev_tx))

    if [[ "${delta_rx}" -lt 0 ]]; then delta_rx=0; fi
    if [[ "${delta_tx}" -lt 0 ]]; then delta_tx=0; fi
    in_bps=$(((delta_rx * 8) / delta_s))
    out_bps=$(((delta_tx * 8) / delta_s))
  fi

  printf "%s %s %s\n" "${now_ts}" "${current_rx}" "${current_tx}" > "${NET_STATE_FILE}"
  echo "${in_bps} ${out_bps}"
}

main() {
  if [[ -z "$SERVER_TOKEN" || "$SERVER_TOKEN" == "CHANGE_ME_TOKEN" ]]; then
    log "ERROR: SERVER_TOKEN belum dikonfigurasi"
    exit 1
  fi
  acquire_lock

  local uptime
  local ram_total ram_used
  local hdd_total hdd_used
  local cpu_load
  local network_in_bps network_out_bps
  local mta queue_total
  local panel_profile
  local services_json

  uptime="$(collect_uptime)"
  read -r ram_total ram_used < <(collect_ram)
  read -r hdd_total hdd_used < <(collect_disk)
  cpu_load="$(collect_cpu_load)"
  read -r network_in_bps network_out_bps < <(calculate_network_bps)
  panel_profile="$(detect_panel_profile)"
  mta="$(detect_mta)"
  queue_total="$(read_queue_total "$mta")"
  services_json="$(build_services_json "${panel_profile}")"

  local payload
  payload=$(cat <<EOF
{
  "server_id": ${SERVER_ID},
  "uptime": ${uptime},
  "ram_total": ${ram_total},
  "ram_used": ${ram_used},
  "hdd_total": ${hdd_total},
  "hdd_used": ${hdd_used},
  "cpu_load": ${cpu_load},
  "network": {
    "in_bps": ${network_in_bps},
    "out_bps": ${network_out_bps}
  },
  "mail": {
    "mta": "${mta}",
    "queue_total": ${queue_total}
  },
  "panel_profile": "${panel_profile}",
  "services": ${services_json}
}
EOF
)

  local response http_code response_body response_body_one_line
  local sign_headers=()
  if [[ "${SIGN_REQUESTS}" == "1" ]] && command -v openssl >/dev/null 2>&1; then
    local request_ts request_sig
    request_ts="$(date +%s)"
    request_sig="$(printf '%s.%s' "${request_ts}" "${payload}" | openssl dgst -sha256 -hmac "${SERVER_TOKEN}" | awk '{print $2}')"
    if [[ "${request_sig}" =~ ^[a-fA-F0-9]{64}$ ]]; then
      sign_headers=(-H "X-Server-Timestamp: ${request_ts}" -H "X-Server-Signature: ${request_sig}")
    fi
  fi

  response=$(curl -sS -m "${CURL_TIMEOUT}" -H "Content-Type: application/json" -H "X-Server-Token: ${SERVER_TOKEN}" "${sign_headers[@]}" -X POST -d "${payload}" -w "\n%{http_code}" "${MASTER_URL}" || true)
  http_code=$(echo "${response}" | tail -n1)
  response_body="$(echo "${response}" | sed '$d')"
  response_body_one_line="$(echo "${response_body}" | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | sed 's/^ //; s/ $//')"

  if [[ "${http_code}" != "200" ]]; then
    log "ERROR push failed code=${http_code} body=${response_body_one_line}"
    exit 1
  fi

  log "OK push success server_id=${SERVER_ID} panel=${panel_profile} mta=${mta} queue=${queue_total}"
}

main "$@"
