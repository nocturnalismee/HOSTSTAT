# Release Checklist

## 1. Pre-Release
- Pull latest code and confirm clean workspace.
- Run DB migrations in staging.
- Verify `php -l` passes for all PHP files.
- Run smoke tests:
  - `php tests/helpers_test.php`
  - `bash tests/api_smoke.sh`
  - `bash tests/authz_matrix.sh` (set viewer creds if available)

## 2. Staging Validation
- Verify `/api/health.php` response:
  - `200` healthy or expected `503` with clear degraded reason.
- Confirm worker heartbeat updates:
  - `alert_check`
  - `ping_check`
  - `retention_cleanup`
- Validate key admin flows:
  - Login/logout
  - Add/edit server
  - Settings save
  - Audit logs visible and recording new actions
- Validate key monitoring flows:
  - Agent push accepted
  - Status list/detail/history working
  - Alerts generated as expected

## 3. Production Deployment
- Backup database before migration.
- Apply migrations in order using CLI migration runner: `php migrate.php`.
- Deploy code and clear opcode/cache if used.
- Ensure cron jobs are active:
  - `workers/alert-check.php` every minute
  - `workers/ping-check.php` every minute
  - `workers/cleanup.php` daily

## 4. Post-Deploy Verification
- Check `/api/health.php` right after deploy.
- Run API smoke quickly with real token.
- Verify one recent audit log entry for deployment action.
- Verify no PHP fatal errors in logs.

## 5. Sign-Off
- Record release version/date.
- Record applied migrations.
- Link to rollback playbook if hotfix needed.
