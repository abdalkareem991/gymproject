import { translations } from "./translations.js?v=20261119-3";

window.smartGymTranslations = translations;

const root = document.documentElement;
const themeButton = document.querySelector("[data-theme-toggle]");
const languageButton = document.querySelector("[data-language-toggle]");
const sidebarButtons = [...document.querySelectorAll("[data-sidebar-toggle]")];
const sidebar = document.querySelector("[data-sidebar]");
const backdrop = document.querySelector("[data-sidebar-backdrop]");
const skeleton = document.querySelector("[data-skeleton-screen]");

function readPreference(key, fallback) {
  try {
    return localStorage.getItem(key) || fallback;
  } catch {
    return fallback;
  }
}

function savePreference(key, value) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // Keep the current session usable when browser storage is unavailable.
  }
}

function applyTheme(theme) {
  const selectedTheme = theme === "light" ? "light" : "dark";
  root.dataset.theme = selectedTheme;
  document.querySelector('meta[name="theme-color"]')?.setAttribute("content", selectedTheme === "light" ? "#FAF8F5" : "#09090b");
  if (themeButton) {
    themeButton.setAttribute("aria-label", selectedTheme === "dark"
      ? translations[root.lang].themeToLight
      : translations[root.lang].themeToDark);
    const sunIcon = themeButton.querySelector("[data-sun-icon]");
    const moonIcon = themeButton.querySelector("[data-moon-icon]");
    if (sunIcon) sunIcon.hidden = selectedTheme !== "dark";
    if (moonIcon) moonIcon.hidden = selectedTheme !== "light";
  }
  savePreference("smart-gym-theme", selectedTheme);
}

function applyLanguage(language) {
  const selectedLanguage = language === "ar" ? "ar" : "en";
  const dictionary = translations[selectedLanguage];
  root.lang = selectedLanguage;
  root.dir = "ltr";

  document.querySelectorAll("[data-i18n]").forEach((element) => {
    const value = dictionary[element.dataset.i18n];
    if (value === undefined) return;
    const directTextNode = [...element.childNodes].find((node) => node.nodeType === Node.TEXT_NODE);
    if (directTextNode) directTextNode.nodeValue = value;
    else if (!element.children.length) element.textContent = value;
    else element.insertBefore(document.createTextNode(value), element.firstChild);
  });

  document.querySelectorAll("[data-i18n-aria]").forEach((element) => {
    const value = dictionary[element.dataset.i18nAria];
    if (value !== undefined) element.setAttribute("aria-label", value);
  });

  document.querySelectorAll("[data-i18n-placeholder]").forEach((element) => {
    const value = dictionary[element.dataset.i18nPlaceholder];
    if (value !== undefined) element.setAttribute("placeholder", value);
  });

  document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (input) toggle.setAttribute("aria-label", input.type === "password" ? dictionary.showPassword : dictionary.hidePassword);
  });

  const authForm = document.querySelector("#auth-form");
  if (authForm) {
    authForm.dataset.submitLabel = dictionary[authForm.dataset.submitKey] || "Continue";
    authForm.dataset.loadingLabel = dictionary[authForm.dataset.loadingKey] || "Please wait";
    const passwordConfirmation = authForm.querySelector("[data-password-confirm]");
    if (passwordConfirmation) passwordConfirmation.dataset.mismatchMessage = dictionary.authPasswordMismatch;
    const passwordInput = authForm.querySelector("#password");
    const confirmInput = authForm.querySelector("[data-password-confirm]");
    if (passwordInput && confirmInput) {
      passwordInput.setCustomValidity(passwordInput.value && confirmInput.value && passwordInput.value !== confirmInput.value
        ? dictionary.authPasswordMismatch
        : "");
      confirmInput.setCustomValidity(confirmInput.value && passwordInput.value !== confirmInput.value
        ? dictionary.authPasswordMismatch
        : "");
    }
  }

  if (languageButton) {
    languageButton.textContent = selectedLanguage === "en" ? "\u0627\u0644\u0639\u0631\u0628\u064a\u0629" : "EN";
    languageButton.setAttribute("aria-label", selectedLanguage === "en"
      ? dictionary.languageToArabic
      : dictionary.languageToEnglish);
  }
  applyTheme(root.dataset.theme);
  savePreference("smart-gym-language", selectedLanguage);
  updateSidebarButton();
  window.dispatchEvent(new CustomEvent("smart-gym-language-change", { detail: { language: selectedLanguage } }));
}

function updateSidebarButton() {
  if (!sidebarButtons.length) return;
  const isMobile = window.matchMedia("(max-width: 991px)").matches;
  const isExpanded = isMobile
    ? document.body.classList.contains("sidebar-open")
    : !document.body.classList.contains("sidebar-collapsed");
  sidebarButtons.forEach((button) => {
    button.setAttribute("aria-expanded", String(isExpanded));
    button.setAttribute("aria-label", translations[root.lang][isExpanded ? "menuClose" : "menuOpen"]);
  });
}

