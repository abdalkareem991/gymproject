const state = { csrfToken: "", member: null, searchTimer: null, directoryTimer: null, plans: [], overview: null, paymentMethods: ["CASH", "BANK_TRANSFER"] };
const $ = (selector) => document.querySelector(selector);
const t = (key) => window.smartGymTranslations?.[document.documentElement.lang]?.[key]
  || window.smartGymTranslations?.en?.[key]
  || key;

function announce(message) {
  const target = $("#notice");
  target.textContent = message;
  target.classList.toggle("show", Boolean(message));
}

async function requestJson(url, options = {}) {
  const result = await window.apiClient(url, options);
  return { ...(result.data || {}), message: result.message };
}

function renderPaymentOptions() {
  const labels = {
    CASH: ["staffCash", "Cash"],
    BANK_TRANSFER: ["staffBankTransfer", "Bank transfer"],
  };
  document.querySelectorAll("select[name='method']").forEach((select) => {
    const selected = select.value;
    select.replaceChildren();
    for (const method of state.paymentMethods) {
      const [key, fallback] = labels[method] || [method, method];
      select.add(new Option(t(key), method));
    }
    select.value = state.paymentMethods.includes(selected) ? selected : (state.paymentMethods[0] || "");
    select.disabled = state.paymentMethods.length === 0;
  });
}

async function loadPaymentOptions() {
  const data = await requestJson("api/staff/payment-options.php");
  state.paymentMethods = data.methods || [];
  renderPaymentOptions();
}

function safeText(tag, className, text) {
  const element = document.createElement(tag);
  element.className = className;
  element.textContent = text ?? "";
  return element;
}

function personName(member) {
  return member.full_name || `${member.first_name || ""} ${member.last_name || ""}`.trim();
}

function statusLabel(status) {
  return ({
    ACTIVE: t("staffActive"),
    PENDING: t("staffPending"),
    INACTIVE: t("staffInactive"),
    SUSPENDED: t("staffSuspended"),
    DISABLED: t("staffDisabled"),
    NO_MEMBERSHIP: t("staffNoPlan"),
  })[status || "NO_MEMBERSHIP"] || t("staffInactive");
}

function setAccessBanner(member) {
  const banner = $("#access-banner");
  if (!member) {
    banner.className = "access-banner access-idle";
    banner.querySelector("strong").textContent = t("staffAwaitingMember");
    banner.querySelector("small").textContent = t("staffAccessHint");
    return;
  }
  const eligible = member.account_status === "ACTIVE" && member.membership_status === "ACTIVE";
  banner.className = `access-banner ${eligible ? "access-granted" : "access-denied"}`;
  banner.querySelector("strong").textContent = eligible ? t("staffAccessGranted") : t("staffAccessDenied");
  banner.querySelector("small").textContent = eligible
    ? t("staffMembershipValid")
    : (member.membership_status === "PENDING" ? t("staffPaymentRequired") : t("staffMembershipInactive"));
}

function chooseMember(member) {
  state.member = member;
  const fullName = personName(member);
  const memberCode = member.member_code || `#${member.id}`;
  $("#selected-member").replaceChildren(
    safeText("b", "", fullName),
    document.createTextNode(` | ${memberCode} | ${member.email || member.phone || ""} | ${statusLabel(member.membership_status)}`),
  );

  const remaining = member.days_remaining == null ? "-" : `${member.days_remaining} ${t("staffDays")}`;
  const summary = member.membership_id
    ? `${t("staffMember")}: ${fullName} | ${t("staffMemberId")}: ${memberCode} | ${t("staffPlan")}: ${member.plan_name || t("staffNoPlan")} | ${t("staffDuration")}: ${remaining} | ${statusLabel(member.membership_status)}`
    : t("staffNoCurrentPlan");
  $("#checkin-summary").textContent = summary;
  setAccessBanner(member);

  const checkedIn = Boolean(member.is_checked_in);
  $("#checkin-button").disabled = checkedIn || member.account_status !== "ACTIVE" || member.membership_status !== "ACTIVE";
  $("#checkout-button").disabled = !checkedIn;

  const paymentButton = $("#payment-button");
  if (paymentButton) paymentButton.disabled = !(member.membership_id && member.membership_status === "PENDING");
  const paymentSummary = $("#payment-member-summary");
  if (paymentSummary) paymentSummary.textContent = `${fullName} | ${memberCode} | ${member.plan_name || t("staffNoPlan")} | ${statusLabel(member.membership_status)}`;

  const membershipSelect = $("#payment-membership");
  if (membershipSelect) {
    membershipSelect.replaceChildren(new Option(t("staffChoosePendingPlan"), ""));
    if (member.membership_id && member.membership_status === "PENDING") {
      membershipSelect.add(new Option(`${member.plan_name} | ${member.duration_days || "-"} ${t("staffDays")}`, member.membership_id));
      membershipSelect.value = String(member.membership_id);
    }
  }

  const paymentAmount = $("#payment-form [name='amount']");
  if (paymentAmount) paymentAmount.value = Number(member.balance_due) > 0 ? member.balance_due : "";
  renderMembers([member]);
}

