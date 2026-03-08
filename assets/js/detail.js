const servmonCharts = {
  ram: null,
  disk: null,
  cpu: null,
  network: null,
};

let servmonHistoryRequest = null;
let servmonHistorySeq = 0;
let servmonLastRows = [];
let servmonChartEngineRetryTimer = null;
let servmonResizeBound = false;

function hasEcharts() {
  return typeof window !== "undefined" && typeof window.echarts !== "undefined";
}

function toNumber(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function parseTimestampMs(ts) {
  if (!ts) return null;
  const text = String(ts).trim();
  const ms = Date.parse(text.includes("T") ? text : text.replace(" ", "T"));
  return Number.isFinite(ms) ? ms : null;
}

function formatBytes(bytes, decimals = 2) {
  const n = toNumber(bytes, 0);
  if (n <= 0) return "0 B";
  if (n < 1) return `${n.toFixed(decimals)} B`;
  const units = ["B", "KB", "MB", "GB", "TB", "PB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
  const value = n / Math.pow(1024, i);
  return `${value.toFixed(i === 0 ? 0 : decimals)} ${units[i]}`;
}

function formatBps(bps, decimals = 2) {
  const n = toNumber(bps, 0);
  if (n <= 0) return "0 bps";
  if (n < 1) return `${n.toFixed(decimals)} bps`;
  const units = ["bps", "Kbps", "Mbps", "Gbps", "Tbps"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1000)));
  const value = n / Math.pow(1000, i);
  return `${value.toFixed(i === 0 ? 0 : decimals)} ${units[i]}`;
}

function formatTimeTick(ts) {
  const ms = toNumber(ts, NaN);
  if (!Number.isFinite(ms)) return "";
  const d = new Date(ms);
  const hh = String(d.getHours()).padStart(2, "0");
  const mm = String(d.getMinutes()).padStart(2, "0");
  // Tampilkan tanggal saat pergantian hari agar tidak muncul label ambigu seperti "4".
  if (d.getHours() === 0 && d.getMinutes() === 0) {
    const dd = String(d.getDate()).padStart(2, "0");
    const mon = String(d.getMonth() + 1).padStart(2, "0");
    return `${dd}/${mon} ${hh}:${mm}`;
  }
  return `${hh}:${mm}`;
}

function getThemeColor(varName, fallback) {
  const value = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
  return value || fallback;
}

function getChartPalette() {
  const theme = document.documentElement.getAttribute("data-bs-theme") || "dark";
  const isLight = theme === "light";
  return {
    text: getThemeColor("--sv-text", isLight ? "#0f172a" : "#e5e7eb"),
    textMuted: getThemeColor("--sv-muted", isLight ? "#475569" : "#94a3b8"),
    grid: isLight ? "rgba(15,23,42,0.08)" : "rgba(148,163,184,0.14)",
    axis: isLight ? "rgba(15,23,42,0.18)" : "rgba(148,163,184,0.22)",
    tooltipBg: getThemeColor("--sv-surface-2", isLight ? "#f1f5f9" : "#1f2937"),
    tooltipBorder: getThemeColor("--sv-border", isLight ? "#cbd5e1" : "#334155"),
    tooltipText: getThemeColor("--sv-text", isLight ? "#0f172a" : "#e5e7eb"),
    series1: getThemeColor("--sv-chart-1", "#38bdf8"),
    series2: getThemeColor("--sv-chart-2", "#f59e0b"),
    series3: getThemeColor("--sv-chart-3", "#ef4444"),
    series4: getThemeColor("--sv-chart-4", "#22c55e"),
    series5: getThemeColor("--sv-chart-5", "#a78bfa"),
  };
}

function downsample(rows, maxPoints = 720) {
  if (!Array.isArray(rows) || rows.length <= maxPoints) return rows;
  const step = Math.ceil(rows.length / maxPoints);
  return rows.filter((_, i) => i % step === 0 || i === rows.length - 1);
}

function normalizeRows(payload) {
  if (!Array.isArray(payload)) return [];
  const rows = payload.map((row) => {
    const recorded_at = String(row.recorded_at || "");
    return {
      recorded_at,
      ts: parseTimestampMs(recorded_at),
      ram_used: toNumber(row.ram_used, 0),
      hdd_used: toNumber(row.hdd_used, 0),
      cpu_load: toNumber(row.cpu_load, 0),
      network_in_bps: toNumber(row.network_in_bps, 0),
      network_out_bps: toNumber(row.network_out_bps, 0),
    };
  });
  rows.sort((a, b) => {
    if (a.ts === null && b.ts === null) return 0;
    if (a.ts === null) return -1;
    if (b.ts === null) return 1;
    return a.ts - b.ts;
  });
  return downsample(rows, 720);
}

