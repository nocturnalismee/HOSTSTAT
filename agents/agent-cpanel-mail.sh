#!/usr/bin/env bash
# ============================================================
#  SERVSTATS - cPanel Email Host Agent (push mode)
#  Version: 2.0
#  Created by: Arief Efriyan
#  Description: Monitor service email, firewall & SSH pada
#               server cPanel khusus email, lalu push ke API.
# ============================================================
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

# ─────────────────────────────────────────────
#  CONFIGURATION
# ─────────────────────────────────────────────
MASTER_URL="${MASTER_URL:-https://status.yourdomain.com/api/push.php}"
SERVER_TOKEN="${SERVER_TOKEN:-CHANGE_ME_TOKEN}"
SERVER_ID="${SERVER_ID:-1}"
CURL_TIMEOUT="${CURL_TIMEOUT:-10}"
LOG_FILE="${LOG_FILE:-}"
NET_STATE_FILE="${NET_STATE_FILE:-/tmp/servmon-agent-email-net.state}"
LOCK_FILE="${LOCK_FILE:-/tmp/servmon-agent-email.lock}"
LOCK_WAIT_SECONDS="${LOCK_WAIT_SECONDS:-0}"
PANEL_PROFILE="${PANEL_PROFILE:-cpanel_email}"

MAIL_QUEUE_SPOOL_FALLBACK="${MAIL_QUEUE_SPOOL_FALLBACK:-0}"
# 0 = nonaktifkan fallback hitung spool via find
# 1 = aktifkan fallback via find jika command utama tidak tersedia


# ─────────────────────────────────────────────
#  SERVICE REGISTRY
#  Format: "group|key|pgrep_pattern|alias1 alias2 ..."
#  Tambah/edit service cukup di sini saja.
# ─────────────────────────────────────────────
declare -A SERVICE_REGISTRY
SERVICE_REGISTRY["exim"]="mail_mta|exim|exim|exim4|exim4 exim"
SERVICE_REGISTRY["dovecot"]="mail_access|dovecot|dovecot|dovecot|dovecot cpanel-dovecot-solr"
SERVICE_REGISTRY["mailman"]="mail_service|mailman|mailman|mailman3|mailman mailman3 mailman-core cpanel-mailman"
SERVICE_REGISTRY["csf"]="firewall|csf|lfd|csf|csf lfd"
SERVICE_REGISTRY["clamd"]="firewall|clamd|clamd|clamd|clamd clamd@scan clamav-daemon clamd-wrapper"
SERVICE_REGISTRY["spamd"]="firewall|spamd|spamd|spamd|spamd spamassassin spamd-wrapper"
SERVICE_REGISTRY["sshd"]="ssh|sshd|sshd|sshd|sshd ssh"

# Urutan service yang akan di-monitor (sesuaikan jika perlu)
MONITOR_SERVICES="exim dovecot mailman csf clamd spamd sshd"


# ─────────────────────────────────────────────
#  UTILITIES
# ─────────────────────────────────────────────
log() {
  [[ -z "${LOG_FILE}" ]] && return 0
  printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "${LOG_FILE}" 2>/dev/null || true
}

die() {
  log "ERROR: $1"
  echo "ERROR: $1" >&2
  exit 1
}

json_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

is_numeric() {
  [[ "${1:-}" =~ ^-?[0-9]+(\.[0-9]+)?$ ]]
}

safe_num() {
  local val="${1:-}" fallback="${2:-0}"
  is_numeric "${val}" && echo "${val}" || echo "${fallback}"
}

