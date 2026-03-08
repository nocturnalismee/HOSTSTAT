/**
 * ServMon — Public status page auto-refresh.
 *
 * Depends on: common.js (ServMon namespace)
 */

const SM = window.ServMon;
const CPU_HISTORY_KEY = "servmon:cpuHistory:public";

SM.restoreCpuHistory(CPU_HISTORY_KEY);

function formatUptime(totalSeconds) {
  const seconds = Math.max(0, Math.trunc(Number(totalSeconds || 0)));
  if (seconds <= 0) return "0m";
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h ${minutes}m`;
  return `${minutes}m`;
}

function updateSummaryCards(servers) {
  const totalEl = document.querySelector("[data-public-total]");
  const onlineEl = document.querySelector("[data-public-online]");
  const downEl = document.querySelector("[data-public-down]");
  const pendingEl = document.querySelector("[data-public-pending]");
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

function renderPublicRowCells(s) {
  return `
    <td><span class="table-cell-truncate" title="${SM.escapeHtml(String(s.name ?? ""))}">${SM.escapeHtml(s.name)}</span></td>
    <td>${SM.escapeHtml(s.location ?? "-")}</td>
    <td><span class="badge ${SM.statusClass(s.status)} text-uppercase">${SM.escapeHtml(s.status ?? "pending")}</span></td>
    <td class="font-mono">${SM.escapeHtml(formatUptime(s.uptime))}</td>
    <td>${SM.renderUsageCell(s.ram_used, s.ram_total, "RAM")}</td>
    <td>${SM.renderUsageCell(s.hdd_used, s.hdd_total, "Disk")}</td>
    <td>
      <div class="cpu-cell">
        <div class="cpu-value font-mono">${SM.escapeHtml(Number(s.cpu_load || 0).toFixed(2))}</div>
        ${SM.getCpuSparkline(s)}
      </div>
    </td>
    <td>
      <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i><span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_in_bps))}</span></div>
      <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i><span class="font-mono">${SM.escapeHtml(SM.formatBps(s.network_out_bps))}</span></div>
    </td>
    <td class="font-mono">${SM.escapeHtml(SM.formatMailQueue(s.mail_mta, s.mail_queue_total))}</td>
  `;
}

function syncPublicTableRows(tableBody, servers) {
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
    const nextHtml = renderPublicRowCells(s);
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

async function refreshPublicStatusTable() {
  const tableBody = document.querySelector("[data-public-server-table]");
  if (!tableBody) return;

  const endpoint = window.SERVMON_API_STATUS || "/api/status.php";
  try {
    const response = await fetch(endpoint, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) return;

    const servers = await response.json();
    if (!Array.isArray(servers)) return;
    servers.sort((a, b) =>
      String(a.name ?? "").localeCompare(String(b.name ?? ""), undefined, {
        sensitivity: "base",
      })
    );

    syncPublicTableRows(tableBody, servers);
    SM.persistCpuHistory(CPU_HISTORY_KEY);

    updateSummaryCards(servers);
  } catch (err) {
    console.error(err);
  }
}

refreshPublicStatusTable();
setInterval(refreshPublicStatusTable, 15000);
