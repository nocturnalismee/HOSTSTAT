#!/usr/bin/env bash
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

MASTER_URL=""
SERVER_TOKEN=""
SERVER_ID=""
CURL_TIMEOUT="12"
LOG_FILE=""
SIGN_REQUESTS="0"
HDSENTINEL_BIN="/root/hdsentinel-018c-x64"

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

has_cmd() {
  command -v "$1" >/dev/null 2>&1
}

safe_int() {
  local val="${1:-}"
  [[ "${val}" =~ ^[0-9]+$ ]] && echo "${val}" || echo "0"
}

first_int_from_text() {
  local text="${1:-}"
  printf '%s' "${text}" | awk '{
    for (i = 1; i <= NF; i++) {
      gsub(/[^0-9]/, "", $i)
      if ($i ~ /^[0-9]+$/) { print $i; exit }
    }
  }'
}

parse_power_on_hours() {
  local text="${1:-}"
  local days hours
  days="$(printf '%s' "${text}" | awk '{
    for (i=1; i<=NF; i++) {
      u=tolower($i); gsub(/[^a-z]/, "", u);
      if (u ~ /^days?$/ && i>1) {
        v=$(i-1); gsub(/[^0-9]/,"",v); if (v ~ /^[0-9]+$/) { print v; exit }
      }
    }
  }')"
  hours="$(printf '%s' "${text}" | awk '{
    for (i=1; i<=NF; i++) {
      u=tolower($i); gsub(/[^a-z]/, "", u);
      if (u ~ /^hours?$/ && i>1) {
        v=$(i-1); gsub(/[^0-9]/,"",v); if (v ~ /^[0-9]+$/) { print v; exit }
      }
    }
  }')"
  days="$(safe_int "${days}")"
  hours="$(safe_int "${hours}")"
  echo $(( days * 24 + hours ))
}

parse_size_mb_to_bytes() {
  local text="${1:-}"
  local mb
  mb="$(first_int_from_text "${text}")"
  mb="$(safe_int "${mb}")"
  echo $(( mb * 1000000 ))
}

parse_written_to_bytes() {
  local text="${1:-}"
  local num unit
  num="$(printf '%s' "${text}" | awk '{print $1}' | tr ',' '.')"
  unit="$(printf '%s' "${text}" | awk '{print toupper($2)}')"
  [[ -z "${num}" || -z "${unit}" ]] && { echo "0"; return; }
  awk -v n="${num}" -v u="${unit}" '
    BEGIN{
      m=1;
      if (u=="KB") m=1000;
      else if (u=="MB") m=1000000;
      else if (u=="GB") m=1000000000;
      else if (u=="TB") m=1000000000000;
      else if (u=="PB") m=1000000000000000;
      else m=1;
      printf "%.0f", (n+0)*m;
    }'
}

extract_smart_fallback_fields() {
  # Return: serial|pot|total_written_bytes|reallocated|pending|uncorrectable
  local device="${1:-}"
  [[ -z "${device}" ]] && { echo "||0|0|0|0"; return 0; }
  has_cmd smartctl || { echo "||0|0|0|0"; return 0; }

  local info serial pot reallocated pending uncorrectable written_lbas written_units written_bytes
  info="$(smartctl -a "${device}" 2>/dev/null || true)"
  [[ -z "${info}" ]] && { echo "||0|0|0|0"; return 0; }

  serial="$(printf '%s' "${info}" | sed -n 's/^Serial Number: *//p' | head -n1)"
  pot="$(printf '%s' "${info}" | awk '/Power_On_Hours|Power on Hours|Power On Hours/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9]+$/){print $i; exit}}' | head -n1)"
  reallocated="$(printf '%s' "${info}" | awk '/Reallocated_Sector_Ct/ {print $NF; exit}')"
  pending="$(printf '%s' "${info}" | awk '/Current_Pending_Sector/ {print $NF; exit}')"
  uncorrectable="$(printf '%s' "${info}" | awk '/Offline_Uncorrectable/ {print $NF; exit}')"

  # SATA/ATA: Total_LBAs_Written uses 512-byte sectors.
  written_lbas="$(printf '%s' "${info}" | awk '/Total_LBAs_Written/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9]+$/){print $i; exit}}' | head -n1)"
  # NVMe: Data Units Written count (1 unit = 512000 bytes)
  written_units="$(printf '%s' "${info}" | awk '/Data Units Written:/ {gsub(/,/, "", $4); if($4 ~ /^[0-9]+$/){print $4; exit}}' | head -n1)"

  written_lbas="$(safe_int "${written_lbas}")"
  written_units="$(safe_int "${written_units}")"
  if (( written_lbas > 0 )); then
    written_bytes=$(( written_lbas * 512 ))
  elif (( written_units > 0 )); then
    written_bytes=$(( written_units * 512000 ))
  else
    written_bytes=0
  fi

  pot="$(safe_int "${pot}")"
  reallocated="$(safe_int "${reallocated}")"
  pending="$(safe_int "${pending}")"
  uncorrectable="$(safe_int "${uncorrectable}")"

  printf '%s|%s|%s|%s|%s|%s' "${serial}" "${pot}" "${written_bytes}" "${reallocated}" "${pending}" "${uncorrectable}"
}

