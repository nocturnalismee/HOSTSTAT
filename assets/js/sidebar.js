(() => {
  const body = document.body;
  const desktopBtn = document.querySelector("[data-sidebar-toggle-desktop]");
  const desktopIcon = document.querySelector("[data-sidebar-toggle-icon]");
  const mobileBtn = document.querySelector("[data-sidebar-toggle-mobile]");
  const overlay = document.querySelector("[data-sidebar-overlay]");
  const stateKey = "servmon_sidebar_collapsed";
  const cookieKey = "servmon_sidebar_collapsed";

  if (!desktopBtn && !mobileBtn) return;

  const getCookie = (name) => {
    const encodedName = `${encodeURIComponent(name)}=`;
    const parts = document.cookie ? document.cookie.split(";") : [];
    for (const part of parts) {
      const trimmed = part.trim();
      if (trimmed.startsWith(encodedName)) {
        return decodeURIComponent(trimmed.slice(encodedName.length));
      }
    }
    return null;
  };

  const getPersistedCollapsed = () => {
    try {
      const saved = localStorage.getItem(stateKey);
      if (saved === "1" || saved === "0") return saved === "1";
    } catch (err) {
      // Ignore storage access errors and fallback to cookie.
    }
    return getCookie(cookieKey) === "1";
  };

  const setPersistedCollapsed = (collapsed) => {
    const value = collapsed ? "1" : "0";
    try {
      localStorage.setItem(stateKey, value);
    } catch (err) {
      // Ignore storage access errors.
    }
    document.cookie = `${encodeURIComponent(cookieKey)}=${value}; path=/; max-age=31536000; samesite=lax`;
  };

  if (getPersistedCollapsed()) {
    body.classList.add("sidebar-collapsed");
  }

  const syncDesktopToggleState = () => {
    if (!desktopBtn) return;
    const collapsed = body.classList.contains("sidebar-collapsed");
    const label = collapsed ? "Expand sidebar" : "Collapse sidebar";
    desktopBtn.setAttribute("title", label);
    desktopBtn.setAttribute("aria-label", label);
    if (desktopIcon) {
      desktopIcon.classList.toggle("ti-chevron-left", !collapsed);
      desktopIcon.classList.toggle("ti-chevron-right", collapsed);
    }
  };

  syncDesktopToggleState();

  desktopBtn?.addEventListener("click", () => {
    body.classList.toggle("sidebar-collapsed");
    setPersistedCollapsed(body.classList.contains("sidebar-collapsed"));
    syncDesktopToggleState();
  });

  mobileBtn?.addEventListener("click", () => {
    body.classList.toggle("sidebar-open-mobile");
  });

  overlay?.addEventListener("click", () => {
    body.classList.remove("sidebar-open-mobile");
  });

  document.querySelectorAll(".servmon-sidebar .nav-link").forEach((link) => {
    link.addEventListener("click", () => {
      body.classList.remove("sidebar-open-mobile");
    });
  });
})();
