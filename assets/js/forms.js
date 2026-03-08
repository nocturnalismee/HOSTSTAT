document.addEventListener("click", (event) => {
  const target = event.target.closest("[data-confirm]");
  if (!target) return;
  const message = target.getAttribute("data-confirm") || "Continue with this action?";
  if (!window.confirm(message)) {
    event.preventDefault();
  }
});

document.addEventListener("submit", (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const button = form.querySelector("[data-submit-loading]");
  if (!button) return;
  const original = button.getAttribute("data-loading-text") || "Processing...";
  if (!button.hasAttribute("data-original-text")) {
    button.setAttribute("data-original-text", button.textContent || "");
  }
  button.disabled = true;
  button.textContent = original;
});