health_from_smart() {
  local temp="${1:-0}" pending="${2:-0}" reallocated="${3:-0}" uncorrectable="${4:-0}" wear="${5:-0}"
  if (( pending > 0 || uncorrectable > 0 )); then
    echo "critical"
    return
  fi
  if (( reallocated > 0 )) || (( temp >= 60 )) || (( wear >= 90 )); then
    echo "warning"
    return
  fi
  echo "ok"
}

build_hdsentinel_json() {
  local hds_bin
  if [[ -n "${HDSENTINEL_BIN}" && -x "${HDSENTINEL_BIN}" ]]; then
    hds_bin="${HDSENTINEL_BIN}"
  else
    hds_bin="$(command -v hdsentinel-018c-x64 || true)"
    if [[ -z "${hds_bin}" ]]; then
      # Common custom install paths (can be overridden by HDSENTINEL_BIN)
      for candidate in \
        /usr/local/bin/hdsentinel-018c-x64 \
        /usr/bin/hdsentinel-018c-x64 \
        /opt/hdsentinel/hdsentinel-018c-x64 \
        /opt/HDSentinel/hdsentinel-018c-x64 \
        /root/hdsentinel-018c-x64; do
        if [[ -x "${candidate}" ]]; then
          hds_bin="${candidate}"
          break
        fi
      done
    fi
  fi
  [[ -z "${hds_bin}" ]] && return 1

  local out
  out="$("${hds_bin}" -r 2>/dev/null || true)"
  [[ -z "${out}" ]] && return 1

  local json="[" first=1 count=0
  local cur_device="" cur_model="" cur_serial="" cur_firmware="" cur_interface="" cur_health="" cur_temp=""
  local cur_power_on="" cur_written="" cur_size_line=""

  flush_hds_block() {
    [[ -z "${cur_device}${cur_model}${cur_serial}" ]] && return 0

    local health temp status disk_key device_name power_on_time total_written_bytes
    local reallocated pending uncorrectable capacity_bytes disk_type summary
    health="$(safe_int "${cur_health}")"
    temp="$(safe_int "${cur_temp}")"
    power_on_time="$(parse_power_on_hours "${cur_power_on}")"
    total_written_bytes="$(parse_written_to_bytes "${cur_written}")"
    capacity_bytes="$(parse_size_mb_to_bytes "${cur_size_line}")"
    IFS='|' read -r _smart_serial _smart_pot _smart_twb reallocated pending uncorrectable <<< "$(extract_smart_fallback_fields "${cur_device}")"
    reallocated="$(safe_int "${reallocated}")"
    pending="$(safe_int "${pending}")"
    uncorrectable="$(safe_int "${uncorrectable}")"

    if [[ -z "${cur_serial}" && -n "${_smart_serial}" ]]; then
      cur_serial="${_smart_serial}"
    fi
    if (( power_on_time == 0 )); then
      power_on_time="$(safe_int "${_smart_pot}")"
    fi
    if (( total_written_bytes == 0 )); then
      total_written_bytes="$(safe_int "${_smart_twb}")"
    fi

    status="ok"
    if (( health < 50 || temp >= 70 )); then
      status="critical"
    elif (( health < 80 || temp >= 60 )); then
      status="warning"
    fi

    disk_type="unknown"
    if printf '%s' "${cur_device}" | grep -qi 'nvme'; then
      disk_type="nvme"
    elif printf '%s' "${cur_model}" | grep -Eqi 'ssd|solid state'; then
      disk_type="ssd"
    elif [[ -n "${cur_model}" ]]; then
      disk_type="hdd"
    fi

    if [[ -n "${cur_serial}" ]]; then
      disk_key="${cur_serial}"
    elif [[ -n "${cur_interface}" ]]; then
      disk_key="${cur_device}|${cur_interface}"
    elif [[ -n "${cur_device}" ]]; then
      disk_key="${cur_device}"
    else
      disk_key="${cur_model}"
    fi

    device_name="${cur_device}"
    if [[ -n "${cur_interface}" ]]; then
      device_name="${cur_device} (${cur_interface})"
    fi
    summary="Health ${health}% | Temp ${temp}C | POT ${power_on_time}h | Written ${total_written_bytes}B"

    [[ "${first}" -eq 0 ]] && json+=","
    first=0
    count=$((count + 1))
    json+="{\"disk_key\":\"$(json_escape "${disk_key}")\",\"device_name\":\"$(json_escape "${device_name}")\",\"model\":\"$(json_escape "${cur_model}")\",\"serial\":\"$(json_escape "${cur_serial}")\",\"firmware\":\"$(json_escape "${cur_firmware}")\",\"disk_type\":\"${disk_type}\",\"capacity_bytes\":${capacity_bytes},\"health_status\":\"${status}\",\"health_score\":${health},\"temperature_c\":${temp},\"power_on_time\":${power_on_time},\"total_written_bytes\":${total_written_bytes},\"reallocated_sectors\":${reallocated},\"pending_sectors\":${pending},\"uncorrectable_sectors\":${uncorrectable},\"source_tool\":\"hdsentinel\",\"raw_summary\":\"$(json_escape "${summary}")\"}"
  }

  local line trimmed value
  while IFS= read -r line; do
    trimmed="${line#"${line%%[![:space:]]*}"}"
    case "${trimmed}" in
      HDD\ Device*)
        flush_hds_block
        cur_device=""
        cur_model=""
        cur_serial=""
        cur_firmware=""
        cur_interface=""
        cur_health=""
        cur_temp=""
        cur_power_on=""
        cur_written=""
        cur_size_line=""
        value="${trimmed#*: }"
        cur_device="${value}"
        ;;
      HDD\ Model\ ID*)
        cur_model="${trimmed#*: }"
        ;;
      HDD\ Serial\ No*)
        cur_serial="${trimmed#*: }"
        ;;
      HDD\ Revision*)
        cur_firmware="${trimmed#*: }"
        ;;
      HDD\ Size*)
        cur_size_line="${trimmed#*: }"
        ;;
      Interface*)
        cur_interface="${trimmed#*: }"
        ;;
      Temperature*)
        cur_temp="$(first_int_from_text "${trimmed}")"
        ;;
      Health*)
        cur_health="$(first_int_from_text "${trimmed}")"
        ;;
      Power\ on\ time*)
        cur_power_on="${trimmed#*: }"
        ;;
      Total\ written*)
        cur_written="${trimmed#*: }"
        ;;
    esac
  done <<< "${out}"
  flush_hds_block

  json+="]"
  (( count > 0 )) || return 1
  printf '%s' "${json}"
}

