/**
 * ServMon — Admin dashboard auto-refresh.
 *
 * Depends on: common.js (ServMon namespace)
 */

const SM = window.ServMon;
const CPU_HISTORY_KEY = "servmon:cpuHistory:admin";

SM.restoreCpuHistory(CPU_HISTORY_KEY);

function formatUptime(totalSeconds) {
  const seconds = Math.max(0, Math.trunc(Number(totalSeconds || 0)));
  if (seconds <= 0) return "0H";
  const days = Math.floor(seconds / 86400);
  if (days > 0) return `${days}D`;
  const hours = Math.floor(seconds / 3600);
  if (hours > 0) return `${hours}H`;
  return "<1H";
}

function formatServiceSummary(summary) {
  const up = Number(summary?.up ?? 0);
  const down = Number(summary?.down ?? 0);
  const unknown = Number(summary?.unknown ?? 0);
  return {
    up: Math.max(0, Math.trunc(up)),
    down: Math.max(0, Math.trunc(down)),
    unknown: Math.max(0, Math.trunc(unknown)),
    hasIssue:
      (Number.isFinite(down) && down > 0) ||
      (Number.isFinite(unknown) && unknown > 0),
  };
}

function normalizeServiceSummaryForServerStatus(summary, serverStatus) {
  if (serverStatus !== "down") return summary;
  const total = summary.up + summary.down + summary.unknown;
  if (total <= 0) return summary;
  return {
    up: 0,
    down: total,
    unknown: 0,
    hasIssue: true,
  };
}

function renderServiceSummary(summary) {
  if (summary.hasIssue) {
    const parts = [];
    if (summary.down > 0) {
      parts.push(
        `<span class="text-danger fw-semibold me-2"><i class="ti ti-arrow-down-circle me-1" aria-label="down"></i>${SM.escapeHtml(String(summary.down))}</span>`
      );
    }
    if (summary.unknown > 0) {
      parts.push(
        `<span class="text-warning fw-semibold"><i class="ti ti-help-circle me-1" aria-label="unknown"></i>${SM.escapeHtml(String(summary.unknown))}</span>`
      );
    }
    return parts.join("");
  }

  return `<span class="text-success fw-semibold"><i class="ti ti-arrow-up-circle me-1" aria-label="up"></i>${SM.escapeHtml(String(summary.up))}</span>`;
}

function updateAdminSummaryCards(servers) {
  const totalEl = document.querySelector("[data-admin-total]");
  const onlineEl = document.querySelector("[data-admin-online]");
  const downEl = document.querySelector("[data-admin-down]");
  const pendingEl = document.querySelector("[data-admin-pending]");
  if (!totalEl || !onlineEl || !downEl || !pendingEl) return;

  let online = 0;
  let down = 0;
  let pending = 0;
  servers.forEach((s) => {
    if (s.status === "online") online += 1;
    else if (s.status === "down") down += 1;
    else pending += 1;
  });

  totalEl.textContent = String(servers.length);
  onlineEl.textContent = String(online);
  downEl.textContent = String(down);
  pendingEl.textContent = String(pending);
}

function updateStaleHint(text, isError) {
  const staleEl = document.querySelector("[data-dashboard-stale]");
  if (!staleEl) return;
  staleEl.textContent = text;
  staleEl.classList.toggle("text-danger", Boolean(isError));
}

function renderAdminRowCells(s) {
  const serviceSummary = normalizeServiceSummaryForServerStatus(
    formatServiceSummary(s.services_summary || {}),
    String(s.status || "")
  );
  return `
    <td><span class="table-cell-truncate" title="${SM.escapeHtml(String(s.name ?? ""))}">${SM.escapeHtml(s.name)}</span></td>
    <td>${SM.escapeHtml(s.location ?? "-")}</td>
    <td class="font-mono">${SM.escapeHtml(formatUptime(s.uptime))}</td>
    <td>
      <div class="cpu-cell">
        <div class="cpu-value font-mono">${SM.escapeHtml(Number(s.cpu_load || 0).toFixed(2))}</div>
        ${SM.getCpuSparkline(s)}
      </div>
    </td>
    <td>${SM.renderUsageCell(s.ram_used, s.ram_total, "RAM")}</td>
    <td>${SM.renderUsageCell(s.hdd_used, s.hdd_total, "Disk")}</td>
    <td class="d-none d-xl-table-cell"><code>${SM.escapeHtml(s.panel_profile ?? "generic")}</code></td>
    <td>${renderServiceSummary(serviceSummary)}</td>
    <td>
      <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i> <span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_in_bps))}</span></div>
      <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i> <span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_out_bps))}</span></div>
    </td>
    <td class="font-mono">${SM.escapeHtml(SM.formatMailQueue(s.mail_mta, s.mail_queue_total))}</td>
    <td><span class="badge ${SM.statusClass(s.status)} text-uppercase">${SM.escapeHtml(s.status ?? "pending")}</span></td>
  `;
}

