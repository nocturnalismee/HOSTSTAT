#!/usr/bin/env bash
# ─────────────────────────────────────────────
#  SERVSTATS - Bash Push Agent
#  Version: 2.0
#  Created by: Arief Efriyan
#  Description: Push server metrics to web monitor API.
# ─────────────────────────────────────────────
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
NET_STATE_FILE="${NET_STATE_FILE:-/tmp/servmon-agent-net.state}"
LOCK_FILE="${LOCK_FILE:-/tmp/servmon-agent.lock}"
LOCK_WAIT_SECONDS="${LOCK_WAIT_SECONDS:-0}"
PANEL_PROFILE="${PANEL_PROFILE:-auto}"
DEBUG_PAYLOAD="${DEBUG_PAYLOAD:-0}"

SIGN_REQUESTS="${SIGN_REQUESTS:-0}"
# 0 = kirim biasa, header X-Server-Token saja
# 1 = tambahkan X-Server-Timestamp + X-Server-Signature (HMAC-SHA256)

MAIL_QUEUE_SPOOL_FALLBACK="${MAIL_QUEUE_SPOOL_FALLBACK:-0}"
# 0 = nonaktifkan fallback hitung spool via find
# 1 = aktifkan fallback via find jika command utama tidak tersedia


# ─────────────────────────────────────────────
#  SERVICE REGISTRY
#  Format per entry: "group|key|pgrep_pattern|alias1 alias2 ..."
#  - group        : kategori service (webserver, database, dll)
#  - key          : nama unik service
#  - pgrep_pattern: pattern untuk pgrep -f (gunakan | sebagai OR)
#  - alias1 ...   : nama unit systemctl/service untuk dicoba (spasi sebagai pemisah)
# ─────────────────────────────────────────────
declare -A SERVICE_REGISTRY
SERVICE_REGISTRY["apache"]="webserver|apache|httpd|httpd apache2"
SERVICE_REGISTRY["nginx"]="webserver|nginx|nginx|nginx"
SERVICE_REGISTRY["litespeed"]="webserver|litespeed|lsws|lsws lscpd openlitespeed"
SERVICE_REGISTRY["mariadb"]="database|mariadb|mariadbd|mariadb mysql"
SERVICE_REGISTRY["postfix"]="mail_mta|postfix|postfix|postfix"
SERVICE_REGISTRY["exim"]="mail_mta|exim|exim4|exim4 exim"
SERVICE_REGISTRY["dovecot"]="mail_access|dovecot|dovecot|dovecot"
SERVICE_REGISTRY["pureftpd"]="ftp|pureftpd|pure-ftpd|pure-ftpd pureftpd"
SERVICE_REGISTRY["sshd"]="ssh|sshd|sshd|sshd ssh"
SERVICE_REGISTRY["csf"]="firewall|csf|lfd|csf lfd"
SERVICE_REGISTRY["fail2ban"]="firewall|fail2ban|fail2ban|fail2ban fail2ban-server"
SERVICE_REGISTRY["imunify360"]="firewall|imunify360|imunify360|imunify360 imunify-agent"
SERVICE_REGISTRY["imunifyav"]="firewall|imunifyav|imunify-antivirus|imunify-antivirus imunifyav"

# ─────────────────────────────────────────────
#  PANEL SERVICE MAP
#  Daftar key service yang dipakai per panel (spasi sebagai pemisah)
#  Urutan menentukan urutan output JSON
# ─────────────────────────────────────────────
declare -A PANEL_SERVICES
PANEL_SERVICES["cpanel"]="apache litespeed csf imunify360 mariadb pureftpd dovecot exim sshd"
PANEL_SERVICES["plesk"]="apache litespeed mariadb postfix dovecot pureftpd sshd imunify360 fail2ban"
PANEL_SERVICES["directadmin"]="apache nginx litespeed mariadb exim postfix dovecot pureftpd sshd csf imunify360 fail2ban"
PANEL_SERVICES["cyberpanel"]="litespeed mariadb postfix dovecot pureftpd sshd imunify360 fail2ban"
PANEL_SERVICES["aapanel"]="apache nginx mariadb pureftpd sshd fail2ban"
PANEL_SERVICES["generic"]="apache nginx mariadb postfix exim sshd"

# imunify360 & imunifyav: jika imunify360 tidak terdeteksi (unknown), fallback cek imunifyav
# Ditangani di build_services_json() dengan logika khusus
IMUNIFY_FALLBACK_PANELS="cpanel plesk directadmin cyberpanel"


# ─────────────────────────────────────────────
#  UTILITIES
# ─────────────────────────────────────────────
log() {
  [[ -z "${LOG_FILE}" ]] && return 0
  printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "${LOG_FILE}" 2>/dev/null || true
}

