const form = document.querySelector("#auth-form");
const message = document.querySelector("#form-message");
const submit = form.querySelector('button[type="submit"]');
const emailInput = form.querySelector('input[name="email"]');
const rememberInput = form.querySelector('input[name="remember"]');
let csrfToken = "";

function getTranslations() {
  return window.smartGymTranslations?.[document.documentElement.lang] || {};
}

function showMessage(text, isSuccess = false) {
  message.textContent = text;
  message.classList.toggle("is-success", isSuccess);
}

function authErrorMessage(error, fallbackKey) {
  const dictionary = getTranslations();
  if (error.status === 401) return dictionary.authInvalidCredentials || "Check your email and password.";
  if (error.status === 409) return dictionary.authDuplicateAccount || "An account with these details already exists.";
  if (error.status === 422) return dictionary.authValidationSummary || "Review the information and try again.";
  if (error.status === 403) return dictionary.authSessionExpired || "Your session expired. Refresh the page and try again.";
  if (!error.status) return dictionary.authNetworkError || "Could not connect to the server. Please try again.";
  return dictionary[fallbackKey] || "Your request could not be completed. Please try again.";
}

function destinationFor(user) {
  if (user.role === "ADMIN") return "admin.html";
  if (user.role === "TRAINER") return "trainer.html";
  if (user.role === "STAFF") return "staff.html";
  return "member.html";
}

function navigateToPage(page) {
  window.navigateWithLoader(new URL(page, window.ApiClient.getBaseUrl()).href);
}

function restoreRememberedEmail() {
  if (!rememberInput) return;
  try {
    const rememberedEmail = localStorage.getItem("smart-gym-remembered-email");
    if (rememberedEmail) {
      emailInput.value = rememberedEmail;
      rememberInput.checked = true;
    }
  } catch {
    // Continue without restoring saved email when browser storage is unavailable.
  }
}

async function initializeSession() {
  const result = await window.ApiClient.get("api/auth/session.php");
  csrfToken = result.data.csrf_token;
  if (result.data.user) navigateToPage(destinationFor(result.data.user));
}

restoreRememberedEmail();

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (form.dataset.submitting === "true") return;
  showMessage("");
  if (!form.reportValidity()) return;

  form.dataset.submitting = "true";
  submit.disabled = true;
  submit.textContent = form.dataset.loadingLabel || getTranslations().authLoading || "Please wait";
  const payload = Object.fromEntries(new FormData(form).entries());
  delete payload.remember;
  delete payload.password_confirm;

  try {
    const result = await window.ApiClient.post(form.dataset.endpoint, payload, {
      redirectOn401: false,
      headers: { "X-CSRF-Token": csrfToken },
    });
    csrfToken = result.data.csrf_token;
    if (rememberInput) {
      try {
        if (rememberInput.checked) localStorage.setItem("smart-gym-remembered-email", emailInput.value.trim());
        else localStorage.removeItem("smart-gym-remembered-email");
      } catch {
        // Email remembering is optional and does not affect account access.
      }
    }
    showMessage(getTranslations().authSuccess || result.message, true);
    navigateToPage(destinationFor(result.data.user));
  } catch (error) {
    showMessage(authErrorMessage(error, form.dataset.errorKey));
  } finally {
    delete form.dataset.submitting;
    submit.disabled = false;
    submit.textContent = form.dataset.submitLabel || "Continue";
  }
});

submit.disabled = true;
initializeSession()
  .then(() => {
    submit.disabled = false;
  })
  .catch((error) => {
    showMessage(authErrorMessage(error, "authSessionError"));
    submit.disabled = false;
  });
