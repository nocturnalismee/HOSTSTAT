$ErrorActionPreference = "Stop"

Write-Host "[1/4] PHP lint"
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    php -l $_.FullName | Out-Null
}

Write-Host "[2/4] Helper tests"
php tests/helpers_test.php

Write-Host "[3/4] Schema consistency tests"
php tests/schema_consistency_test.php

Write-Host "[4/4] API smoke (opsional)"
Write-Host "Jalankan server lokal lalu:"
Write-Host "`$env:BASE_URL='http://127.0.0.1:8000'; bash tests/api_smoke.sh"
Write-Host "Catatan: /api/health.php valid jika 200 atau 503 (degraded)."

Write-Host "Smoke tests completed."
