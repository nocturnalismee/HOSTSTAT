# Rollback Playbook

## Scope
Use this playbook when a deployment causes major regression in API, auth, alerting, or dashboard behavior.

## 1. Trigger Conditions
- Health endpoint remains degraded with production impact.
- Critical auth or push API failures.
- Continuous fatal errors after deploy.

## 2. Immediate Stabilization
- Pause deployment pipeline.
- Announce incident window to stakeholders.
- Keep worker cron running unless workers are root cause.

## 3. Code Rollback
- Re-deploy last known good application package/version.
- Clear opcode/cache layer if present.
- Validate key pages and API quickly:
  - `/auth/login.php`
  - `/api/status.php`
  - `/api/push.php` (token test)
  - `/api/health.php`

## 4. Database Rollback Strategy
- Prefer **forward fix** if migration is additive and harmless.
- If rollback is required:
  - Restore DB from pre-release backup.
  - Re-apply only stable migrations.
- Document any potential data loss window.

## 5. Worker & Alert Recovery
- Confirm worker crons are still scheduled and executable.
- Verify worker heartbeat freshness in dashboard warning banners.
- Check alert flood risk after rollback and adjust cooldown temporarily if needed.

## 6. Verification After Rollback
- Run smoke checks:
  - `php tests/helpers_test.php`
  - `bash tests/api_smoke.sh`
- Confirm admin audit logs still writable.
- Confirm push ingestion and latest metrics update.

## 7. Post-Incident
- Capture root cause.
- Open hotfix tasks with priority/severity.
- Update release checklist to prevent recurrence.
