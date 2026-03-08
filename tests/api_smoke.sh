#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"

echo "Running API smoke on ${BASE_URL}"

code=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/push.php")
if [[ "${code}" != "405" ]]; then
  echo "Expected 405 on GET /api/push.php, got ${code}"
  exit 1
fi

code=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{}' "${BASE_URL}/api/push.php")
if [[ "${code}" != "403" ]]; then
  echo "Expected 403 on POST /api/push.php without token, got ${code}"
  exit 1
fi

code=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/status.php")
if [[ "${code}" != "200" ]]; then
  echo "Expected 200 on GET /api/status.php, got ${code}"
  exit 1
fi

health_code=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/health.php")
if [[ "${health_code}" != "200" && "${health_code}" != "503" ]]; then
  echo "Expected 200/503 on GET /api/health.php, got ${health_code}"
  exit 1
fi

if [[ -n "${TEST_PUSH_TOKEN:-}" ]]; then
  payload='{
    "server_id": '"${TEST_SERVER_ID:-1}"',
    "uptime": 123,
    "ram_total": 1024,
    "ram_used": 512,
    "hdd_total": 2048,
    "hdd_used": 1024,
    "cpu_load": 0.5,
    "network": {"in_bps": 1000, "out_bps": 2000},
    "mail": {"mta": "none", "queue_total": 0},
    "services": [
      {"group":"webserver","service_key":"nginx","unit_name":"nginx","status":"up","source":"systemctl"},
      {"group":"ssh","service_key":"sshd","unit_name":"sshd","status":"down","source":"service"}
    ]
  }'
  code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -H "X-Server-Token: ${TEST_PUSH_TOKEN}" \
    -d "${payload}" \
    "${BASE_URL}/api/push.php")
  if [[ "${code}" != "200" ]]; then
    echo "Expected 200 on POST /api/push.php with services payload, got ${code}"
    exit 1
  fi

  if [[ "${TEST_SIGNED_PUSH:-0}" == "1" ]]; then
    if ! command -v openssl >/dev/null 2>&1; then
      echo "Skipping signed push smoke: openssl not found"
    else
      ts=$(date +%s)
      sig=$(printf '%s.%s' "${ts}" "${payload}" | openssl dgst -sha256 -hmac "${TEST_PUSH_TOKEN}" | awk '{print $2}')
      code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
        -H "Content-Type: application/json" \
        -H "X-Server-Token: ${TEST_PUSH_TOKEN}" \
        -H "X-Server-Timestamp: ${ts}" \
        -H "X-Server-Signature: ${sig}" \
        -d "${payload}" \
        "${BASE_URL}/api/push.php")
      if [[ "${code}" != "200" ]]; then
        echo "Expected 200 on signed POST /api/push.php, got ${code}"
        exit 1
      fi

      bad_sig="0000000000000000000000000000000000000000000000000000000000000000"
      bad_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
        -H "Content-Type: application/json" \
        -H "X-Server-Token: ${TEST_PUSH_TOKEN}" \
        -H "X-Server-Timestamp: ${ts}" \
        -H "X-Server-Signature: ${bad_sig}" \
        -d "${payload}" \
        "${BASE_URL}/api/push.php")
      if [[ "${bad_code}" != "403" && "${bad_code}" != "400" ]]; then
        echo "Expected 403/400 on invalid signed POST /api/push.php, got ${bad_code}"
        exit 1
      fi
    fi
  fi
fi

echo "api_smoke passed"