function renderMembers(members) {
  const results = $("#search-results");
  results.replaceChildren();
  if (!members.length) {
    results.append(safeText("p", "muted small", t("staffNoSearchResults")));
    return;
  }
  for (const member of members) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "result";
    button.setAttribute("aria-pressed", String(state.member?.id === member.id));
    const details = document.createElement("span");
    const contact = [member.member_code, member.email, member.phone].filter(Boolean).join(" | ");
    details.append(safeText("b", "", personName(member)), safeText("small", "", contact));
    button.append(details, safeText("span", "tag", statusLabel(member.membership_status)));
    button.addEventListener("click", () => chooseMember(member));
    results.append(button);
  }
}

async function searchMembers() {
  const query = $("#member-search").value.trim();
  if (query.length < 2) {
    $("#search-results").replaceChildren(safeText("p", "muted small", t("staffSearchHint")));
    return;
  }
  try {
    const data = await requestJson(`api/staff/members.php?q=${encodeURIComponent(query)}`);
    renderMembers(data.members || []);
  } catch (error) {
    $("#search-results").replaceChildren(safeText("p", "muted small", error.message));
  }
}

function renderDirectory(members) {
  const body = $("#member-directory-body");
  body.replaceChildren();
  if (!members.length) {
    const row = document.createElement("tr");
    const cell = safeText("td", "empty-cell", t("staffNoDirectoryResults"));
    cell.colSpan = 8;
    row.append(cell);
    body.append(row);
    return;
  }
  for (const member of members) {
    const row = document.createElement("tr");
    const id = safeText("td", "", member.member_code || `#${member.id}`);
    const name = safeText("td", "", personName(member));
    const phone = safeText("td", "", member.phone || "-");
    const email = safeText("td", "", member.email || "-");
    const plan = safeText("td", "", member.plan_name || t("staffNoPlan"));
    const durationText = member.days_remaining == null ? "-" : `${member.days_remaining} ${t("staffDays")}`;
    const duration = safeText("td", "", durationText);
    const status = document.createElement("td");
    status.append(safeText("span", `status-pill status-${String(member.membership_status || "inactive").toLowerCase()}`, statusLabel(member.membership_status)));
    const actions = document.createElement("td");
    const select = safeText("button", "row-select", t("staffSelect"));
    select.type = "button";
    select.addEventListener("click", () => {
      chooseMember(member);
      $("#member-search").value = member.member_code || member.email || String(member.id);
      document.querySelector("#gate").scrollIntoView({ behavior: "smooth" });
    });
    actions.append(select);
    row.append(id, name, phone, email, plan, duration, status, actions);
    body.append(row);
  }
}

async function loadDirectory() {
  const query = $("#directory-search").value.trim();
  const url = query.length >= 2
    ? `api/staff/members.php?q=${encodeURIComponent(query)}`
    : "api/staff/members.php?directory=1";
  try {
    const data = await requestJson(url);
    renderDirectory(data.members || []);
  } catch (error) {
    const cell = safeText("td", "empty-cell", error.message);
    cell.colSpan = 8;
    const row = document.createElement("tr");
    row.append(cell);
    $("#member-directory-body").replaceChildren(row);
  }
}

