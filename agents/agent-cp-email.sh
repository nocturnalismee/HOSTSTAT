#!/usr/bin/env bash
set -euo pipefail

# CPanel Email Host Agent (push mode)
# Fokus monitor service email + firewall + ssh.

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"

MASTER_URL="${MASTER_URL:-https://status.yourdomain.com/api/push.php}"
SERVER_TOKEN="${SERVER_TOKEN:-CHANGE_ME_TOKEN}"
SERVER_ID="${SERVER_ID:-1}"
CURL_TIMEOUT="${CURL_TIMEOUT:-10}"
LOG_FILE="${LOG_FILE:-}"
NET_STATE_FILE="${NET_STATE_FILE:-/tmp/servmon-agent-email-net.state}"
LOCK_FILE="${LOCK_FILE:-/tmp/servmon-agent-email.lock}"
LOCK_WAIT_SECONDS="${LOCK_WAIT_SECONDS:-0}"
MAIL_QUEUE_SPOOL_FALLBACK="${MAIL_QUEUE_SPOOL_FALLBACK:-0}"
PANEL_PROFILE="${PANEL_PROFILE:-cpanel_email}"

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

  if [[ -n "${exim_bin}" ]]; then
    echo "exim"; return
  fi
  if [[ -n "${postfix_bin}" ]] && (pgrep -x master >/dev/null 2>&1 || [[ -d /var/spool/postfix ]]); then
    echo "postfix"; return
  fi
  echo "none"
}

read_queue_total() {
  local mta="$1"
  local total=0
  local postqueue_bin exim_bin

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

  echo "unknown|pgrep|${aliases[0]}"
}

detect_panel_profile() {
  local forced
  forced="$(echo "${PANEL_PROFILE}" | tr '[:upper:]' '[:lower:]')"
  if [[ "${forced}" != "auto" && "${forced}" != "" ]]; then
    echo "${forced}"
    return
  fi

  if [[ -x /usr/local/cpanel/cpanel ]] || [[ -d /usr/local/cpanel ]]; then
    echo "cpanel_mail"
    return
  fi

  echo "generic"
}

build_services_json() {
  local rows=()
  local record status source unit
  local json="["
  local first=1

  add_service() {
    local group="$1"
    local key="$2"
    local pattern="$3"
    shift 3
    record="$(detect_service_status "${pattern}" "$@")"
    IFS='|' read -r status source unit <<< "${record}"
    rows+=("${group}|${key}|${unit}|${status}|${source}")
  }

  # Mail services
  add_service "mail_mta" "exim" "exim|exim4" "exim" "exim4"
  add_service "mail_access" "dovecot" "dovecot|dovecot/imap|dovecot/pop3" "dovecot" "cpanel-dovecot-solr"
  add_service "mail_service" "mailman" "mailman|mailmanctl|mailman3|mailman-core|cpanel-mailman" "mailman" "mailman3" "mailman-core" "cpanel-mailman"

  # Firewall / anti-spam / anti-virus
  add_service "firewall" "csf" "lfd|csf" "csf" "lfd" "csf.service"
  add_service "firewall" "clamd" "clamd|clamd@|clamd\.cpaneld|clamd-wrapper" "clamd" "clamd@scan" "clamav-daemon" "clamd-wrapper"
  add_service "firewall" "spamd" "spamd|spamd-child|spamd\.cpaneld|spamd-wrapper" "spamd" "spamassassin" "spamd-wrapper"

  # SSH
  add_service "ssh" "sshd" "sshd|sshd:" "sshd" "ssh"

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
  services_json="$(build_services_json)"

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

  local response http_code
  response=$(curl -sS -m "${CURL_TIMEOUT}" -H "Content-Type: application/json" -H "X-Server-Token: ${SERVER_TOKEN}" -X POST -d "${payload}" -w "\n%{http_code}" "${MASTER_URL}" || true)
  http_code=$(echo "${response}" | tail -n1)

  if [[ "${http_code}" != "200" ]]; then
    log "ERROR push failed code=${http_code} body=$(echo "${response}" | head -n1)"
    exit 1
  fi

  log "OK push success server_id=${SERVER_ID} panel=${panel_profile} mta=${mta} queue=${queue_total}"
}

main "$@"
