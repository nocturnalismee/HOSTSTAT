const STORAGE_KEY = "servmon_last_alert_id";
const MAX_POPUPS_PER_POLL = 3;

let servmonLastAlertId = Number(localStorage.getItem(STORAGE_KEY)) || 0;
let servmonAlertsInitialized = servmonLastAlertId > 0;

function playAlertBeep() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = ctx.createOscillator();
    const gainNode = ctx.createGain();
    oscillator.type = "sine";
    oscillator.frequency.value = 880;
    gainNode.gain.value = 0.08;
    oscillator.connect(gainNode);
    gainNode.connect(ctx.destination);
    oscillator.start();
    setTimeout(() => {
      oscillator.stop();
      ctx.close();
    }, 260);
  } catch (err) {
    console.error(err);
  }
}

function updateLastAlertId(id) {
  const numericId = Number(id);
  if (numericId > servmonLastAlertId) {
    servmonLastAlertId = numericId;
    localStorage.setItem(STORAGE_KEY, servmonLastAlertId);
  }
}

function showAlertToast(alert) {
  const container = document.getElementById("servmon-alert-toast-container");
  if (!container) return;

  const div = document.createElement("div");
  const severity =
    alert.severity === "danger"
      ? "danger"
      : alert.severity === "warning"
        ? "warning"
        : alert.severity === "success"
          ? "success"
          : "info";
  const closeBtnClass =
    severity === "warning" || severity === "info"
      ? "btn-close"
      : "btn-close btn-close-white";
  div.className = `toast align-items-center border-0 toast-severity toast-severity-${severity}`;
  div.setAttribute("role", "alert");
  div.setAttribute("aria-live", "assertive");
  div.setAttribute("aria-atomic", "true");
  div.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">
        <strong>${escapeHtml(alert.title || "Alert")}</strong><br>
        ${escapeHtml(alert.message || "")}
      </div>
      <button type="button" class="${closeBtnClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  `;
  container.appendChild(div);
  const toast = new bootstrap.Toast(div, { delay: 12000 });
  toast.show();
}

function escapeHtml(input) {
  const el = document.createElement("div");
  el.textContent = input ?? "";
  return el.innerHTML;
}

async function pollAlerts() {
  const endpoint = window.SERVMON_API_ALERTS;
  if (!endpoint) return;
  try {
    // Attempt to grab any missed alerts since the browser last checked in
    const response = await fetch(`${endpoint}?since_id=${servmonLastAlertId}&limit=20`, { headers: { Accept: "application/json" } });
    if (!response.ok) return;
    const rows = await response.json();
    if (!Array.isArray(rows) || rows.length === 0) return;

    if (!servmonAlertsInitialized) {
      // First ever page load for a brand new browser - fast forward silently
      rows.forEach((row) => {
        updateLastAlertId(row.id);
      });
      servmonAlertsInitialized = true;
      return;
    }

    // Sort to handle oldest first so popup stacking makes temporal sense
    rows.sort((a, b) => Number(a.id || 0) - Number(b.id || 0));

    // If an enormous backlog hit (e.g., coming back after a weekend), drop the oldest
    // and only pop the $MAX_POPUPS_PER_POLL freshest ones to prevent browser freezing.
    const popupsToShow = rows.slice(-MAX_POPUPS_PER_POLL);

    popupsToShow.forEach((row) => {
      showAlertToast(row);
    });

    // Crucially, update the high watermark ID for ALL rows retrieved (even the ones we muted)
    rows.forEach((row) => {
      updateLastAlertId(row.id);
    });

    if (popupsToShow.length > 0) {
      playAlertBeep();
    }
  } catch (err) {
    console.error(err);
  }
}

// ── SSE with polling fallback ────────────────────────────────────────
function handleSSEAlerts(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return;

  if (!servmonAlertsInitialized) {
    rows.forEach(function (row) {
      updateLastAlertId(row.id);
    });
    servmonAlertsInitialized = true;
    return;
  }

  rows.sort(function (a, b) {
    return Number(a.id || 0) - Number(b.id || 0);
  });

  var popupsToShow = rows.slice(-MAX_POPUPS_PER_POLL);

  popupsToShow.forEach(function (row) {
    showAlertToast(row);
  });

  rows.forEach(function (row) {
    updateLastAlertId(row.id);
  });

  if (popupsToShow.length > 0) {
    playAlertBeep();
  }
}

// Initial poll to fast-forward the alert ID
pollAlerts();

// SSE connection for real-time alerts (reuses the page's SSE if available)
var SM = window.ServMon;
if (SM && SM.createSSEConnection) {
  var alertSseUrl = window.SERVMON_API_SSE || "/api/sse.php";
  // Add since_alert_id param if we have a known watermark
  if (servmonLastAlertId > 0) {
    alertSseUrl += (alertSseUrl.includes("?") ? "&" : "?") + "since_alert_id=" + servmonLastAlertId;
  }

  SM.createSSEConnection({
    url: alertSseUrl,
    events: {
      alerts: handleSSEAlerts,
    },
    fallbackInterval: 15000,
    fallbackFn: pollAlerts,
  });
} else {
  // No SSE support — use traditional polling
  setInterval(pollAlerts, 15000);
}