function closeMobileSidebar() {
  document.body.classList.remove("sidebar-open");
  updateSidebarButton();
}

themeButton?.addEventListener("click", () => {
  applyTheme(root.dataset.theme === "dark" ? "light" : "dark");
});

languageButton?.addEventListener("click", () => {
  applyLanguage(root.lang === "en" ? "ar" : "en");
});

sidebarButtons.forEach((button) => button.addEventListener("click", () => {
  if (window.matchMedia("(max-width: 991px)").matches) {
    document.body.classList.toggle("sidebar-open");
  } else {
    document.body.classList.toggle("sidebar-collapsed");
    savePreference("smart-gym-sidebar-collapsed", String(document.body.classList.contains("sidebar-collapsed")));
  }
  updateSidebarButton();
}));

backdrop?.addEventListener("click", closeMobileSidebar);
sidebar?.addEventListener("click", (event) => {
  if (event.target.closest("a[href^='#']")) closeMobileSidebar();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && document.body.classList.contains("sidebar-open")) closeMobileSidebar();
});
window.addEventListener("resize", updateSidebarButton);

document.addEventListener("click", (event) => {
  const toggle = event.target.closest("[data-password-toggle]");
  if (!toggle) return;
  const input = document.getElementById(toggle.dataset.passwordToggle);
  if (!input) return;
  input.type = input.type === "password" ? "text" : "password";
  const dictionary = translations[root.lang];
  toggle.setAttribute("aria-label", input.type === "password" ? dictionary.showPassword : dictionary.hidePassword);
});

document.querySelectorAll("#auth-form [data-password-confirm]").forEach((confirmInput) => {
  const form = confirmInput.form;
  const passwordInput = form?.querySelector("#password");
  const validateConfirmation = () => {
    const message = translations[root.lang].authPasswordMismatch;
    confirmInput.setCustomValidity(confirmInput.value && passwordInput.value !== confirmInput.value ? message : "");
    passwordInput.setCustomValidity(confirmInput.value && passwordInput.value !== confirmInput.value ? message : "");
  };
  confirmInput.addEventListener("input", validateConfirmation);
  passwordInput?.addEventListener("input", validateConfirmation);
});

const sectionLinks = [...document.querySelectorAll('.sidebar-link[href^="#"]')];
if ("IntersectionObserver" in window) {
  const sectionObserver = new IntersectionObserver((entries) => {
    const visibleSection = entries
      .filter((entry) => entry.isIntersecting)
      .sort((first, second) => second.intersectionRatio - first.intersectionRatio)[0];
    if (!visibleSection) return;
    sectionLinks.forEach((link) => {
      if (link.hash === `#${visibleSection.target.id}`) link.setAttribute("aria-current", "location");
      else link.removeAttribute("aria-current");
    });
  }, { rootMargin: "-18% 0px -62% 0px", threshold: [0, 0.2, 0.5, 1] });
  sectionLinks.forEach((link) => {
    link.addEventListener("click", () => {
      sectionLinks.forEach((candidate) => {
        if (candidate === link) candidate.setAttribute("aria-current", "location");
        else candidate.removeAttribute("aria-current");
      });
    });
    const section = document.querySelector(link.hash);
    if (section) sectionObserver.observe(section);
  });
}

document.querySelectorAll("[data-current-year]").forEach((element) => {
  element.textContent = String(new Date().getFullYear());
});

const savedTheme = readPreference("smart-gym-theme", "dark");
const savedLanguage = readPreference("smart-gym-language", "en");
const sidebarCollapsed = readPreference("smart-gym-sidebar-collapsed", "false") === "true";
const planSelect = document.querySelector("#plan");
if (planSelect) {
  const requestedPlan = new URLSearchParams(window.location.search).get("plan")?.trim().toLowerCase();
  const planAliases = new Map([
    ["monthly", "monthly"], ["monthly-plus", "monthly"],
    ["quarterly", "three-months"], ["3-months", "three-months"], ["three-months", "three-months"],
    ["annual", "annual"], ["annual-plus", "annual"], ["yearly", "annual"]
  ]);
  const selectedPlan = planAliases.get(requestedPlan);
  if (selectedPlan) planSelect.value = selectedPlan;
}
document.body.classList.toggle("sidebar-collapsed", sidebarCollapsed);
applyTheme(savedTheme);
applyLanguage(savedLanguage);
updateSidebarButton();

const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));
const fontsReady = document.fonts?.ready ?? Promise.resolve();
if (skeleton && !skeleton.hasAttribute("data-skeleton-wait-for-hydration")) {
  Promise.all([
    wait(650),
    Promise.race([fontsReady, wait(1400)])
  ]).then(() => {
    skeleton.classList.add("is-hidden");
    skeleton.setAttribute("aria-hidden", "true");
  });
}