function syncAdminTableRows(tableBody, servers) {
  const fragment = document.createDocumentFragment();
  const existingRows = new Map();
  tableBody.querySelectorAll("tr[data-server-id]").forEach((row) => {
    existingRows.set(row.getAttribute("data-server-id"), row);
  });

  servers.forEach((s) => {
    const key = String(s.id ?? s.name ?? "");
    let row = existingRows.get(key);
    if (!row) {
      row = document.createElement("tr");
      row.setAttribute("data-server-id", key);
    }
    const detailBase =
      window.SERVMON_ADMIN_DETAIL_BASE || "/admin/server-detail.php?id=";
    row.setAttribute(
      "data-detail-url",
      `${detailBase}${encodeURIComponent(String(s.id ?? ""))}`
    );
    row.classList.add("dashboard-row-link");
    row.setAttribute("tabindex", "0");
    row.setAttribute("role", "link");
    row.setAttribute(
      "aria-label",
      `Open details for ${String(s.name ?? "server")}`
    );
    const nextHtml = renderAdminRowCells(s);
    if (row.dataset.renderedHtml !== nextHtml) {
      if (!row.hasChildNodes()) {
        row.innerHTML = nextHtml;
      } else {
        const tempRow = document.createElement("tr");
        tempRow.innerHTML = nextHtml;
        const oldCells = Array.from(row.children);
        const newCells = Array.from(tempRow.children);
        for (let i = 0; i < newCells.length; i++) {
          if (oldCells[i] && oldCells[i].innerHTML !== newCells[i].innerHTML) {
            oldCells[i].innerHTML = newCells[i].innerHTML;
          }
        }
      }
      row.dataset.renderedHtml = nextHtml;
    }
    fragment.appendChild(row);
  });

  tableBody.replaceChildren(fragment);
}

function wireDashboardRowNavigation() {
  const tableBody = document.querySelector("[data-server-table]");
  if (!tableBody) return;

  tableBody.addEventListener("click", (event) => {
    const row = event.target.closest("tr[data-detail-url]");
    if (!row) return;
    const detailUrl = row.getAttribute("data-detail-url");
    if (!detailUrl) return;
    window.location.href = detailUrl;
  });

  tableBody.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const row = event.target.closest("tr[data-detail-url]");
    if (!row) return;
    const detailUrl = row.getAttribute("data-detail-url");
    if (!detailUrl) return;
    event.preventDefault();
    window.location.href = detailUrl;
  });
}

async function refreshServerTable() {
  const tableBody = document.querySelector("[data-server-table]");
  if (!tableBody) return;
  const endpoint =
    window.SERVMON_API_STATUS || "/api/status.php?include_inactive=1";

  try {
    const response = await fetch(endpoint, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) {
      updateStaleHint("Live update unavailable", true);
      return;
    }
    const servers = await response.json();
    if (!Array.isArray(servers)) {
      updateStaleHint("Live data format invalid", true);
      return;
    }
    servers.sort((a, b) =>
      String(a.name ?? "").localeCompare(String(b.name ?? ""), undefined, {
        sensitivity: "base",
      })
    );

    syncAdminTableRows(tableBody, servers);
    SM.persistCpuHistory(CPU_HISTORY_KEY);

    updateAdminSummaryCards(servers);
    updateStaleHint(`Live | ${new Date().toLocaleTimeString()}`, false);
  } catch (err) {
    console.error(err);
    updateStaleHint("Live update error", true);
  }
}

refreshServerTable();
wireDashboardRowNavigation();
setInterval(refreshServerTable, 15000);