function updateCpuSummary(rows) {
  const highEl = document.getElementById("cpuLoadHigh");
  const lowEl = document.getElementById("cpuLoadLow");
  const dailyAvgEl = document.getElementById("cpuLoadDailyAvg");
  if (!highEl || !lowEl || !dailyAvgEl) return;

  if (!Array.isArray(rows) || rows.length === 0) {
    highEl.textContent = "0.00";
    lowEl.textContent = "0.00";
    dailyAvgEl.textContent = "0.00";
    return;
  }

  const latestRow = rows[rows.length - 1];
  const latestDateKey =
    latestRow && latestRow.ts !== null
      ? new Date(latestRow.ts).toLocaleDateString("sv-SE")
      : String(latestRow?.recorded_at || "").slice(0, 10);

  const dayRows = rows.filter((row) => {
    if (row.ts !== null) {
      return new Date(row.ts).toLocaleDateString("sv-SE") === latestDateKey;
    }
    return String(row.recorded_at || "").slice(0, 10) === latestDateKey;
  });

  const sampleRows = dayRows.length > 0 ? dayRows : rows;
  const cpuValues = sampleRows.map((r) => toNumber(r.cpu_load, 0));
  const high = cpuValues.reduce((max, v) => Math.max(max, v), 0);
  const low = cpuValues.reduce(
    (min, v) => (Number.isFinite(min) ? Math.min(min, v) : v),
    Number.POSITIVE_INFINITY,
  );
  const sum = cpuValues.reduce((acc, v) => acc + v, 0);
  const dailyAverage = cpuValues.length > 0 ? sum / cpuValues.length : 0;

  highEl.textContent = high.toFixed(2);
  lowEl.textContent = (Number.isFinite(low) ? low : 0).toFixed(2);
  dailyAvgEl.textContent = dailyAverage.toFixed(2);
}

function toSeriesData(rows, key) {
  return rows
    .filter((row) => row.ts !== null)
    .map((row) => [row.ts, toNumber(row[key], 0)]);
}

function getOrCreateChart(chartKey, containerId) {
  const container = document.getElementById(containerId);
  if (!container || !hasEcharts()) return null;

  if (servmonCharts[chartKey] && !servmonCharts[chartKey].isDisposed()) {
    return servmonCharts[chartKey];
  }

  servmonCharts[chartKey] = window.echarts.init(container);
  return servmonCharts[chartKey];
}

function buildEchartsLineOption({
  palette,
  series,
  yFormatter,
  tooltipFormatter,
  integerAxis = false,
}) {
  return {
    animation: false,
    grid: { top: 22, right: 16, bottom: 42, left: 12, containLabel: true },
    legend: {
      top: 0,
      textStyle: { color: palette.text },
      itemWidth: 12,
      itemHeight: 8,
      data: series.map((s) => s.name),
    },
    tooltip: {
      trigger: "axis",
      confine: true,
      backgroundColor: palette.tooltipBg,
      borderColor: palette.tooltipBorder,
      textStyle: { color: palette.tooltipText },
      formatter: (params) => {
        const items = Array.isArray(params) ? params : [params];
        if (items.length === 0) return "";
        const ts = items[0].value?.[0] ?? items[0].axisValue;
        const header = new Date(ts).toLocaleString("id-ID", {
          hour12: false,
          year: "numeric",
          month: "2-digit",
          day: "2-digit",
          hour: "2-digit",
          minute: "2-digit",
        });
        const lines = items.map((item) => {
          const value = Array.isArray(item.value) ? item.value[1] : item.value;
          return `${item.marker} ${item.seriesName}: <b>${tooltipFormatter(value)}</b>`;
        });
        return `${header}<br/>${lines.join("<br/>")}`;
      },
    },
    xAxis: {
      type: "time",
      axisLine: { lineStyle: { color: palette.axis } },
      axisLabel: {
        color: palette.textMuted,
        formatter: (value) => formatTimeTick(value),
      },
      splitLine: { lineStyle: { color: palette.grid } },
    },
    yAxis: {
      type: "value",
      min: 0,
      axisLine: { lineStyle: { color: palette.axis } },
      axisLabel: {
        color: palette.textMuted,
        formatter: (value) => yFormatter(value),
      },
      splitLine: { lineStyle: { color: palette.grid } },
      ...(integerAxis ? { minInterval: 1 } : {}),
    },
    dataZoom: [
      { type: "inside", xAxisIndex: 0, filterMode: "none" },
      { type: "inside", yAxisIndex: 0, filterMode: "none" },
    ],
    series: series.map((item) => ({
      name: item.name,
      type: "line",
      showSymbol: false,
      smooth: false,
      lineStyle: { width: 2, color: item.color },
      itemStyle: { color: item.color },
      connectNulls: false,
      data: item.data,
    })),
  };
}

