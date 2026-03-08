function parseTimestampMs(input) {
  if (!input) return null;
  const text = String(input).trim();
  const ms = Date.parse(text.includes("T") ? text : text.replace(" ", "T"));
  return Number.isFinite(ms) ? ms : null;
}

function getThemeColor(varName, fallback) {
  const css = getComputedStyle(document.documentElement);
  const value = css.getPropertyValue(varName).trim();
  return value || fallback;
}

function getChartPalette() {
  const theme = document.documentElement.getAttribute("data-bs-theme") || "dark";
  const isLight = theme === "light";
  return {
    text: getThemeColor("--sv-text", isLight ? "#0f172a" : "#e9e9e9"),
    muted: getThemeColor("--sv-muted", isLight ? "#475569" : "#9a9a9a"),
    grid: isLight ? "rgba(15,23,42,0.08)" : "rgba(148,163,184,0.16)",
    axis: isLight ? "rgba(15,23,42,0.2)" : "rgba(148,163,184,0.25)",
    surface: getThemeColor("--sv-surface-2", isLight ? "#f1f5f9" : "#232323"),
    border: getThemeColor("--sv-border", isLight ? "#cbd5e1" : "#333333"),
    accent: getThemeColor("--sv-chart-1", "#2dd4bf"),
    danger: getThemeColor("--sv-danger", "#ef4444"),
  };
}

function formatTimeLabel(ms) {
  if (!Number.isFinite(ms)) return "-";
  const d = new Date(ms);
  const hh = String(d.getHours()).padStart(2, "0");
  const mm = String(d.getMinutes()).padStart(2, "0");
  const prevDay = new Date(ms - 60 * 1000).getDate();
  if (d.getDate() !== prevDay) {
    const dd = String(d.getDate()).padStart(2, "0");
    const mon = String(d.getMonth() + 1).padStart(2, "0");
    return `${dd}/${mon} ${hh}:${mm}`;
  }
  return `${hh}:${mm}`;
}

function normalizeRows(payload) {
  if (!Array.isArray(payload)) return [];
  return payload
    .map((row) => {
      const ts = parseTimestampMs(row.checked_at);
      const status = String(row.status || "down");
      const latency = Number(row.latency_ms);
      return {
        ts,
        status,
        latency: Number.isFinite(latency) ? latency : null,
      };
    })
    .filter((row) => row.ts !== null)
    .sort((a, b) => a.ts - b.ts);
}

let pingHistoryChart = null;
let pingChartRetryTimer = null;

function renderPingHistory() {
  const container = document.getElementById("pingHistoryChart");
  if (!container) return;
  if (typeof window.echarts === "undefined") {
    schedulePingChartRetry();
    return;
  }

  const rows = normalizeRows(window.SERVMON_PING_HISTORY);
  if (rows.length === 0) return;

  const palette = getChartPalette();
  const labels = rows.map((row) => formatTimeLabel(row.ts));
  const seriesData = rows.map((row) => ({
    value: row.status === "up" ? Number(row.latency || 0) : 0,
    itemStyle: {
      color: row.status === "up" ? palette.accent : palette.danger,
      opacity: row.status === "up" ? 0.9 : 0.65,
    },
  }));

  if (!pingHistoryChart || pingHistoryChart.isDisposed()) {
    pingHistoryChart = window.echarts.init(container);
    window.addEventListener("resize", () => {
      if (pingHistoryChart && typeof pingHistoryChart.resize === "function") {
        pingHistoryChart.resize();
      }
    });
  }

  pingHistoryChart.setOption(
    {
      animation: false,
      grid: { top: 20, right: 14, bottom: 52, left: 56, containLabel: true },
      legend: {
        top: 0,
        textStyle: { color: palette.text },
        data: ["Latency"],
      },
      tooltip: {
        trigger: "axis",
        axisPointer: { type: "shadow" },
        confine: true,
        backgroundColor: palette.surface,
        borderColor: palette.border,
        textStyle: { color: palette.text },
        formatter: (params) => {
          const items = Array.isArray(params) ? params : [params];
          if (items.length === 0) return "";
          const idx = Number(items[0].dataIndex);
          const row = rows[idx];
          if (!row) return "";
          const time = new Date(row.ts).toLocaleString("id-ID", { hour12: false });
          const value = row.status === "up" && row.latency !== null
            ? `${Number(row.latency).toFixed(2)} ms`
            : "DOWN";
          return `${time}<br/>${items[0].marker} Latency: <b>${value}</b>`;
        },
      },
      xAxis: {
        type: "category",
        data: labels,
        boundaryGap: true,
        axisTick: {
          alignWithLabel: true,
        },
        axisLine: { lineStyle: { color: palette.axis } },
        axisLabel: {
          color: palette.muted,
          hideOverlap: true,
        },
        splitLine: { show: false },
      },
      yAxis: {
        type: "value",
        min: 0,
        axisLine: { lineStyle: { color: palette.axis } },
        axisLabel: {
          color: palette.muted,
          formatter: (value) => `${value} ms`,
        },
        splitLine: { lineStyle: { color: palette.grid } },
      },
      dataZoom: [
        { type: "inside", xAxisIndex: 0, filterMode: "none" },
        { type: "slider", xAxisIndex: 0, height: 14, bottom: 6 },
      ],
      series: [
        {
          name: "Latency",
          type: "bar",
          data: seriesData,
          barMaxWidth: 12,
          barMinHeight: 1,
        },
      ],
    },
    true,
  );
}

function schedulePingChartRetry() {
  if (pingChartRetryTimer !== null) return;
  pingChartRetryTimer = window.setInterval(() => {
    if (typeof window.echarts === "undefined") return;
    window.clearInterval(pingChartRetryTimer);
    pingChartRetryTimer = null;
    renderPingHistory();
  }, 200);

  window.setTimeout(() => {
    if (pingChartRetryTimer === null) return;
    window.clearInterval(pingChartRetryTimer);
    pingChartRetryTimer = null;
  }, 20000);
}

document.addEventListener("DOMContentLoaded", renderPingHistory);
document.addEventListener("servmon:theme-changed", renderPingHistory);
window.addEventListener("load", renderPingHistory);
