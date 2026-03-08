const themeMediaQuery =
  typeof window !== "undefined" && typeof window.matchMedia === "function"
    ? window.matchMedia("(prefers-color-scheme: dark)")
    : null;

function getStoredTheme() {
  try {
    const stored = localStorage.getItem("servmon_theme");
    return stored === "dark" || stored === "light" ? stored : null;
  } catch (e) {
    return null;
  }
}

function getPreferredTheme() {
  const stored = getStoredTheme();
  if (stored) return stored;
  return themeMediaQuery && themeMediaQuery.matches ? "dark" : "light";
}

function applyTheme(theme) {
  const html = document.documentElement;
  html.setAttribute("data-bs-theme", theme);
  document
    .querySelectorAll("[data-theme-toggle]")
    .forEach((el) => setThemeLabel(el, theme));
  document.dispatchEvent(new CustomEvent("servmon:theme-changed", { detail: { theme } }));
}

document.addEventListener("click", (event) => {
  const btn = event.target.closest("[data-theme-toggle]");
  if (!btn) return;

  const current = document.documentElement.getAttribute("data-bs-theme") || getPreferredTheme();
  const next = current === "dark" ? "light" : "dark";
  try {
    localStorage.setItem("servmon_theme", next);
  } catch (e) {}
  applyTheme(next);
});

applyTheme(getPreferredTheme());

if (themeMediaQuery) {
  themeMediaQuery.addEventListener("change", () => {
    if (getStoredTheme()) return;
    applyTheme(themeMediaQuery.matches ? "dark" : "light");
  });
}

function setThemeLabel(btn, currentTheme) {
  const label = currentTheme === "dark" ? "Light Mode" : "Dark Mode";
  const iconClass = currentTheme === "dark" ? "ti-brightness-down" : "ti-moon-2";
  const iconNode = btn.querySelector("[data-theme-toggle-icon], i");
  const labelNode = btn.querySelector(".sidebar-label");

  btn.setAttribute("title", label);
  btn.setAttribute("aria-label", label);

  if (iconNode) {
    iconNode.classList.remove("ti-contrast-2", "ti-moon-2", "ti-brightness-down");
    iconNode.classList.add("ti", iconClass);
  }

  if (labelNode) {
    labelNode.textContent = label;
  } else {
    if (!iconNode) {
      btn.textContent = label;
    }
  }
}
