#!/usr/bin/env bash
set -euo pipefail

echo "[1/4] PHP lint"
find . -type f -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l >/dev/null

echo "[2/4] Helper tests"
php tests/helpers_test.php

echo "[3/4] Schema consistency tests"
php tests/schema_consistency_test.php

echo "[4/4] API contract checklist"
echo "- POST /api/push.php with invalid method should return 405"
echo "- POST /api/push.php with invalid token should return 403"
echo "- GET /api/status.php should return JSON array"
echo "- GET /api/health.php should return 200 (or 503 when degraded)"
echo "Smoke tests completed."