function formatTime(value) {
  if (!value) return "-";
  return new Intl.DateTimeFormat(undefined, { hour: "2-digit", minute: "2-digit", timeZone: "UTC" })
    .format(new Date(`${value.replace(" ", "T")}Z`));
}

function renderActivity(data) {
  $("#checkins-today").textContent = data.checkins_today ?? 0;
  $("#members-inside").textContent = data.members_inside ?? 0;
  $("#active-members").textContent = data.active_members ?? 0;
  $("#pending-payments").textContent = data.pending_memberships ?? 0;
  $("#cash-today").textContent = data.cash_today?.length
    ? data.cash_today.map((item) => `${item.total} ${item.currency}`).join(" | ")
    : "0.00 JOD";

  const attendance = $("#attendance-list");
  attendance.replaceChildren();
  if (!data.attendance?.length) {
    const row = document.createElement("tr");
    const cell = safeText("td", "empty-cell", t("staffNoVisits"));
    cell.colSpan = 4;
    row.append(cell);
    attendance.append(row);
  }
  for (const item of data.attendance || []) {
    const row = document.createElement("tr");
    row.append(
      safeText("td", "", `${item.first_name} ${item.last_name}`),
      safeText("td", "", formatTime(item.check_in_at)),
      safeText("td", "", item.check_out_at ? formatTime(item.check_out_at) : "-"),
    );
    const status = document.createElement("td");
    status.append(safeText("span", `status-pill ${item.check_out_at ? "" : "status-active"}`, item.check_out_at ? t("staffCheckedOut") : t("staffOnSite")));
    row.append(status);
    attendance.append(row);
  }

  const payments = $("#payments-list");
  payments.replaceChildren();
  if (!data.payments?.length) payments.append(safeText("p", "muted small", t("staffNoPayments")));
  for (const item of data.payments || []) {
    const line = document.createElement("div");
    line.className = "payment-row";
    const info = document.createElement("span");
    const methodLabel = item.method === "CASH" ? t("staffCash")
      : item.method === "CARD" ? t("staffCard")
        : item.method === "BANK_TRANSFER" ? t("staffBankTransfer") : item.method;
    info.append(safeText("strong", "", `${item.first_name} ${item.last_name}`), safeText("small", "", `${formatTime(item.paid_at)} | ${methodLabel}`));
    line.append(info, safeText("span", "payment-amount", `${item.amount} ${item.currency}`));
    payments.append(line);
  }
}

async function refreshOverview() {
  try {
    state.overview = await requestJson("api/staff/overview.php");
    renderActivity(state.overview);
  } catch (error) {
    announce(error.message);
  }
}

async function refreshSelectedMember() {
  if (!state.member) return;
  const data = await requestJson(`api/staff/members.php?q=${encodeURIComponent(state.member.id)}`);
  const fresh = data.members?.find((member) => Number(member.id) === Number(state.member.id));
  if (fresh) chooseMember(fresh);
}