function renderCharts(rows) {
  if (!hasEcharts()) return;

  const palette = getChartPalette();
  const ramData = toSeriesData(rows, "ram_used");
  const diskData = toSeriesData(rows, "hdd_used");
  const cpuData = toSeriesData(rows, "cpu_load");
  const netInData = toSeriesData(rows, "network_in_bps");
  const netOutData = toSeriesData(rows, "network_out_bps");

  const ramChart = getOrCreateChart("ram", "ramHistoryChart");
  if (ramChart) {
    ramChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [{ name: "RAM Used", data: ramData, color: palette.series1 }],
        yFormatter: formatBytes,
        tooltipFormatter: formatBytes,
      }),
      true,
    );
  }

  const diskChart = getOrCreateChart("disk", "diskHistoryChart");
  if (diskChart) {
    diskChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [{ name: "Disk Used", data: diskData, color: palette.series2 }],
        yFormatter: formatBytes,
        tooltipFormatter: formatBytes,
      }),
      true,
    );
  }

  const cpuChart = getOrCreateChart("cpu", "cpuHistoryChart");
  if (cpuChart) {
    cpuChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [{ name: "CPU Load", data: cpuData, color: palette.series3 }],
        yFormatter: (v) => toNumber(v, 0).toFixed(2),
        tooltipFormatter: (v) => toNumber(v, 0).toFixed(2),
      }),
      true,
    );
  }

  const networkChart = getOrCreateChart("network", "networkHistoryChart");
  if (networkChart) {
    networkChart.setOption(
      buildEchartsLineOption({
        palette,
        series: [
          { name: "Network In", data: netInData, color: palette.series4 },
          { name: "Network Out", data: netOutData, color: palette.series5 },
        ],
        yFormatter: formatBps,
        tooltipFormatter: formatBps,
        integerAxis: true,
      }),
      true,
    );
  }

  updateCpuSummary(rows);
}

function scheduleChartRenderRetry() {
  if (servmonChartEngineRetryTimer !== null) return;

  servmonChartEngineRetryTimer = window.setInterval(() => {
    if (!Array.isArray(servmonLastRows) || servmonLastRows.length === 0) return;
    if (!hasEcharts()) return;
    window.clearInterval(servmonChartEngineRetryTimer);
    servmonChartEngineRetryTimer = null;
    renderCharts(servmonLastRows);
  }, 200);

  window.setTimeout(() => {
    if (servmonChartEngineRetryTimer === null) return;
    window.clearInterval(servmonChartEngineRetryTimer);
    servmonChartEngineRetryTimer = null;
  }, 20000);
}

function bindChartResize() {
  if (servmonResizeBound) return;
  servmonResizeBound = true;

  window.addEventListener("resize", () => {
    Object.values(servmonCharts).forEach((chart) => {
      if (chart && typeof chart.resize === "function") {
        chart.resize();
      }
    });
  });
}

function resetHistoryZoom() {
  Object.values(servmonCharts).forEach((chart) => {
    if (!chart || typeof chart.setOption !== "function") return;
    chart.setOption({
      dataZoom: [
        { start: 0, end: 100 },
        { start: 0, end: 100 },
      ],
    });
  });
}

function bootstrapHistory(payload) {
  if (!Array.isArray(payload) || payload.length === 0) return;
  servmonLastRows = normalizeRows(payload);
  renderCharts(servmonLastRows);
  updateCpuSummary(servmonLastRows);
  bindChartResize();
}

async function loadHistory(endpoint) {
  if (!endpoint) return;

  const seq = ++servmonHistorySeq;
  if (servmonHistoryRequest) {
    servmonHistoryRequest.abort();
  }
  servmonHistoryRequest = new AbortController();

  let payload = [];
  let timeoutId = null;
  try {
    timeoutId = setTimeout(() => {
      if (servmonHistoryRequest) {
        servmonHistoryRequest.abort();
      }
    }, 10000);

    const url = endpoint.includes("?") ? `${endpoint}&_t=${Date.now()}` : `${endpoint}?_t=${Date.now()}`;
    const response = await fetch(url, {
      headers: { Accept: "application/json" },
      signal: servmonHistoryRequest.signal,
      cache: "no-store",
    });
    if (!response.ok) return;
    payload = await response.json();
  } catch (err) {
    if (err && err.name !== "AbortError") {
      console.error(err);
    }
    return;
  } finally {
    if (timeoutId) {
      clearTimeout(timeoutId);
    }
  }

  if (seq !== servmonHistorySeq) return;

  servmonLastRows = normalizeRows(payload);
  renderCharts(servmonLastRows);
  updateCpuSummary(servmonLastRows);

  if (!hasEcharts()) {
    scheduleChartRenderRetry();
  }

  bindChartResize();
}

document.addEventListener("click", (event) => {
  const target = event.target.closest("[data-reset-zoom]");
  if (!target) return;
  resetHistoryZoom();
});

document.addEventListener("servmon:theme-changed", () => {
  if (!Array.isArray(servmonLastRows) || servmonLastRows.length === 0) return;
  renderCharts(servmonLastRows);
  updateCpuSummary(servmonLastRows);
});
