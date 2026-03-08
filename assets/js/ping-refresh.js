document.addEventListener("DOMContentLoaded", () => {
  const intervalMs = Number(window.SERVMON_PING_AUTO_REFRESH_MS || 15000);
  if (!Number.isFinite(intervalMs) || intervalMs < 5000) return;

  const hasActiveFormFocus = () => {
    const el = document.activeElement;
    if (!(el instanceof HTMLElement)) return false;
    const tag = el.tagName.toLowerCase();
    return tag === "input" || tag === "select" || tag === "textarea";
  };

  window.setInterval(() => {
    if (document.hidden) return;
    if (hasActiveFormFocus()) return;
    location.reload();
  }, intervalMs);
});