async function recordVisit(action) {
  if (!state.member) return;
  const button = action === "checkin" ? $("#checkin-button") : $("#checkout-button");
  button.disabled = true;
  $("#checkin-message").textContent = "";
  try {
    const result = await requestJson(`api/staff/${action}.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrfToken },
      body: JSON.stringify({ user_id: state.member.id, notes: $("#checkin-notes").value }),
    });
    $("#checkin-message").textContent = result.message;
    $("#checkin-notes").value = "";
    await Promise.all([refreshOverview(), refreshSelectedMember()]);
  } catch (error) {
    $("#checkin-message").textContent = error.message;
    chooseMember(state.member);
  }
}

async function loadPlans() {
  const data = await requestJson("api/staff/members.php?plans=1");
  state.plans = data.plans || [];
  const select = $("#walkin-plan");
  for (const plan of state.plans) {
    select.add(new Option(`${plan.title} | ${plan.duration_days} ${t("staffDays")} | ${plan.price} ${plan.currency}`, plan.id));
  }
}

$("#member-search").addEventListener("input", () => {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(searchMembers, 220);
});
$("#directory-search").addEventListener("input", () => {
  clearTimeout(state.directoryTimer);
  state.directoryTimer = setTimeout(loadDirectory, 250);
});
$("#walkin-plan").addEventListener("change", (event) => {
  const plan = state.plans.find((item) => String(item.id) === event.target.value);
  if (plan) $("#walkin-form [name='amount']").value = plan.price;
});
$("#checkin-button").addEventListener("click", () => recordVisit("checkin"));
$("#checkout-button").addEventListener("click", () => recordVisit("checkout"));

$("#walkin-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = $("#walkin-button");
  button.disabled = true;
  $("#walkin-message").textContent = "";
  const formData = Object.fromEntries(new FormData(form).entries());
  try {
    const registration = await requestJson("api/staff/members.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrfToken },
      body: JSON.stringify({
        full_name: formData.full_name,
        email: formData.email,
        phone: formData.phone,
        password: formData.password,
        plan_id: formData.plan_id,
      }),
    });
    try {
      const payment = await requestJson("api/staff/payment.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrfToken },
        body: JSON.stringify({
          user_id: registration.user_id,
          membership_id: registration.membership_id,
          amount: formData.amount,
          method: formData.method,
          notes: "Walk-in registration",
        }),
      });
      $("#walkin-message").textContent = `${payment.message} ${payment.amount} ${payment.currency}`;
      form.reset();
      await Promise.all([refreshOverview(), loadDirectory()]);
      const lookup = await requestJson(`api/staff/members.php?q=${encodeURIComponent(registration.user_id)}`);
      if (lookup.members?.[0]) chooseMember(lookup.members[0]);
    } catch (error) {
      $("#walkin-message").textContent = `${t("staffRegisteredPaymentPending")} ${error.message}`;
      await Promise.all([refreshOverview(), loadDirectory()]);
    }
  } catch (error) {
    $("#walkin-message").textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

$("#payment-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!state.member || state.member.membership_status !== "PENDING") return;
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = $("#payment-button");
  button.disabled = true;
  $("#payment-message").textContent = "";
  const formData = Object.fromEntries(new FormData(form).entries());
  try {
    const payment = await requestJson("api/staff/payment.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrfToken },
      body: JSON.stringify({
        user_id: state.member.id,
        membership_id: formData.membership_id,
        amount: formData.amount,
        method: formData.method,
        notes: "Reception membership activation",
      }),
    });
    $("#payment-message").textContent = `${payment.message} ${payment.amount} ${payment.currency}`;
    await Promise.all([refreshOverview(), loadDirectory(), refreshSelectedMember()]);
  } catch (error) {
    $("#payment-message").textContent = error.message;
    button.disabled = false;
  }
});

$("#refresh").addEventListener("click", () => Promise.all([refreshOverview(), loadDirectory()]));
$("#logout").addEventListener("click", async () => {
  try {
    await requestJson("api/auth/logout.php", { method: "POST", headers: { "X-CSRF-Token": state.csrfToken } });
    window.navigateWithLoader("login.html");
  } catch (error) {
    announce(error.message);
  }
});

(async function init() {
  const skeleton = $("[data-skeleton-screen]");
  try {
    const session = await requestJson("api/auth/session.php");
    state.csrfToken = session.csrf_token;
    if (!session.user) {
      window.navigateWithLoader("login.html");
      return;
    }
    if (!["STAFF", "ADMIN"].includes(session.user.role)) {
      window.navigateWithLoader("member.html");
      return;
    }
    const name = `${session.user.first_name} ${session.user.last_name}`.trim();
    $("#staff-name").textContent = name;
    $("#staff-pill-name").textContent = name;
    $(".staff-avatar").textContent = name.charAt(0).toUpperCase() || "S";
    await Promise.all([refreshOverview(), loadPlans(), loadDirectory(), loadPaymentOptions()]);
  } catch (error) {
    announce(error.message);
  } finally {
    if (skeleton) {
      await new Promise((resolve) => setTimeout(resolve, 250));
      skeleton.classList.add("is-hidden");
      skeleton.setAttribute("aria-hidden", "true");
    }
  }
})();

window.addEventListener("smart-gym-language-change", () => {
  renderPaymentOptions();
  if (state.member) chooseMember(state.member);
  else setAccessBanner(null);
  if (state.overview) renderActivity(state.overview);
  if ($("#member-search").value.trim().length >= 2) searchMembers();
  loadDirectory();
});
