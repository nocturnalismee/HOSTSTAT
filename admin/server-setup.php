<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$server = db_one('SELECT id, name, token FROM servers WHERE id = :id LIMIT 1', [':id' => $id]);
if ($server === null) {
    flash_set('danger', 'Server not found.');
    redirect('/admin/servers.php');
}

$pushEndpoint = app_url('api/push.php');
$agentUrl = app_url('agents/agent.sh');
$agentEmailUrl = app_url('agents/agent-cpanel-mail.sh');
$systemdServiceUrl = app_url('agents/systemd/servmon-agent.service');
$systemdTimerUrl = app_url('agents/systemd/servmon-agent.timer');
$systemdEmailServiceUrl = app_url('agents/systemd/servmon-agent-cpanel-email.service');
$systemdEmailTimerUrl = app_url('agents/systemd/servmon-agent-cpanel-email.timer');

$title = APP_NAME . ' - Agent Instructions';
$activeNav = 'servers';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Agent Installation</h1>
            <p class="page-subtitle">Server: <?= e((string) $server['name']) ?>. Pilih profil agent yang sesuai, lakukan manual test, lalu aktifkan timer.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('admin/servers.php')) ?>">Back to List</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Quick Config Values</h2>
            <span class="badge text-bg-info">Copy & paste ready</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold mb-1">MASTER_URL</label>
                    <div class="input-group">
                        <input class="form-control font-mono" type="text" readonly value="<?= e($pushEndpoint) ?>">
                        <button class="btn btn-soft" type="button" data-copy-text="<?= e($pushEndpoint) ?>">Copy</button>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold mb-1">SERVER_TOKEN</label>
                    <div class="input-group">
                        <input class="form-control font-mono" type="text" readonly value="<?= e((string) $server['token']) ?>">
                        <button class="btn btn-soft" type="button" data-copy-text="<?= e((string) $server['token']) ?>">Copy</button>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold mb-1">SERVER_ID</label>
                    <div class="input-group">
                        <input class="form-control font-mono" type="text" readonly value="<?= e((string) ((int) $server['id'])) ?>">
                        <button class="btn btn-soft" type="button" data-copy-text="<?= e((string) ((int) $server['id'])) ?>">Copy</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Profile A: General Server (Recommended)</h2>
            <span class="badge text-bg-success">systemd timer</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">Jalankan di server target:</p>
<pre class="code-block rounded p-3 border-soft mb-0"><code>wget <?= e($agentUrl) ?> -O /opt/agent.sh
chmod +x /opt/agent.sh
sed -i 's|^MASTER_URL=.*|MASTER_URL="<?= e($pushEndpoint) ?>"|' /opt/agent.sh
sed -i 's|^SERVER_TOKEN=.*|SERVER_TOKEN="<?= e((string) $server['token']) ?>"|' /opt/agent.sh
sed -i 's|^SERVER_ID=.*|SERVER_ID=<?= e((string) $server['id']) ?>|' /opt/agent.sh

# Manual test
/opt/agent.sh

# Install systemd unit (general server role)
wget <?= e($systemdServiceUrl) ?> -O /etc/systemd/system/servmon-agent.service
wget <?= e($systemdTimerUrl) ?> -O /etc/systemd/system/servmon-agent.timer
systemctl daemon-reload
systemctl enable --now servmon-agent.timer
systemctl status servmon-agent.service --no-pager
systemctl list-timers --all | grep servmon-agent
</code></pre>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Profile B: cPanel Email Host</h2>
            <span class="badge text-bg-warning">specialized agent</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">Gunakan profile ini khusus node mail/cPanel email:</p>
<pre class="code-block rounded p-3 border-soft mb-0"><code>wget <?= e($agentEmailUrl) ?> -O /opt/agent-cpanel-mail.sh
chmod +x /opt/agent-cpanel-mail.sh
sed -i 's|^MASTER_URL=.*|MASTER_URL="<?= e($pushEndpoint) ?>"|' /opt/agent-cpanel-mail.sh
sed -i 's|^SERVER_TOKEN=.*|SERVER_TOKEN="<?= e((string) $server['token']) ?>"|' /opt/agent-cpanel-mail.sh
sed -i 's|^SERVER_ID=.*|SERVER_ID=<?= e((string) $server['id']) ?>|' /opt/agent-cpanel-mail.sh

# Manual test
/opt/agent-cpanel-mail.sh

# Install systemd unit (cPanel email role)
wget <?= e($systemdEmailServiceUrl) ?> -O /etc/systemd/system/servmon-agent-cpanel-email.service
wget <?= e($systemdEmailTimerUrl) ?> -O /etc/systemd/system/servmon-agent-cpanel-email.timer
systemctl daemon-reload
systemctl enable --now servmon-agent-cpanel-email.timer
systemctl status servmon-agent-cpanel-email.service --no-pager
systemctl list-timers --all | grep servmon-agent-cpanel-email
</code></pre>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Fallback: Legacy Cron</h2>
            <span class="badge text-bg-secondary">optional</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">Pakai ini jika server tidak mendukung systemd timer:</p>
<pre class="code-block rounded p-3 border-soft mb-0"><code>(crontab -l 2>/dev/null; echo "* * * * * /opt/agent.sh") | crontab -
</code></pre>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-body d-flex flex-wrap gap-2 justify-content-end">
            <a class="btn btn-info" href="<?= e(app_url('admin/server-detail.php?id=' . (int) $server['id'])) ?>">View Server Details</a>
            <a class="btn btn-outline-light" href="<?= e(app_url('admin/servers.php')) ?>">Back to List</a>
        </div>
    </section>
</main>
<script>
document.addEventListener("click", async (event) => {
  const btn = event.target.closest("[data-copy-text]");
  if (!btn) return;
  const value = btn.getAttribute("data-copy-text") || "";
  try {
    await navigator.clipboard.writeText(value);
    const prev = btn.textContent;
    btn.textContent = "Copied";
    window.setTimeout(() => { btn.textContent = prev; }, 1000);
  } catch (err) {
    console.error(err);
  }
});
</script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
