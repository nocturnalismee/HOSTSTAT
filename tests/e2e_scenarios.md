# E2E Scenarios

## Prerequisites
- App running at `BASE_URL` (default `http://127.0.0.1:8000`)
- Database migrated up to latest migration.
- At least 1 active server with valid push token.

## Scenario 1: AuthZ Matrix
- Run `bash tests/authz_matrix.sh`
- Expected:
  - Admin can access mutating admin pages (`server_add`, `server_edit`, `settings`, `export`)
  - Viewer is redirected from mutating pages to dashboard

## Scenario 2: Push API Compatibility
- Run `BASE_URL=http://127.0.0.1:8000 TEST_PUSH_TOKEN=<token> TEST_SERVER_ID=<id> bash tests/api_smoke.sh`
- Expected:
  - Token-only push returns `200`
  - Invalid token returns `403`

## Scenario 3: Signed Push
- Run `TEST_SIGNED_PUSH=1` together with Scenario 2.
- Expected:
  - Valid signature returns `200`
  - Invalid signature returns `403` or `400`

## Scenario 4: Push IP Allowlist
- Set `push_allowed_ips` in server edit page.
- Try push from non-allowlisted source IP.
- Expected:
  - Push returns `403` (`Source IP not allowed`)
- Clear allowlist and retry.
- Expected:
  - Push returns `200`

## Scenario 5: Maintenance Suppression
- Enable maintenance mode on a server.
- Trigger high metric push (CPU/RAM/Disk/Queue).
- Expected:
  - Metrics still recorded
  - Alert threshold/service transition is suppressed while maintenance is active
- Disable maintenance and retry.
- Expected:
  - Alerting resumes

## Scenario 6: Session Timeout
- Set low values in settings:
  - `session_idle_timeout_minutes = 5`
  - `session_absolute_timeout_minutes = 15`
- Login, keep idle > idle timeout.
- Expected:
  - Next request forces re-login with session expired flash message.