build_smartctl_json() {
  has_cmd smartctl || return 1

  local scan
  scan="$(smartctl --scan 2>/dev/null || true)"
  [[ -z "${scan}" ]] && return 1

  local json="["
  local first=1
  local line device
  while IFS= read -r line; do
    device="$(printf '%s' "${line}" | awk '{print $1}')"
    [[ -z "${device}" ]] && continue

    local info model serial firmware temp pot reallocated pending uncorrectable
    local written_lbas written_units score percentage_used wearout_pct type status
    local capacity_bytes disk_key total_written_bytes
    info="$(smartctl -a "${device}" 2>/dev/null || true)"
    [[ -z "${info}" ]] && continue

    model="$(printf '%s' "${info}" | sed -n 's/^Model Family: *//p; s/^Device Model: *//p; s/^Product: *//p' | head -n1)"
    serial="$(printf '%s' "${info}" | sed -n 's/^Serial Number: *//p' | head -n1)"
    firmware="$(printf '%s' "${info}" | sed -n 's/^Firmware Version: *//p; s/^Firmware Revision: *//p' | head -n1)"
    temp="$(printf '%s' "${info}" | awk '/Temperature_Celsius|Current Drive Temperature|Temperature:/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9]+$/){print $i; exit}}' | head -n1)"
    pot="$(printf '%s' "${info}" | awk '/Power_On_Hours|Power on Hours|Power On Hours/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9]+$/){print $i; exit}}' | head -n1)"
    reallocated="$(printf '%s' "${info}" | awk '/Reallocated_Sector_Ct/ {print $NF; exit}')"
    pending="$(printf '%s' "${info}" | awk '/Current_Pending_Sector/ {print $NF; exit}')"
    uncorrectable="$(printf '%s' "${info}" | awk '/Offline_Uncorrectable/ {print $NF; exit}')"
    written_lbas="$(printf '%s' "${info}" | awk '/Total_LBAs_Written/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9,]+$/){gsub(/,/, "", $i); print $i; exit}}' | head -n1)"
    written_units="$(printf '%s' "${info}" | awk '/Data Units Written:/ {for(i=1;i<=NF;i++){if($i ~ /^[0-9,]+$/){gsub(/,/, "", $i); print $i; exit}}}' | head -n1)"
    score="$(printf '%s' "${info}" | awk '/Media_Wearout_Indicator|Percentage Used/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9]+$/){print $i; exit}}' | head -n1)"
    percentage_used="$(printf '%s' "${info}" | awk '/Percentage Used/ {for(i=NF;i>=1;i--) if($i ~ /^[0-9]+$/){print $i; exit}}' | head -n1)"

    capacity_bytes="$(printf '%s' "${info}" | sed -n 's/^User Capacity: *\[\([0-9][0-9,]*\) bytes\].*/\1/p; s/^Total NVM Capacity: *\([0-9][0-9,]*\).*/\1/p' | head -n1 | tr -d ',')"

    if [[ "${device}" == /dev/nvme* ]]; then
      type="nvme"
    elif printf '%s' "${info}" | grep -qi "Rotation Rate:.*Solid State Device"; then
      type="ssd"
    elif printf '%s' "${info}" | grep -qi "Rotation Rate:.*rpm"; then
      type="hdd"
    elif printf '%s' "${model}" | grep -Eqi 'ssd|solid state'; then
      type="ssd"
    elif [[ -n "${model}" ]]; then
      type="hdd"
    else
      type="unknown"
    fi

    temp="$(safe_int "${temp}")"
    pot="$(safe_int "${pot}")"
    reallocated="$(safe_int "${reallocated}")"
    pending="$(safe_int "${pending}")"
    uncorrectable="$(safe_int "${uncorrectable}")"
    written_lbas="$(safe_int "${written_lbas}")"
    written_units="$(safe_int "${written_units}")"
    score="$(safe_int "${score}")"
    percentage_used="$(safe_int "${percentage_used}")"
    capacity_bytes="$(safe_int "${capacity_bytes}")"
    wearout_pct=0

    # If wear is reported as "used", convert to health score and preserve wearout_pct.
    if (( percentage_used > 0 && percentage_used <= 100 )); then
      wearout_pct="${percentage_used}"
      score=$((100 - percentage_used))
    elif (( score > 0 && score <= 100 )) && printf '%s' "${info}" | grep -qi "Media_Wearout_Indicator"; then
      wearout_pct=$((100 - score))
    fi
    if (( score == 0 )); then
      score=100
    fi

    status="$(health_from_smart "${temp}" "${pending}" "${reallocated}" "${uncorrectable}" "${wearout_pct}")"
    if (( temp >= 70 )); then
      status="critical"
    elif (( temp >= 60 && "${status}" == "ok" )); then
      status="warning"
    fi

    # Total written: ATA (LBA*512) or NVMe (units*512000).
    if (( written_lbas > 0 )); then
      total_written_bytes=$(( written_lbas * 512 ))
    elif (( written_units > 0 )); then
      total_written_bytes=$(( written_units * 512000 ))
    else
      total_written_bytes=0
    fi

    if [[ -n "${serial}" ]]; then
      disk_key="${serial}"
    else
      disk_key="${device}"
    fi

    [[ "${first}" -eq 0 ]] && json+=","
    first=0
    json+="{\"disk_key\":\"$(json_escape "${disk_key}")\",\"device_name\":\"$(json_escape "${device}")\",\"model\":\"$(json_escape "${model}")\",\"serial\":\"$(json_escape "${serial}")\",\"firmware\":\"$(json_escape "${firmware}")\",\"disk_type\":\"${type}\",\"capacity_bytes\":${capacity_bytes},\"health_status\":\"${status}\",\"health_score\":${score},\"temperature_c\":${temp},\"power_on_time\":${pot},\"reallocated_sectors\":${reallocated},\"pending_sectors\":${pending},\"uncorrectable_sectors\":${uncorrectable},\"wearout_pct\":${wearout_pct},\"total_written_bytes\":${total_written_bytes},\"source_tool\":\"smartctl\",\"raw_summary\":\"$(json_escape "$(printf '%s' "${info}" | head -n 4 | tr '\n' ' ')")\"}"
  done <<< "${scan}"

  json+="]"
  [[ "${first}" -eq 1 ]] && return 1
  printf '%s' "${json}"
}

