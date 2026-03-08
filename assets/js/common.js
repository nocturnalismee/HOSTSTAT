/**
 * ServMon shared utility functions.
 *
 * Used by both public.js and dashboard.js to avoid code duplication.
 * Exposes functions on the global `ServMon` namespace.
 */
window.ServMon = window.ServMon || {};

(function (ns) {
  "use strict";

  // ── HTML escaping ──────────────────────────────────────────────────
  ns.escapeHtml = function (input) {
    const div = document.createElement("div");
    div.textContent = input ?? "";
    return div.innerHTML;
  };

  // ── Formatting helpers ─────────────────────────────────────────────
  ns.formatBytes = function (bytes) {
    if (!bytes || Number(bytes) <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const value = bytes / Math.pow(1024, i);
    return `${value.toFixed(i === 0 ? 0 : 2)} ${units[i]}`;
  };

  ns.formatBps = function (bps) {
    const value = Number(bps ?? 0);
    if (!Number.isFinite(value) || value <= 0) return "0 bps";
    const units = ["bps", "Kb", "Mb", "Gb", "Tb"];
    let scaled = value;
    let unitIndex = 0;
    while (scaled >= 1000 && unitIndex < units.length - 1) {
      scaled /= 1000;
      unitIndex += 1;
    }
    return `${scaled.toFixed(unitIndex === 0 ? 0 : 2)} ${units[unitIndex]}`;
  };

  ns.formatMailQueue = function (mailMta, mailQueueTotal) {
    const queue = Number(mailQueueTotal ?? 0);
    return `${Number.isFinite(queue) ? Math.max(0, Math.trunc(queue)) : 0}`;
  };

  // ── Status helpers ─────────────────────────────────────────────────
  ns.statusClass = function (status) {
    if (status === "online") return "badge-online";
    if (status === "down") return "badge-down";
    return "badge-pending";
  };

  ns.usagePercent = function (used, total) {
    const usedNumber = Number(used || 0);
    const totalNumber = Number(total || 0);
    if (!Number.isFinite(totalNumber) || totalNumber <= 0) return 0;
    const pct = (usedNumber / totalNumber) * 100;
    return Math.max(0, Math.min(100, pct));
  };

  ns.usageClass = function (percent) {
    if (percent >= 80) return "is-critical";
    if (percent > 60) return "is-warning";
    return "is-ok";
  };

  ns.renderUsageCell = function (used, total, label) {
    const safeUsed = Number.isFinite(Number(used))
      ? Math.max(0, Number(used))
      : 0;
    const safeTotal = Number.isFinite(Number(total))
      ? Math.max(0, Number(total))
      : 0;
    const pct = ns.usagePercent(safeUsed, safeTotal);
    const pctText = pct.toFixed(1);
    return `
      <div class="resource-cell">
        <div class="resource-label">
          <span>${ns.escapeHtml(`${ns.formatBytes(safeUsed)} / ${ns.formatBytes(safeTotal)}`)}</span>
          <span>${ns.escapeHtml(`${pctText}%`)}</span>
        </div>
        <div class="progress resource-progress" role="progressbar" aria-label="${ns.escapeHtml(label)} usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${ns.escapeHtml(pctText)}">
          <div class="progress-bar resource-progress-bar ${ns.usageClass(pct)}" style="--target-width:${ns.escapeHtml(pctText)}%"></div>
        </div>
      </div>
    `;
  };

  // ── CPU Sparkline ──────────────────────────────────────────────────

  /** @type {Map<string, {last_seen: string|null, values: number[]}>} */
  const cpuHistory = new Map();

  /**
   * @param {string} storageKey
   */
  ns.restoreCpuHistory = function (storageKey) {
    try {
      const raw = window.sessionStorage.getItem(storageKey);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object") return;
      Object.entries(parsed).forEach(([key, value]) => {
        const values = Array.isArray(value?.values)
          ? value.values
              .map((n) => Number(n))
              .filter((n) => Number.isFinite(n))
              .slice(-20)
          : [];
        cpuHistory.set(String(key), {
          last_seen: value?.last_seen ?? null,
          values,
        });
      });
    } catch (err) {
      console.warn("Failed to restore CPU history", err);
    }
  };

  /**
   * @param {string} storageKey
   */
  ns.persistCpuHistory = function (storageKey) {
    try {
      const data = {};
      cpuHistory.forEach((value, key) => {
        data[key] = {
          last_seen: value?.last_seen ?? null,
          values: Array.isArray(value?.values) ? value.values.slice(-20) : [],
        };
      });
      window.sessionStorage.setItem(storageKey, JSON.stringify(data));
    } catch (err) {
      console.warn("Failed to persist CPU history", err);
    }
  };

  ns.getCpuSparkline = function (s) {
    const serverId = String(s.id ?? "");
    const currentLoad = Number(s.cpu_load) || 0;
    const lastSeen = s.last_seen;

    let record = cpuHistory.get(serverId);
    if (!record) {
      record = { last_seen: null, values: [] };
      cpuHistory.set(serverId, record);
    }

    if (record.last_seen !== lastSeen) {
      record.values.push(currentLoad);
      record.last_seen = lastSeen;
      if (record.values.length > 20) {
        record.values.shift();
      }
    }

    const history = record.values;

    if (history.length < 2) {
      const width = 60;
      const height = 18;
      const normalizedMax = Math.max(currentLoad, 5);
      const y =
        height - 2 - (Math.max(currentLoad, 0) / normalizedMax) * (height - 4);
      const clampedY = Number.isFinite(y)
        ? Math.max(2, Math.min(height - 2, y))
        : height - 2;
      return `<svg class="cpu-sparkline" width="${width}" height="${height}"><polyline fill="none" stroke="var(--sv-muted)" stroke-width="1.5" points="0,${clampedY.toFixed(1)} ${width},${clampedY.toFixed(1)}"/></svg>`;
    }

    const maxVal = Math.max(...history, 1.5);
    const width = 60;
    const height = 18;
    const maxPoints = 20;
    const stepX = width / (maxPoints - 1);

    let points = "";
    const startIdx = maxPoints - history.length;

    history.forEach((val, i) => {
      const x = (startIdx + i) * stepX;
      const y = height - 2 - (val / maxVal) * (height - 4);
      points += `${x.toFixed(1)},${y.toFixed(1)} `;
    });

    const latestLoad = history[history.length - 1];
    let strokeColor = "var(--sv-accent)";
    if (latestLoad >= Math.max(0.8 * maxVal, 1.0) && latestLoad > 2.0)
      strokeColor = "var(--sv-warning)";
    if (latestLoad > 5.0) strokeColor = "var(--sv-danger)";

    return `<svg class="cpu-sparkline" width="${width}" height="${height}"><polyline fill="none" stroke="${strokeColor}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" points="${points.trim()}"/></svg>`;
  };
})(window.ServMon);
