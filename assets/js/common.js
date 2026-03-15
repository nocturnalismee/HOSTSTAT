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

  /**
   * Calculates a severity score for a server (Higher = More severe).
   * Down servers: 10000+
   * High resource usage (>90%): 500+
   * Warning resource usage (>75%): 100+
   * Online and healthy: ~0
   * Pending/Unknown: negative or low score
   */
  ns.calculateSeverityScore = function (server) {
    let score = 0;
    const status = ns.determineStatus(server.last_seen, server.active);
    
    // Status carries the most weight
    if (status === "down") {
      score += 10000;
    } else if (status === "pending") {
      score -= 50; // Pending servers are least urgent unless they were down
    } else if (status === "online") {
      // Calculate resource pressure for online servers
      const ramPct = ns.usagePercent(server.ram_used, server.ram_total);
      const diskPct = ns.usagePercent(server.hdd_used, server.hdd_total);
      const cpuLoad = Number(server.cpu_load) || 0;

      // RAM severity
      if (ramPct >= 90) score += 500;
      else if (ramPct >= 80) score += 200;
      else if (ramPct >= 70) score += 50;

      // Disk severity (critical if full)
      if (diskPct >= 95) score += 600;
      else if (diskPct >= 85) score += 250;
      else if (diskPct >= 75) score += 60;

      // CPU severity (assuming typical load average scale)
      if (cpuLoad >= 4.0) score += 100;
      else if (cpuLoad >= 2.0) score += 40;
    }
    return score;
  };

  /**
   * Comparator function for sorting server list by severity, then alphabetical.
   */
  ns.compareServers = function (a, b) {
    const scoreA = ns.calculateSeverityScore(a);
    const scoreB = ns.calculateSeverityScore(b);

    if (scoreA !== scoreB) {
      return scoreB - scoreA; // Descending: Highest severity first
    }

    // Fallback: Alphabetical by name
    return String(a.name || "").localeCompare(String(b.name || ""), undefined, {
      sensitivity: "base",
    });
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
  // ── SSE Connection Manager ─────────────────────────────────────────

  /**
   * Create an SSE connection with automatic reconnect and polling fallback.
   *
   * @param {Object} config
   * @param {string} config.url                  - SSE endpoint URL
   * @param {Object} config.events               - Map of event name → handler(data)
   * @param {number} [config.fallbackInterval]    - Polling interval ms (default 15000)
   * @param {Function} [config.fallbackFn]        - Function to call for polling fallback
   * @param {Function} [config.onConnectionChange]- Callback(state: 'sse'|'polling'|'error')
   * @returns {Object} controller with destroy() method
   */
  ns.createSSEConnection = function (config) {
    const MAX_SSE_FAILURES = 3;
    const INITIAL_RETRY_MS = 1000;
    const MAX_RETRY_MS = 30000;
    const SSE_RECOVERY_CHECK_MS = 60000;

    let eventSource = null;
    let failureCount = 0;
    let retryMs = INITIAL_RETRY_MS;
    let retryTimer = null;
    let pollingTimer = null;
    let recoveryTimer = null;
    let destroyed = false;
    let mode = "connecting"; // 'sse' | 'polling' | 'connecting'
    let lastEventId = "";

    const fallbackInterval = config.fallbackInterval || 15000;
    const onConnectionChange = config.onConnectionChange || function () {};

    function setMode(newMode) {
      if (mode !== newMode) {
        mode = newMode;
        onConnectionChange(newMode);
      }
    }

    function buildUrl() {
      const base = config.url || "/api/sse.php";
      const sep = base.includes("?") ? "&" : "?";
      let url = base;
      if (lastEventId) {
        url += sep + "last_event_id=" + encodeURIComponent(lastEventId);
      }
      return url;
    }

    function connectSSE() {
      if (destroyed) return;
      if (!window.EventSource) {
        startPolling();
        return;
      }

      try {
        const url = buildUrl();
        eventSource = new EventSource(url);

        eventSource.onopen = function () {
          failureCount = 0;
          retryMs = INITIAL_RETRY_MS;
          setMode("sse");
          stopPolling();
          stopRecoveryCheck();
        };

        // Register event handlers
        if (config.events) {
          Object.keys(config.events).forEach(function (eventName) {
            eventSource.addEventListener(eventName, function (e) {
              try {
                const data = JSON.parse(e.data);
                if (e.lastEventId) {
                  lastEventId = e.lastEventId;
                }
                config.events[eventName](data);
              } catch (err) {
                console.warn("ServMon SSE parse error for event:", eventName, err);
              }
            });
          });
        }

        // Handle server-sent "end" event (max lifetime reached)
        eventSource.addEventListener("end", function () {
          closeSSE();
          scheduleReconnect();
        });

        eventSource.onerror = function () {
          failureCount++;
          closeSSE();

          if (failureCount >= MAX_SSE_FAILURES) {
            startPolling();
            startRecoveryCheck();
          } else {
            scheduleReconnect();
          }
        };
      } catch (err) {
        console.warn("ServMon SSE connection failed:", err);
        failureCount++;
        if (failureCount >= MAX_SSE_FAILURES) {
          startPolling();
          startRecoveryCheck();
        } else {
          scheduleReconnect();
        }
      }
    }

    function closeSSE() {
      if (eventSource) {
        try {
          eventSource.close();
        } catch (e) {
          /* ignore */
        }
        eventSource = null;
      }
    }

    function scheduleReconnect() {
      if (destroyed) return;
      clearTimeout(retryTimer);
      retryTimer = setTimeout(function () {
        connectSSE();
      }, retryMs);
      // Exponential backoff
      retryMs = Math.min(retryMs * 2, MAX_RETRY_MS);
    }

    function startPolling() {
      if (destroyed || pollingTimer) return;
      setMode("polling");

      if (typeof config.fallbackFn === "function") {
        // Run immediately, then on interval
        config.fallbackFn();
        pollingTimer = setInterval(function () {
          if (destroyed) return;
          if (document.hidden) return;
          config.fallbackFn();
        }, fallbackInterval);
      }
    }

    function stopPolling() {
      if (pollingTimer) {
        clearInterval(pollingTimer);
        pollingTimer = null;
      }
    }

    function startRecoveryCheck() {
      if (destroyed || recoveryTimer) return;
      recoveryTimer = setInterval(function () {
        if (destroyed) return;
        // Reset failure count and try SSE again
        failureCount = 0;
        retryMs = INITIAL_RETRY_MS;
        stopPolling();
        connectSSE();
      }, SSE_RECOVERY_CHECK_MS);
    }

    function stopRecoveryCheck() {
      if (recoveryTimer) {
        clearInterval(recoveryTimer);
        recoveryTimer = null;
      }
    }

    // Visibility API: pause/resume
    function onVisibilityChange() {
      if (destroyed) return;
      if (document.hidden) {
        // Tab hidden — close SSE to save resources
        closeSSE();
        stopPolling();
        clearTimeout(retryTimer);
      } else {
        // Tab visible again — reconnect
        failureCount = 0;
        retryMs = INITIAL_RETRY_MS;
        connectSSE();
      }
    }

    document.addEventListener("visibilitychange", onVisibilityChange);

    // Start
    connectSSE();

    // Return controller
    return {
      getMode: function () {
        return mode;
      },
      destroy: function () {
        destroyed = true;
        closeSSE();
        stopPolling();
        stopRecoveryCheck();
        clearTimeout(retryTimer);
        document.removeEventListener("visibilitychange", onVisibilityChange);
      },
    };
  };
})(window.ServMon);