main() {
  [[ -z "${SERVER_TOKEN}" || "${SERVER_TOKEN}" == "CHANGE_ME_TOKEN" ]] && die "SERVER_TOKEN belum dikonfigurasi"

  local disks_json source
  if disks_json="$(build_hdsentinel_json)"; then
    source="hdsentinel"
  elif disks_json="$(build_smartctl_json)"; then
    source="smartctl"
  else
    die "Tidak bisa mengumpulkan data disk health (hdsentinel/smartctl tidak tersedia)"
  fi

  local payload
  payload=$(cat <<EOF
{
  "server_id": ${SERVER_ID},
  "disk_health": ${disks_json}
}
EOF
)

  local sign_headers=()
  if [[ "${SIGN_REQUESTS}" == "1" ]] && has_cmd openssl; then
    local req_ts req_sig
    req_ts="$(date +%s)"
    req_sig="$(printf '%s.%s' "${req_ts}" "${payload}" | openssl dgst -sha256 -hmac "${SERVER_TOKEN}" | awk '{print $2}')"
    if [[ "${req_sig}" =~ ^[a-fA-F0-9]{64}$ ]]; then
      sign_headers=(
        -H "X-Server-Timestamp: ${req_ts}"
        -H "X-Server-Signature: ${req_sig}"
      )
    fi
  fi

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

  if [[ "${http_code}" != "200" ]]; then
    log "ERROR disk push failed code=${http_code} source=${source} body=${response_body}"
    exit 1
  fi

  local accepted rejected
  accepted="$(printf '%s' "${response_body}" | sed -n 's/.*"accepted":\([0-9][0-9]*\).*/\1/p')"
  rejected="$(printf '%s' "${response_body}" | sed -n 's/.*"rejected":\([0-9][0-9]*\).*/\1/p')"
  if [[ -z "${accepted}" || -z "${rejected}" ]]; then
    log "ERROR disk push invalid success response source=${source} http_code=${http_code} body=${response_body}"
    exit 1
  fi
  accepted="$(safe_int "${accepted}")"
  rejected="$(safe_int "${rejected}")"
  if (( accepted <= 0 )); then
    log "ERROR disk push no data accepted server_id=${SERVER_ID} source=${source} accepted=${accepted} rejected=${rejected} http_code=${http_code} body=${response_body}"
    exit 1
  fi

  local payload_bytes disk_count
  payload_bytes="$(printf '%s' "${payload}" | wc -c | tr -d ' ')"
  disk_count="$(printf '%s' "${disks_json}" | grep -o '"disk_key"' | wc -l | tr -d ' ')"
  log "OK disk push success server_id=${SERVER_ID} source=${source} disk_count=${disk_count} accepted=${accepted} rejected=${rejected} http_code=${http_code} payload_bytes=${payload_bytes} body=${response_body}"
}

main "$@"