debug_log() {
  [[ "${DEBUG_PAYLOAD}" != "1" ]] && return 0
  log "DEBUG: $1"
  printf '%s\n' "DEBUG: $1" >&2
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
  # Kembalikan nilai jika numerik, fallback ke $2 (default 0)
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
#  PANEL DETECTION
# ─────────────────────────────────────────────
detect_panel_profile() {
  local forced
  forced="$(echo "${PANEL_PROFILE}" | tr '[:upper:]' '[:lower:]')"
  [[ "${forced}" != "auto" && -n "${forced}" ]] && { echo "${forced}"; return; }

  [[ -x /usr/local/cpanel/cpanel || -d /usr/local/cpanel ]]         && { echo "cpanel";      return; }
  [[ -x /usr/sbin/plesk          || -d /opt/psa ]]                   && { echo "plesk";       return; }
  [[ -x /usr/local/directadmin/directadmin || -d /usr/local/directadmin ]] && { echo "directadmin"; return; }
  [[ -d /usr/local/CyberCP ]] || pgrep -x lscpd >/dev/null 2>&1     && { echo "cyberpanel";  return; }
  [[ -d /www/server/panel ]]  || pgrep -f BT-Panel >/dev/null 2>&1  && { echo "aapanel";     return; }

  echo "generic"
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

service_alias_declared() {
  # Return 0 jika alias service terdeteksi/terdaftar di init system.
  # Return 1 jika tidak terdaftar.
  # Jika tidak bisa dipastikan (tanpa systemctl & service), return 0 (safe default).
  local aliases=("$@")
  local alias load_state svc_out svc_code
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
      svc_out="$(service "${alias}" status 2>&1)"
      svc_code=$?
      set -e

      if echo "${svc_out}" | grep -Eiq "unrecognized|not-found|could not be found|unknown service|does not exist"; then
        continue
      fi

      # Command/service dikenal, anggap service declared.
      if [[ -n "${svc_out}" || "${svc_code}" -ge 0 ]]; then
        return 0
      fi
    done
  fi

  if [[ "${has_systemctl}" -eq 0 && "${has_service}" -eq 0 ]]; then
    return 0
  fi

  return 1
}


# ─────────────────────────────────────────────
#  BUILD SERVICES JSON
# ─────────────────────────────────────────────
build_services_json() {
  local panel="$1"
  local service_keys="${PANEL_SERVICES[${panel}]:-${PANEL_SERVICES[generic]}}"
  local rows=() json="[" first=1
  local key entry group svc_key pgrep_pat aliases_str
  local record status source unit
  local safe_group safe_key safe_unit safe_status safe_source

  append_row() {
    # Args: key (dari SERVICE_REGISTRY)
    local k="$1"
    local reg="${SERVICE_REGISTRY[$k]:-}"
    [[ -z "${reg}" ]] && { log "WARN: service key '${k}' tidak ada di registry"; return; }

    IFS='|' read -r group svc_key pgrep_pat aliases_str <<< "${reg}"
    read -ra aliases_arr <<< "${aliases_str}"

    record="$(detect_service_status "${pgrep_pat}" "${aliases_arr[@]}")"
    IFS='|' read -r status source unit <<< "${record}"
    rows+=("${group}|${svc_key}|${unit}|${status}|${source}")
  }

  append_imunify_services() {
    local can_fallback=0
    local reg360 pgrep360 aliases360_str
    local regav pgrepav aliasesav_str
    local last_row last_status

    if echo "${IMUNIFY_FALLBACK_PANELS}" | grep -qw "${panel}"; then
      can_fallback=1
    fi

    reg360="${SERVICE_REGISTRY[imunify360]:-}"
    IFS='|' read -r _ _ pgrep360 aliases360_str <<< "${reg360}"
    read -ra aliases360_arr <<< "${aliases360_str}"

    append_row "imunify360"
    last_row="${rows[-1]:-}"
    IFS='|' read -r _ _ _ last_status _ <<< "${last_row}"
    if [[ "${last_status}" != "unknown" ]]; then
      return
    fi

    if service_alias_declared "${aliases360_arr[@]}"; then
      return
    fi

    # imunify360 unknown dan tidak terpasang -> drop row
    rows=("${rows[@]:0:${#rows[@]}-1}")
    debug_log "skip service imunify360: not declared"

    if [[ "${can_fallback}" -eq 0 ]]; then
      return
    fi

    regav="${SERVICE_REGISTRY[imunifyav]:-}"
    IFS='|' read -r _ _ pgrepav aliasesav_str <<< "${regav}"
    read -ra aliasesav_arr <<< "${aliasesav_str}"

    append_row "imunifyav"
    last_row="${rows[-1]:-}"
    IFS='|' read -r _ _ _ last_status _ <<< "${last_row}"
    if [[ "${last_status}" == "unknown" ]] && ! service_alias_declared "${aliasesav_arr[@]}"; then
      rows=("${rows[@]:0:${#rows[@]}-1}")
      debug_log "skip service imunifyav: not declared"
    fi
  }

  for key in ${service_keys}; do
    if [[ "${key}" == "imunify360" ]]; then
      append_imunify_services
      continue
    fi
    append_row "${key}"
  done

  # Serialize ke JSON
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
    json+="{\"group\":\"${safe_group}\",\"service_key\":\"${safe_key}\",\"unit_name\":\"${safe_unit}\",\"status\":\"${safe_status}\",\"source\":\"${safe_source}\"}"
  done

  json+="]"
  echo "${json}"
}

summarize_services_json() {
  # Return format: total|up|down|unknown|non_up_csv
  local services_json="${1:-[]}"
  local compact obj key status
  local total=0 up=0 down=0 unknown=0
  local non_up_csv=""
  local -a non_up_down=() non_up_unknown=()

  compact="$(printf '%s' "${services_json}" | tr -d '\n\r')"
  compact="${compact#[}"
  compact="${compact%]}"
  [[ -z "${compact// }" ]] && { echo "0|0|0|0|"; return; }

  while IFS= read -r obj; do
    [[ -z "${obj}" ]] && continue
    key="$(printf '%s' "${obj}" | sed -n 's/.*"service_key":"\([^"]*\)".*/\1/p')"
    status="$(printf '%s' "${obj}" | sed -n 's/.*"status":"\([^"]*\)".*/\1/p')"
    [[ -z "${key}" ]] && key="-"
    [[ -z "${status}" ]] && status="unknown"

    total=$((total + 1))
    case "${status}" in
      up)
        up=$((up + 1))
        ;;
      down)
        down=$((down + 1))
        non_up_down+=("${key}")
        ;;
      unknown)
        unknown=$((unknown + 1))
        non_up_unknown+=("${key}")
        ;;
      *)
        unknown=$((unknown + 1))
        non_up_unknown+=("${key}")
        ;;
    esac
  done < <(printf '%s' "${compact}" | sed 's/},{/}\n{/g')

  local item
  for item in "${non_up_down[@]}"; do
    [[ -n "${non_up_csv}" ]] && non_up_csv+=","
    non_up_csv+="${item}"
  done
  for item in "${non_up_unknown[@]}"; do
    [[ -n "${non_up_csv}" ]] && non_up_csv+=","
    non_up_csv+="${item}"
  done

  echo "${total}|${up}|${down}|${unknown}|${non_up_csv}"
}


# ─────────────────────────────────────────────
#  MAIL QUEUE
# ─────────────────────────────────────────────
detect_mta() {
  local postfix_bin exim_bin
  postfix_bin="$(resolve_cmd postfix /usr/sbin/postfix || true)"
  exim_bin="$(resolve_cmd exim4 exim /usr/sbin/exim4 /usr/sbin/exim || true)"

  [[ -n "${postfix_bin}" ]] && (pgrep -x master >/dev/null 2>&1 || [[ -d /var/spool/postfix ]]) && { echo "postfix"; return; }
  [[ -n "${exim_bin}" || -d /var/spool/exim4 || -d /var/spool/exim ]]                           && { echo "exim";    return; }
  [[ -d /var/qmail ]] || pgrep -x qmail-send >/dev/null 2>&1                                    && { echo "qmail";   return; }
  echo "none"
}

read_queue_total() {
  local mta="$1" total=0
  local bin

  case "${mta}" in
    postfix)
      bin="$(resolve_cmd postqueue /usr/sbin/postqueue || true)"
      if [[ -n "${bin}" ]]; then
        total=$("${bin}" -p 2>/dev/null | grep -cE '^[A-F0-9]{10,}' || true)
      elif [[ "${MAIL_QUEUE_SPOOL_FALLBACK}" == "1" ]]; then
        total=$(find /var/spool/postfix/active /var/spool/postfix/deferred \
                     /var/spool/postfix/hold /var/spool/postfix/incoming \
                     -type f 2>/dev/null | wc -l | tr -d ' ')
      fi
      ;;
    exim)
      bin="$(resolve_cmd exim4 exim /usr/sbin/exim4 /usr/sbin/exim || true)"
      if [[ -n "${bin}" ]]; then
        total=$("${bin}" -bpc 2>/dev/null || echo 0)
      elif [[ "${MAIL_QUEUE_SPOOL_FALLBACK}" == "1" ]]; then
        total=$(find /var/spool/exim4/input /var/spool/exim/input \
                     -name '*-H' -type f 2>/dev/null | wc -l | tr -d ' ')
      fi
      ;;
    qmail)
      bin="$(resolve_cmd qmail-qstat /var/qmail/bin/qmail-qstat || true)"
      if [[ -n "${bin}" ]]; then
        total=$("${bin}" 2>/dev/null | awk '/^messages in queue:/ {print $4}')
      else
        total=$(find /var/qmail/queue/mess -type f 2>/dev/null | wc -l | tr -d ' ')
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
  df -B1 / | awk 'NR==2 {print $2, $3}'
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
    delta_rx=$(( current_rx - prev_rx )); (( delta_rx < 0 )) && delta_rx=0
    delta_tx=$(( current_tx - prev_tx )); (( delta_tx < 0 )) && delta_tx=0
    in_bps=$(( (delta_rx * 8) / delta_s ))
    out_bps=$(( (delta_tx * 8) / delta_s ))
  fi

  # Atomic write: tulis ke tmp dulu lalu rename
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

  # Kumpulkan semua metrik
  local uptime ram_total ram_used hdd_total hdd_used cpu_load
  local network_in_bps network_out_bps
  local panel mta queue_total services_json

  uptime="$(collect_uptime)"
  read -r ram_total ram_used   < <(collect_ram)
  read -r hdd_total hdd_used   < <(collect_disk)
  cpu_load="$(collect_cpu_load)"
  read -r network_in_bps network_out_bps < <(calculate_network_bps)
  panel="$(detect_panel_profile)"
  mta="$(detect_mta)"
  queue_total="$(read_queue_total "${mta}")"
  services_json="$(build_services_json "${panel}")"

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

  local payload payload_bytes
  local svc_total svc_up svc_down svc_unknown svc_nonup_csv svc_nonup_fmt
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
  "panel_profile": "$(json_escape "${panel}")",
  "services": ${services_json}
}
EOF
)
  payload_bytes="$(printf '%s' "${payload}" | wc -c | tr -d ' ')"
  payload_bytes="$(safe_num "${payload_bytes}" 0)"
  IFS='|' read -r svc_total svc_up svc_down svc_unknown svc_nonup_csv <<< "$(summarize_services_json "${services_json}")"
  svc_total="$(safe_num "${svc_total}" 0)"
  svc_up="$(safe_num "${svc_up}" 0)"
  svc_down="$(safe_num "${svc_down}" 0)"
  svc_unknown="$(safe_num "${svc_unknown}" 0)"
  svc_nonup_fmt="[${svc_nonup_csv}]"
  [[ -z "${svc_nonup_csv}" ]] && svc_nonup_fmt="[]"

  debug_log "request payload=${payload}"

  # Optional HMAC signing
  local sign_headers=()
  if [[ "${SIGN_REQUESTS}" == "1" ]] && command -v openssl >/dev/null 2>&1; then
    local req_ts req_sig
    req_ts="$(date +%s)"
    req_sig="$(printf '%s.%s' "${req_ts}" "${payload}" \
      | openssl dgst -sha256 -hmac "${SERVER_TOKEN}" | awk '{print $2}')"
    if [[ "${req_sig}" =~ ^[a-fA-F0-9]{64}$ ]]; then
      sign_headers=(
        -H "X-Server-Timestamp: ${req_ts}"
        -H "X-Server-Signature: ${req_sig}"
      )
    fi
  fi

  # Kirim payload
  local response http_code response_body
  response=$(curl -sS \
    --connect-timeout 5 \
    -m "${CURL_TIMEOUT}" \
    -H "Content-Type: application/json" \
    -H "X-Server-Token: ${SERVER_TOKEN}" \
    "${sign_headers[@]}" \
    -X POST -d "${payload}" \
    -w "\n%{http_code}" \
    "${MASTER_URL}" || true)

  http_code="$(echo "${response}" | tail -n1)"
  response_body="$(echo "${response}" | sed '$d' | tr '\n' ' ' | sed 's/  */ /g;s/^ //;s/ $//')"
  debug_log "response code=${http_code} body=${response_body}"

  if [[ "${http_code}" != "200" ]]; then
    log "ERROR push failed code=${http_code} body=${response_body}"
    exit 1
  fi

  # Success log tetap 1 baris key-value agar mudah di-grep/parse.
  log "OK push success server_id=${SERVER_ID} panel=${panel} mta=${mta} queue=${queue_total} http_code=${http_code} payload_bytes=${payload_bytes} services=${svc_total} up=${svc_up} down=${svc_down} unknown=${svc_unknown} non_up=${svc_nonup_fmt}"
}

main "$@"