resolve_cmd() {
  local candidate
  for candidate in "$@"; do
    if [[ "${candidate}" == */* ]]; then
      [[ -x "${candidate}" ]] && { echo "${candidate}"; return 0; }
      continue
    fi
    command -v "${candidate}" >/dev/null 2>&1 && { command -v "${candidate}"; return 0; }
  done
  return 1
}

acquire_lock() {
  local flock_bin
  flock_bin="$(resolve_cmd flock /usr/bin/flock /bin/flock || true)"
  [[ -z "${flock_bin}" ]] && return 0
  exec 9>"${LOCK_FILE}"
  "${flock_bin}" -w "${LOCK_WAIT_SECONDS}" 9 || { log "WARN: lock sedang dipakai, skip run ini"; exit 0; }
}


# ─────────────────────────────────────────────
#  SERVICE STATUS DETECTION
# ─────────────────────────────────────────────
detect_service_status() {
  # Args: pgrep_pattern alias1 alias2 ...
  local pgrep_pattern="$1"; shift
  local aliases=("$@")
  local alias load_state svc_out svc_code detected_any=0 detected_name=""

  # 1) systemctl
  if command -v systemctl >/dev/null 2>&1; then
    for alias in "${aliases[@]}"; do
      load_state="$(systemctl show "${alias}" -p LoadState --value 2>/dev/null || true)"
      [[ -z "${load_state}" || "${load_state}" == "not-found" ]] && continue
      detected_any=1; detected_name="${alias}"
      systemctl is-active --quiet "${alias}" 2>/dev/null && { echo "up|systemctl|${alias}"; return; }
    done
    [[ "${detected_any}" -eq 1 ]] && { echo "down|systemctl|${detected_name}"; return; }
  fi

  # 2) service
  if command -v service >/dev/null 2>&1; then
    detected_any=0; detected_name=""
    for alias in "${aliases[@]}"; do
      set +e; svc_out="$(service "${alias}" status 2>&1)"; svc_code=$?; set -e
      echo "${svc_out}" | grep -Eiq "unrecognized|not-found|could not be found|unknown service|does not exist" && continue
      detected_any=1; detected_name="${alias}"
      [[ "${svc_code}" -eq 0 ]] && { echo "up|service|${alias}"; return; }
    done
    [[ "${detected_any}" -eq 1 ]] && { echo "down|service|${detected_name}"; return; }
  fi

  # 3) pgrep fallback
  for alias in "${aliases[@]}"; do
    pgrep -x "${alias}" >/dev/null 2>&1 && { echo "up|pgrep|${alias}"; return; }
  done
  [[ -n "${pgrep_pattern}" ]] && pgrep -f "${pgrep_pattern}" >/dev/null 2>&1 && { echo "up|pgrep|${aliases[0]}"; return; }

  echo "unknown|pgrep|${aliases[0]}"
}


# ─────────────────────────────────────────────
#  BUILD SERVICES JSON
# ─────────────────────────────────────────────
build_services_json() {
  local rows=() json="[" first=1
  local key reg group svc_key pgrep_pat aliases_str
  local record status source unit
  local safe_group safe_key safe_unit safe_status safe_source

  for key in ${MONITOR_SERVICES}; do
    reg="${SERVICE_REGISTRY[${key}]:-}"
    if [[ -z "${reg}" ]]; then
      log "WARN: service key '${key}' tidak ada di registry, dilewati"
      continue
    fi

    IFS='|' read -r group svc_key pgrep_pat aliases_str <<< "${reg}"
    read -ra aliases_arr <<< "${aliases_str}"

    record="$(detect_service_status "${pgrep_pat}" "${aliases_arr[@]}")"
    IFS='|' read -r status source unit <<< "${record}"
    rows+=("${group}|${svc_key}|${unit}|${status}|${source}")
  done

  local row
  for row in "${rows[@]}"; do
    IFS='|' read -r safe_group safe_key safe_unit safe_status safe_source <<< "${row}"
    safe_group="$(json_escape "${safe_group}")"
    safe_key="$(json_escape "${safe_key}")"
    safe_unit="$(json_escape "${safe_unit}")"
    safe_status="$(json_escape "${safe_status}")"
    safe_source="$(json_escape "${safe_source}")"

    [[ "${first}" -eq 0 ]] && json+=","
    first=0
    
    safe_group="$(echo "${safe_group}" | tr -dc '[:print:]')"
    safe_key="$(echo "${safe_key}" | tr -dc '[:print:]')"
    safe_unit="$(echo "${safe_unit}" | tr -dc '[:print:]')"
    safe_source="$(echo "${safe_source}" | tr -dc '[:print:]')"
    
    json+="{\"group\":\"${safe_group}\",\"service_key\":\"${safe_key}\",\"unit_name\":\"${safe_unit}\",\"status\":\"${safe_status}\",\"source\":\"${safe_source}\"}"
  done

  json+="]"
  echo "${json}"
}


# ─────────────────────────────────────────────
#  MAIL QUEUE
# ─────────────────────────────────────────────
detect_mta() {
  local exim_bin postfix_bin
  exim_bin="$(resolve_cmd exim4 exim /usr/sbin/exim4 /usr/sbin/exim || true)"
  postfix_bin="$(resolve_cmd postfix /usr/sbin/postfix || true)"

  # cPanel umumnya pakai exim, prioritaskan
  [[ -n "${exim_bin}" ]] && { echo "exim"; return; }
  [[ -n "${postfix_bin}" ]] && (pgrep -x master >/dev/null 2>&1 || [[ -d /var/spool/postfix ]]) \
    && { echo "postfix"; return; }
  echo "none"
}

read_queue_total() {
  local mta="$1" total=0 bin

  case "${mta}" in
    exim)
      bin="$(resolve_cmd exim4 exim /usr/sbin/exim4 /usr/sbin/exim || true)"
      if [[ -n "${bin}" ]]; then
        total=$(timeout 5s "${bin}" -bpc 2>/dev/null || echo 0)
      elif [[ "${MAIL_QUEUE_SPOOL_FALLBACK}" == "1" ]]; then
        total=$(find /var/spool/exim4/input /var/spool/exim/input \
                     -name '*-H' -type f 2>/dev/null | wc -l | tr -d ' ')
      fi
      ;;
    postfix)
      bin="$(resolve_cmd postqueue /usr/sbin/postqueue || true)"
      if [[ -n "${bin}" ]]; then
        total=$(timeout 5s "${bin}" -p 2>/dev/null | grep -cE '^[A-F0-9]{10,}' || true)
      elif [[ "${MAIL_QUEUE_SPOOL_FALLBACK}" == "1" ]]; then
        total=$(find /var/spool/postfix/active /var/spool/postfix/deferred \
                     /var/spool/postfix/hold /var/spool/postfix/incoming \
                     -type f 2>/dev/null | wc -l | tr -d ' ')
      fi
      ;;
  esac

  safe_num "${total}" 0
}


# ─────────────────────────────────────────────
#  METRICS COLLECTORS
# ─────────────────────────────────────────────
collect_uptime() {
  safe_num "$(awk '{print int($1)}' /proc/uptime)" 0
}

collect_ram() {
  free -b | awk '/^Mem:/ {print $2, $3}'
}

collect_disk() {
  df -PB1 / | awk 'NR==2 {print $2, $3}'
}

collect_cpu_load() {
  safe_num "$(awk '{print $1}' /proc/loadavg)" "0.00"
}

collect_network_totals() {
  awk '
    NR <= 2 { next }
    {
      iface = $1; sub(/:$/, "", iface)
      if (iface == "" || iface == "lo") next
      rx += $2; tx += $10
    }
    END { printf "%.0f %.0f\n", rx+0, tx+0 }
  ' /proc/net/dev 2>/dev/null || echo "0 0"
}

calculate_network_bps() {
  local now_ts current_rx current_tx
  local prev_ts=0 prev_rx=0 prev_tx=0
  local delta_s delta_rx delta_tx in_bps=0 out_bps=0

  now_ts="$(date +%s)"
  read -r current_rx current_tx < <(collect_network_totals)

  if [[ -f "${NET_STATE_FILE}" ]]; then
    read -r prev_ts prev_rx prev_tx < "${NET_STATE_FILE}" 2>/dev/null || true
  fi

  if (( ${prev_ts:-0} > 0 && now_ts > prev_ts && ${prev_rx:-0} > 0 )); then
    delta_s=$(( now_ts - prev_ts ))
    if (( delta_s >= 1 )); then
      delta_rx=$(( current_rx - prev_rx )); (( delta_rx < 0 )) && delta_rx=0
      delta_tx=$(( current_tx - prev_tx )); (( delta_tx < 0 )) && delta_tx=0
      in_bps=$(( (delta_rx * 8) / delta_s ))
      out_bps=$(( (delta_tx * 8) / delta_s ))
    fi
  elif (( current_rx < prev_rx || now_ts < prev_ts )); then
    > "${NET_STATE_FILE}"
  fi

  # Atomic write
  printf "%s %s %s\n" "${now_ts}" "${current_rx}" "${current_tx}" > "${NET_STATE_FILE}.tmp" \
    && mv "${NET_STATE_FILE}.tmp" "${NET_STATE_FILE}" || true

  echo "${in_bps} ${out_bps}"
}


# ─────────────────────────────────────────────
#  MAIN
# ─────────────────────────────────────────────
main() {
  [[ -z "${SERVER_TOKEN}" || "${SERVER_TOKEN}" == "CHANGE_ME_TOKEN" ]] \
    && die "SERVER_TOKEN belum dikonfigurasi"

  acquire_lock

  local uptime ram_total ram_used hdd_total hdd_used cpu_load
  local network_in_bps network_out_bps
  local mta queue_total services_json

  uptime="$(collect_uptime)"
  read -r ram_total ram_used   < <(collect_ram)
  read -r hdd_total hdd_used   < <(collect_disk)
  cpu_load="$(collect_cpu_load)"
  read -r network_in_bps network_out_bps < <(calculate_network_bps)
  mta="$(detect_mta)"
  queue_total="$(read_queue_total "${mta}")"
  services_json="$(build_services_json)"

  # Sanitasi nilai numerik sebelum masuk payload
  uptime="$(safe_num "${uptime}" 0)"
  ram_total="$(safe_num "${ram_total}" 0)"
  ram_used="$(safe_num "${ram_used}" 0)"
  hdd_total="$(safe_num "${hdd_total}" 0)"
  hdd_used="$(safe_num "${hdd_used}" 0)"
  cpu_load="$(safe_num "${cpu_load}" "0.00")"
  network_in_bps="$(safe_num "${network_in_bps}" 0)"
  network_out_bps="$(safe_num "${network_out_bps}" 0)"
  queue_total="$(safe_num "${queue_total}" 0)"

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
    "mta": "$(json_escape "${mta}")",
    "queue_total": ${queue_total}
  },
  "panel_profile": "$(json_escape "${PANEL_PROFILE}")",
  "services": ${services_json}
}
EOF
)

  local response http_code response_body
  local c_timeout=$(( CURL_TIMEOUT / 2 ))
  (( c_timeout < 5 )) && c_timeout=5

  response=$(curl -sS \
    --connect-timeout ${c_timeout} \
    -m "${CURL_TIMEOUT}" \
    -H "Content-Type: application/json" \
    -H "X-Server-Token: ${SERVER_TOKEN}" \
    -X POST -d "${payload}" \
    -w "\n%{http_code}" \
    "${MASTER_URL}" || true)

  http_code="$(echo "${response}" | tail -n1)"
  response_body="$(echo "${response}" | sed '$d' | tr '\n' ' ' | sed 's/  */ /g;s/^ //;s/ $//')"

  if [[ "${http_code}" != "200" ]]; then
    log "ERROR push failed code=${http_code} body=${response_body}"
    exit 1
  fi

  log "OK push success server_id=${SERVER_ID} panel=${PANEL_PROFILE} mta=${mta} queue=${queue_total}"
}

main "$@"