(() => {
  const loader = document.createElement("div");
  loader.className = "navigation-loader";
  loader.setAttribute("aria-hidden", "true");
  loader.innerHTML = `
    <div class="navigation-loader__screen skeleton-screen" role="status" aria-live="polite">
      <aside class="skeleton-sidebar" aria-hidden="true">
        <div class="skeleton-logo"></div>
        <div class="skeleton-nav">
          <div class="skeleton-line long"></div>
          <div class="skeleton-line long"></div>
          <div class="skeleton-line medium"></div>
          <div class="skeleton-line long"></div>
        </div>
      </aside>
      <main class="skeleton-main" aria-hidden="true">
        <div class="skeleton-topbar"></div>
        <div class="skeleton-content">
          <div class="skeleton-copy">
            <div class="skeleton-line short"></div>
            <div class="skeleton-line medium"></div>
            <div class="skeleton-line long"></div>
            <div class="skeleton-line medium"></div>
          </div>
          <div class="skeleton-card skeleton-hero-card"></div>
        </div>
        <div class="skeleton-metrics">
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
        </div>
        <div class="skeleton-cards">
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
        </div>
      </main>
    </div>`;
  document.body.append(loader);

  const updateAccessibilityLabel = () => {
    let language = "en";
    try {
      language = localStorage.getItem("smart-gym-language") || document.documentElement.lang || "en";
    } catch {
      language = document.documentElement.lang || "en";
    }
    loader.firstElementChild.setAttribute("aria-label", language === "ar" ? "جارٍ تحميل الصفحة" : "Loading page");
  };

  const showLoader = () => {
    updateAccessibilityLabel();
    loader.classList.add("is-visible");
    loader.setAttribute("aria-hidden", "false");
  };

  window.navigateWithLoader = (url) => {
    showLoader();
    window.setTimeout(() => window.location.assign(url), 180);
  };

  document.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest("a[href]");
    if (!link || link.hasAttribute("download") || (link.target && link.target !== "_self")) return;

    let destination;
    try {
      destination = new URL(link.href, window.location.href);
    } catch {
      return;
    }

    if (destination.origin !== window.location.origin) return;
    const current = new URL(window.location.href);
    if (destination.pathname === current.pathname && destination.search === current.search) return;

    event.preventDefault();
    window.navigateWithLoader(destination.href);
  }, true);

  window.addEventListener("pageshow", () => {
    loader.classList.remove("is-visible");
    loader.setAttribute("aria-hidden", "true");
  });
})();
