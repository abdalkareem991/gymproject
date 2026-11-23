const charts = {};
const palette = [
  "#ff1e42",
  "#39d98a",
  "#f6b84a",
  "#9b8cff",
  "#45b6e8",
  "#ff7f50",
];
const $ = (selector) => document.querySelector(selector);
const t = (key, fallback = key) => window.smartGymTranslations?.[document.documentElement.lang]?.[key] || fallback;
let csrfToken = "";
let dashboardData = null;
let adminPlans = [];
let selectedAdminMember = null;
let memberSearchTimer = null;
let memberManagementTimer = null;
let directoryUsers = [];
let activeUserFilter = "ALL";
let paymentSettings = { cash_enabled: true, bank_transfer_enabled: true, online_enabled: false };

async function requestJson(url, options = {}) {
  const result = await window.apiClient(url, options);
  return { ...(result.data || {}), message: result.message };
}

function announce(message) {
  const notice = $("#notice");
  notice.textContent = message;
  notice.classList.toggle("show", Boolean(message));
}

function formatDateTime(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat(document.documentElement.lang, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(`${value.replace(" ", "T")}Z`));
}

function formatMonth(month) {
  return new Intl.DateTimeFormat(document.documentElement.lang, {
    month: "short",
    year: "numeric",
  }).format(new Date(`${month}-01T00:00:00Z`));
}

function setMetrics(metrics) {
  $("#total-members").textContent = metrics.active_memberships.toLocaleString();
  $("#pending-requests").textContent = metrics.pending_membership_requests.toLocaleString();
  $("#classes-week").textContent = metrics.active_classes_today.toLocaleString();
  $("#total-revenue").textContent = `${Number(metrics.total_revenue_jod).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} JOD`;
}

function chartBaseOptions() {
  const styles = getComputedStyle(document.documentElement);
  const textColor = styles.getPropertyValue("--color-text-muted").trim() || "#a5a1a5";
  const gridColor = styles.getPropertyValue("--color-border").trim() || "rgb(255 255 255 / 10%)";
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        labels: { color: textColor, usePointStyle: true, boxWidth: 8 },
      },
      tooltip: { mode: "index", intersect: false },
    },
    scales: {
      x: { ticks: { color: textColor }, grid: { color: gridColor } },
      y: {
        beginAtZero: true,
        ticks: { color: textColor, precision: 0 },
        grid: { color: gridColor },
      },
    },
  };
}

function replaceChart(name, canvas, config) {
  charts[name]?.destroy();
  charts[name] = new Chart(canvas, config);
}

function renderRevenueChart(data) {
  const select = $("#revenue-currency");
  const previousCurrency = select.value;
  select.replaceChildren();
  for (const currency of data.revenue.currencies)
    select.add(new Option(currency, currency));
  if (!data.revenue.currencies.length) {
    select.add(new Option(t("noData", "No data"), ""));
    select.disabled = true;
  } else {
    select.disabled = false;
    select.value = data.revenue.currencies.includes(previousCurrency)
       previousCurrency
      : data.revenue.currencies[0];
  }

  const currency = select.value;
  replaceChart("revenue", $("#revenue-chart"), {
    type: "line",
    data: {
      labels: data.revenue.months.map(formatMonth),
      datasets: [
        {
          label: currency
            ? `${t("revenueLabel", "Completed payments")} (${currency})`
            : t("noPaymentData", "No payment data"),
          data: currency
            ? data.revenue.series[currency]
            : data.revenue.months.map(() => 0),
          borderColor: palette[0],
          backgroundColor: "#ff1e4220",
          pointBackgroundColor: palette[0],
          borderWidth: 2,
          pointRadius: 3,
          tension: 0.32,
          fill: true,
        },
      ],
    },
    options: chartBaseOptions(),
  });
}

function renderPlansChart(plans) {
  replaceChart("plans", $("#plans-chart"), {
    type: "doughnut",
    data: {
      labels: plans.map((plan) => plan.name),
      datasets: [
        {
          data: plans.map((plan) => Number(plan.total)),
          backgroundColor: plans.map(
            (_, index) => palette[index % palette.length],
          ),
          borderColor: "#111827",
          borderWidth: 3,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: "68%",
      plugins: {
        legend: {
          position: "bottom",
          labels: { color: "#b8c1d0", padding: 16, usePointStyle: true },
        },
      },
    },
  });
}

function renderGrowthChart(growth) {
  replaceChart("growth", $("#growth-chart"), {
    type: "bar",
    data: {
      labels: growth.months.map(formatMonth),
      datasets: [
        {
          label: t("newMembers", "New members"),
          data: growth.totals,
          backgroundColor: "#39d98a88",
          borderColor: "#39d98a",
          borderWidth: 1,
          borderRadius: 6,
        },
      ],
    },
    options: chartBaseOptions(),
  });
}

function renderAttendanceChart(attendance) {
  const labels = attendance.days.map((day) =>
    new Intl.DateTimeFormat(document.documentElement.lang, {
      month: "short",
      day: "numeric",
      timeZone: "UTC",
    }).format(new Date(`${day}T00:00:00Z`)),
  );
  replaceChart("attendance", $("#attendance-chart"), {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: t("checkIns", "Check-ins"),
          data: attendance.totals,
          backgroundColor: "#45b6e888",
          borderColor: "#45b6e8",
          borderWidth: 1,
          borderRadius: 6,
        },
      ],
    },
    options: chartBaseOptions(),
  });
}

function renderPayments(payments) {
  const rows = $("#payment-rows");
  rows.replaceChildren();
  if (!payments.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 5;
    cell.className = "muted";
    cell.textContent = t("noPayments", "No payments have been recorded yet.");
    row.append(cell);
    rows.append(row);
    return;
  }
  for (const payment of payments) {
    const row = document.createElement("tr");
    const member = document.createElement("td");
    member.textContent = `${payment.first_name} ${payment.last_name}`;
    const date = document.createElement("td");
    date.textContent = formatDateTime(payment.paid_at || payment.created_at);
    const method = document.createElement("td");
    const methodLabels = { CASH: "cash", CARD: "card", BANK_TRANSFER: "bankTransfer", OTHER: "other" };
    method.textContent = t(methodLabels[payment.method] || payment.method, payment.method.replaceAll("_", " "));
    const amount = document.createElement("td");
    amount.className = "amount-cell";
    amount.textContent = `${payment.amount} ${payment.currency}`;
    const status = document.createElement("td");
    status.append(
      Object.assign(document.createElement("span"), {
        className: `status ${payment.status.toLowerCase()}`,
        textContent: t(payment.status.toLowerCase(), payment.status),
      }),
    );
    row.append(member, date, method, amount, status);
    rows.append(row);
  }
}

function renderMembers(members) {
  const list = $("#recent-members");
  list.replaceChildren();
  if (!members.length) {
    const empty = document.createElement("p");
    empty.className = "muted";
    empty.textContent = t("noRecentMembers", "No members have registered yet.");
    list.append(empty);
    return;
  }
  for (const member of members) {
    const row = document.createElement("div");
    row.className = "member-row";
    const details = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = `${member.first_name} ${member.last_name}`;
    const email = document.createElement("span");
    email.textContent = member.email;
    details.append(name, email);
    const date = document.createElement("time");
    date.textContent = formatDateTime(member.created_at);
    row.append(details, date);
    list.append(row);
  }
}

function renderDashboard(data) {
  dashboardData = data;
  setMetrics(data.metrics);
  renderPayments(data.recent_payments);
  renderMembers(data.recent_members);
  if (typeof Chart === "undefined") {
    $("#revenue-currency").disabled = true;
    announce(t("chartUnavailable", "Dashboard data loaded, but Chart.js could not be reached."));
    return;
  }
  renderRevenueChart(data);
  renderPlansChart(data.membership_plans);
  renderGrowthChart(data.member_growth);
  renderAttendanceChart(data.attendance);
}

async function loadDashboard() {
  try {
    const data = await requestJson("api/admin/dashboard.php");
    renderDashboard(data);
    if (typeof Chart !== "undefined") announce("");
  } catch (error) {
    announce(error.message);
  }
}

function setOperationMessage(id, message) {
  const target = document.querySelector(id);
  target.textContent = message;
}

function beginPlanEdit(plan) {
  const form = $("#plan-form");
  form.elements.plan_id.value = plan.id;
  form.elements.name.value = plan.name;
  form.elements.duration_days.value = plan.duration_days;
  form.elements.price.value = plan.price;
  form.elements.currency.value = plan.currency;
  form.elements.description.value = plan.description || "";
  form.elements.monthly_class_limit.value = plan.monthly_class_limit ?? "";
  form.elements.term_class_limit.value = plan.term_class_limit ?? "";
  $("#save-plan").textContent = t("saveChanges", "Save changes");
  $("#cancel-plan-edit").hidden = false;
  $("#plan-dialog-title").textContent = t("editPlan", "Edit plan");
  $("#plan-dialog").showModal();
  $("#plan-name").focus();
}

function resetPlanForm() {
  const form = $("#plan-form");
  form.reset();
  form.elements.plan_id.value = "";
  form.elements.currency.value = "JOD";
  form.elements.duration_days.value = "30";
  $("#save-plan").textContent = t("createPlan", "Create plan");
  $("#cancel-plan-edit").hidden = true;
  $("#plan-dialog-title").textContent = t("createPlan", "Create plan");
  $("#plan-dialog").close();
}

async function archivePlan(plan, button) {
  if (!window.confirm(`${t("confirmArchivePlan", "Archive this plan?")} ${plan.name}`)) return;
  button.disabled = true;
  try {
    await requestJson("api/admin/plans.php", {
      method: "DELETE",
      headers: { "X-CSRF-Token": csrfToken },
      body: JSON.stringify({ plan_id: plan.id }),
    });
    resetPlanForm();
    await loadPlans();
    announce(t("planArchived", "Plan archived."));
  } catch (error) {
    announce(error.message);
    button.disabled = false;
  }
}

async function loadPlans() {
  const data = await requestJson("api/admin/plans.php");
  adminPlans = data.plans;
  const select = $("#membership-plan");
  select.replaceChildren();
  if (!adminPlans.length) {
    select.add(new Option(t("loadPlanFirst", "Create or load a plan first"), ""));
    $("#activate-membership").disabled = true;
  } else {
    select.add(new Option(t("choosePlan", "Choose a plan"), ""));
    for (const plan of adminPlans) {
      const option = new Option(
        `${plan.name} · ${plan.price} ${plan.currency} · ${plan.duration_days} ${t("days", "days")}`,
        plan.id,
      );
      option.dataset.price = plan.price;
      select.add(option);
    }
  }

  const list = $("#plan-list");
  list.replaceChildren();
  if (!adminPlans.length) {
    const empty = document.createElement("p");
    empty.className = "muted small";
    empty.textContent = t("noActivePlans", "No active plans yet.");
    list.append(empty);
    return;
  }
  for (const plan of adminPlans) {
    const row = document.createElement("div");
    row.className = "operation-row";
    const details = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = plan.name;
    const meta = document.createElement("small");
    meta.textContent = `${plan.duration_days} ${t("days", "days")} · ${plan.price} ${plan.currency}`;
    details.append(name, meta);
    if (plan.description) {
      const description = document.createElement("small");
      description.className = "muted small";
      description.textContent = plan.description;
      details.append(description);
    }
    const controls = document.createElement("div");
    controls.className = "row-actions";
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "secondary-button";
    edit.textContent = t("edit", "Edit");
    edit.addEventListener("click", () => beginPlanEdit(plan));
    const archive = document.createElement("button");
    archive.type = "button";
    archive.className = "danger-button";
    archive.textContent = t("archive", "Archive");
    archive.addEventListener("click", () => archivePlan(plan, archive));
    controls.append(edit, archive);
    row.append(details, controls);
    list.append(row);
  }
}

async function loadTeam() {
  const data = await requestJson("api/admin/team.php");
  const list = $("#team-list");
  list.replaceChildren();
  if (!data.team.length) {
    const empty = document.createElement("p");
    empty.className = "muted small";
    empty.textContent = t("noTeamAccounts", "No trainers or reception staff accounts yet.");
    list.append(empty);
    return;
  }
  for (const person of data.team) {
    const row = document.createElement("div");
    row.className = "operation-row";
    const details = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = `${person.first_name} ${person.last_name}`;
    const meta = document.createElement("small");
    meta.textContent = `${t(person.role === "TRAINER" ? "roleTrainer" : "roleStaff", person.role)} | ${person.email} | ${t(person.status.toLowerCase(), person.status)}`;
    details.append(name, meta);
    row.append(details);
    list.append(row);
  }
}

async function searchAdminMembers() {
  const query = $("#admin-member-search").value.trim();
  const results = $("#admin-member-results");
  results.replaceChildren();
  if (query.length < 2) return;
  try {
    const data = await requestJson(
      `api/admin/members.php?q=${encodeURIComponent(query)}`,
    );
    if (!data.members.length) {
      const empty = document.createElement("p");
      empty.className = "muted small";
      empty.textContent = t("noActiveMembersMatched", "No active members matched.");
      results.append(empty);
      return;
    }
    for (const member of data.members) {
      const button = document.createElement("button");
      button.type = "button";
      button.setAttribute("role", "option");
      button.textContent = `${member.first_name} ${member.last_name} | ${member.email}`;
      button.addEventListener("click", () => {
        selectedAdminMember = member;
        $("#membership-user-id").value = member.id;
        $("#selected-admin-member").textContent = `${member.first_name} ${member.last_name} | ${member.email}`;
        results.replaceChildren();
        updateMembershipButton();
      });
      results.append(button);
    }
  } catch (error) {
    const message = document.createElement("p");
    message.className = "operation-message";
    message.textContent = error.message;
    results.append(message);
  }
}

function hasActiveMembership(user) {
  return user.role === "MEMBER" && user.status === "ACTIVE"
    && ["ACTIVE", "EXPIRING"].includes(user.membership_status)
    && Number(user.days_remaining) >= 0;
}

function displayRole(user) {
  if (user.role === "MEMBER" && !hasActiveMembership(user)) return t("roleVisitor", "Registered Visitor / No Active Plan");
  const labels = { MEMBER: "roleMember", TRAINER: "roleTrainer", STAFF: "roleStaff", ADMIN: "roleAdmin" };
  return t(labels[user.role] || "roleMember", user.role);
}

function directoryMatchesFilter(user) {
  if (activeUserFilter === "ACTIVE_MEMBER") return hasActiveMembership(user);
  if (activeUserFilter === "NON_MEMBER") return user.role === "MEMBER" && !hasActiveMembership(user);
  if (activeUserFilter === "TRAINER") return user.role === "TRAINER";
  if (activeUserFilter === "STAFF_ADMIN") return ["STAFF", "ADMIN"].includes(user.role);
  return true;
}

function userDuration(user) {
  if (!user.membership_id) return t("notSubscribed", "Not Subscribed");
  if (Number(user.days_remaining) < 0 || ["EXPIRED", "CANCELLED"].includes(user.membership_status)) return t("expired", "Expired");
  if (!["ACTIVE", "EXPIRING"].includes(user.membership_status)) return t("notSubscribed", "Not Subscribed");
  return `${Number(user.days_remaining)} ${t("daysLeft", "Days Left")}`;
}

function createDirectoryCell(text, className = "") {
  const cell = document.createElement("td");
  cell.textContent = text;
  if (className) cell.className = className;
  return cell;
}

function renderUserDirectory() {
  const body = $("#member-management-list");
  const query = $("#member-management-search").value.trim().toLowerCase();
  const users = directoryUsers.filter((user) => {
    const searchable = `${user.id} ${user.full_name} ${user.email} ${user.phone || ""}`.toLowerCase();
    return directoryMatchesFilter(user) && (!query || searchable.includes(query));
  });
  body.replaceChildren();
  if (!users.length) {
    const row = document.createElement("tr");
    const empty = document.createElement("td");
    empty.colSpan = 7;
    empty.className = "muted empty-cell";
    empty.textContent = t("noUsersFound", "No users match this search.");
    row.append(empty);
    body.append(row);
    return;
  }

  for (const user of users) {
    const row = document.createElement("tr");
    const identity = document.createElement("td");
    identity.className = "user-identity";
    const name = document.createElement("strong");
    name.textContent = user.full_name;
    const details = document.createElement("small");
    details.textContent = `#${user.id} · ${user.email}${user.phone ? ` · ${user.phone}` : ""}`;
    identity.append(name, details);

    const roleCell = document.createElement("td");
    const roleBadge = document.createElement("span");
    roleBadge.className = `role-badge role-${user.role.toLowerCase()}`;
    roleBadge.textContent = displayRole(user);
    roleCell.append(roleBadge);

    const statusCell = document.createElement("td");
    const statusBadge = document.createElement("span");
    statusBadge.className = `status-badge ${user.status === "ACTIVE" ? "is-active" : "is-inactive"}`;
    statusBadge.textContent = user.status === "ACTIVE" ? t("active", "Active") : t("inactive", "Inactive");
    statusCell.append(statusBadge);

    const planText = user.plan_name && hasActiveMembership(user) ? user.plan_name : t("noneNa", "None / N/A");
    const paidText = user.price_paid === null ? "—" : `${Number(user.price_paid).toFixed(2)} ${user.price_currency || "JOD"}`;
    const roleSelect = document.createElement("select");
    roleSelect.setAttribute("aria-label", `${t("changeRole", "Change role")} · ${user.full_name}`);
    for (const [value, key, fallback] of [["MEMBER", "roleMember", "Member"], ["TRAINER", "roleTrainer", "Trainer"], ["STAFF", "roleStaff", "Staff"], ["ADMIN", "roleAdmin", "Admin"]]) {
      roleSelect.add(new Option(t(key, fallback), value));
    }
    roleSelect.value = user.role;
    const statusSelect = document.createElement("select");
    statusSelect.setAttribute("aria-label", `${t("changeStatus", "Change status")} · ${user.full_name}`);
    for (const [value, key, fallback] of [["ACTIVE", "active", "Active"], ["INACTIVE", "inactive", "Inactive"], ["SUSPENDED", "suspended", "Suspended"], ["DISABLED", "disabled", "Disabled"]]) {
      statusSelect.add(new Option(t(key, fallback), value));
    }
    statusSelect.value = user.status;
    const save = document.createElement("button");
    save.type = "button";
    save.className = "secondary-button save-user-button";
    save.textContent = t("apply", "Apply");
    save.addEventListener("click", async () => {
      const changes = { user_id: user.id };
      if (roleSelect.value !== user.role) changes.role = roleSelect.value;
      if (statusSelect.value !== user.status) changes.status = statusSelect.value;
      if (Object.keys(changes).length === 1) return;
      if (!window.confirm(`${t("confirmUserChange", "Apply this account change for")} ${user.full_name}?`)) return;
      save.disabled = true;
      try {
        await requestJson("api/admin/team.php", {
          method: "PATCH",
          headers: { "X-CSRF-Token": csrfToken },
          body: JSON.stringify(changes),
        });
        announce(t("userUpdated", "User account updated."));
        await Promise.all([loadUserDirectory(), loadTeam(), loadDashboard()]);
      } catch (error) {
        announce(error.message);
        save.disabled = false;
      }
    });
    const actions = document.createElement("div");
    actions.className = "user-row-actions";
    actions.append(roleSelect, statusSelect, save);
    const actionCell = document.createElement("td");
    actionCell.append(actions);
    row.append(identity, roleCell, statusCell, createDirectoryCell(planText), createDirectoryCell(paidText), createDirectoryCell(userDuration(user)), actionCell);
    body.append(row);
  }
}

async function loadUserDirectory() {
  const body = $("#member-management-list");
  try {
    const data = await requestJson("api/admin/team.php");
    directoryUsers = data.users || [];
    renderUserDirectory();
  } catch (error) {
    body.replaceChildren();
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 7;
    cell.textContent = error.message;
    row.append(cell);
    body.append(row);
  }
}

function updateMembershipButton() {
  $("#activate-membership").disabled =
    !selectedAdminMember || !$("#membership-plan").value;
}

function createPaymentMethodSelect(selected = "") {
  const select = document.createElement("select");
  select.name = "method";
  select.required = true;
  const methods = [
    ["CASH", "cash", "Cash"],
    ["BANK_TRANSFER", "bankTransfer", "Bank transfer"],
  ];
  for (const [value, key, fallback] of methods) {
    if (!paymentSettings[`${value.toLowerCase()}_enabled`]) continue;
    const option = new Option(t(key, fallback), value);
    option.selected = value === selected;
    select.add(option);
  }
  return select;
}

function applyPaymentSettings(settings) {
  paymentSettings = settings;
  const form = $("#payment-settings-form");
  form.elements.cash_enabled.checked = settings.cash_enabled;
  form.elements.bank_transfer_enabled.checked = settings.bank_transfer_enabled;
  form.elements.online_enabled.checked = Boolean(settings.online_enabled);
  form.elements.online_enabled.disabled = !settings.gateway_configured;
  $("#gateway-status-badge").textContent = settings.gateway_configured
    ? t("gatewayReady", "Online payments ready")
    : t("gatewayNotConfigured", "Provider not configured");
  $("#gateway-status-copy").textContent = settings.gateway_configured
    ? t("gatewayConfiguredHint", "The payment provider is configured. Enable online card payments to accept member checkout.")
    : t("gatewayConfigurationHint", "Online card checkout is disabled until a provider is selected and its server credentials are configured.");
  const paymentSelect = $("#payment-method");
  const previous = paymentSelect.value;
  const refreshedMethods = createPaymentMethodSelect(previous);
  paymentSelect.replaceChildren(...Array.from(refreshedMethods.options));
  paymentSelect.value = [...paymentSelect.options].some((option) => option.value === previous)
    ? previous
    : paymentSelect.options[0]?.value || "";
}

async function loadPaymentSettings() {
  try {
    const data = await requestJson("api/admin/payment-settings.php");
    applyPaymentSettings(data.settings);
  } catch (error) {
    $("#payment-settings-message").textContent = error.message;
    $("#save-payment-settings").disabled = true;
  }
}

async function loadMembershipRequests() {
  const target = $("#membership-requests");
  const data = await requestJson("api/admin/memberships.php");
  target.replaceChildren();
  if (!data.requests.length) {
    const empty = document.createElement("p");
    empty.className = "muted small";
    empty.textContent = t("noPendingRequests", "No membership requests are waiting for review.");
    target.append(empty);
    return;
  }
  for (const request of data.requests) {
    const row = document.createElement("article");
    row.className = "request-card";
    const heading = document.createElement("div");
    heading.className = "request-card-heading";
    const identity = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = request.full_name;
    const meta = document.createElement("small");
    meta.textContent = `${request.email}${request.phone ? ` | ${request.phone}` : ""}`;
    identity.append(name, meta);
    const plan = document.createElement("span");
    plan.className = "request-plan-badge";
    plan.textContent = request.plan_name;
    heading.append(identity, plan);
    const period = document.createElement("p");
    period.textContent = `${request.start_date} – ${request.end_date} · ${request.price} ${request.currency}`;
    const actions = document.createElement("div");
    actions.className = "request-actions";
    const method = createPaymentMethodSelect();
    method.setAttribute("aria-label", `${t("paymentMethod", "Payment method")} · ${request.full_name}`);
    const amount = document.createElement("input");
    amount.type = "number";
    amount.min = "0";
    amount.step = "0.01";
    amount.value = request.price;
    amount.setAttribute("aria-label", `${t("paidAmount", "Paid amount")} · ${request.full_name}`);
    const approve = document.createElement("button");
    approve.type = "button";
    approve.className = "primary-button";
    approve.textContent = t("approveActivate", "Approve & activate");
    const reject = document.createElement("button");
    reject.type = "button";
    reject.className = "danger-button";
    reject.textContent = t("rejectRequest", "Reject");
    approve.addEventListener("click", () => reviewMembershipRequest(request, "APPROVED", amount.value, method.value, approve));
    reject.addEventListener("click", () => reviewMembershipRequest(request, "REJECTED", null, method.value, reject));
    actions.append(amount, method, approve, reject);
    row.append(heading, period, actions);
    target.append(row);
  }
}

async function reviewMembershipRequest(request, decision, amount, method, button) {
  const action = decision === "APPROVED" ? t("approve", "approve") : t("reject", "reject");
  if (!window.confirm(`${t("confirmMembershipReview", "Are you sure you want to")} ${action} ${request.full_name}?`)) return;
  button.disabled = true;
  try {
    const result = await requestJson("api/admin/memberships.php", {
      method: "PATCH",
      headers: { "X-CSRF-Token": csrfToken },
      body: JSON.stringify({ membership_id: request.id, decision, amount, method }),
    });
    announce(result.message);
    await Promise.all([loadMembershipRequests(), loadDashboard(), loadUserDirectory()]);
  } catch (error) {
    announce(error.message);
    button.disabled = false;
  }
}

$("#plan-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = $("#save-plan");
  button.disabled = true;
  setOperationMessage("#plan-message", "");
  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const isEditing = Boolean(payload.plan_id);
    const result = await requestJson("api/admin/plans.php", {
      method: isEditing ? "PATCH" : "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify(payload),
    });
    setOperationMessage("#plan-message", result.message);
    resetPlanForm();
    await loadPlans();
  } catch (error) {
    setOperationMessage("#plan-message", error.message);
  } finally {
    button.disabled = false;
  }
});

$("#team-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = form.querySelector("button[type='submit']");
  button.disabled = true;
  setOperationMessage("#team-message", "");
  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const result = await requestJson("api/admin/team.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify(payload),
    });
    setOperationMessage("#team-message", result.message);
    form.reset();
    await Promise.all([loadTeam(), loadUserDirectory()]);
  } catch (error) {
    setOperationMessage("#team-message", error.message);
  } finally {
    button.disabled = false;
  }
});

$("#member-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = form.querySelector("button[type='submit']");
  button.disabled = true;
  setOperationMessage("#member-form-message", "");
  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const result = await requestJson("api/admin/members.php", {
      method: "POST",
      headers: { "X-CSRF-Token": csrfToken },
      body: JSON.stringify(payload),
    });
    setOperationMessage("#member-form-message", result.message);
    form.reset();
    await Promise.all([loadDashboard(), loadUserDirectory()]);
  } catch (error) {
    setOperationMessage("#member-form-message", error.message);
  } finally {
    button.disabled = false;
  }
});

$("#member-management-search").addEventListener("input", () => {
  clearTimeout(memberManagementTimer);
  memberManagementTimer = setTimeout(renderUserDirectory, 120);
});
document.querySelectorAll("[data-user-filter]").forEach((button) => {
  button.addEventListener("click", () => {
    activeUserFilter = button.dataset.userFilter;
    document.querySelectorAll("[data-user-filter]").forEach((filter) => filter.classList.toggle("is-active", filter === button));
    renderUserDirectory();
  });
});
$("#refresh-users").addEventListener("click", loadUserDirectory);
$("#refresh-requests").addEventListener("click", () => loadMembershipRequests().catch((error) => announce(error.message)));
$("#cancel-plan-edit").addEventListener("click", resetPlanForm);
$("#create-plan-open").addEventListener("click", () => {
  resetPlanForm();
  $("#plan-dialog").showModal();
  $("#plan-name").focus();
});
$("#close-plan-dialog").addEventListener("click", resetPlanForm);
$("#plan-dialog").addEventListener("click", (event) => {
  if (event.target === event.currentTarget) resetPlanForm();
});

$("#membership-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity() || !selectedAdminMember) return;
  const button = $("#activate-membership");
  button.disabled = true;
  setOperationMessage("#membership-message", "");
  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const result = await requestJson("api/admin/memberships.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify(payload),
    });
    setOperationMessage("#membership-message", `${result.message} ${t("validThrough", "Valid through")} ${result.ends_on}.`);
    form.reset();
    selectedAdminMember = null;
    $("#membership-user-id").value = "";
    $("#selected-admin-member").textContent = "No member selected.";
    $("#membership-starts-on").value = new Date().toISOString().slice(0, 10);
    $("#membership-amount").value = "";
    updateMembershipButton();
    await Promise.all([loadDashboard(), loadPlans(), loadMembershipRequests(), loadUserDirectory()]);
  } catch (error) {
    setOperationMessage("#membership-message", error.message);
    updateMembershipButton();
  }
});

$("#payment-settings-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const button = $("#save-payment-settings");
  button.disabled = true;
  $("#payment-settings-message").textContent = "";
  try {
    const result = await requestJson("api/admin/payment-settings.php", {
      method: "PATCH",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify({
        cash_enabled: form.elements.cash_enabled.checked,
        bank_transfer_enabled: form.elements.bank_transfer_enabled.checked,
        online_enabled: form.elements.online_enabled.checked,
      }),
    });
    applyPaymentSettings(result.settings);
    $("#payment-settings-message").textContent = result.message;
    await loadMembershipRequests();
  } catch (error) {
    $("#payment-settings-message").textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

$("#admin-member-search").addEventListener("input", () => {
  selectedAdminMember = null;
  $("#membership-user-id").value = "";
  $("#selected-admin-member").textContent = "No member selected.";
  updateMembershipButton();
  clearTimeout(memberSearchTimer);
  memberSearchTimer = setTimeout(searchAdminMembers, 250);
});
$("#membership-plan").addEventListener("change", (event) => {
  const selected = event.currentTarget.selectedOptions[0];
  if (selected?.dataset.price) $("#membership-amount").value = selected.dataset.price;
  updateMembershipButton();
});
function reportRange() {
  const from = $("#report-from").value;
  const to = $("#report-to").value;
  if (!from || !to || from > to) {
    announce(t("validReportRange", "Choose a valid report date range."));
    return null;
  }
  const dayCount =
    (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
    86_400_000;
  if (dayCount > 365) {
    announce(t("reportRangeLimit", "Choose a report range of 366 days or fewer."));
    return null;
  }
  return { from, to };
}

function downloadReport(type) {
  const range = reportRange();
  if (!range) return;
  const query = new URLSearchParams({ type, ...range });
  const link = document.createElement("a");
  link.href = `api/admin/export.php?${query.toString()}`;
  link.download = `plus-ultra-${type}-${range.from}-to-${range.to}.csv`;
  document.body.append(link);
  link.click();
  link.remove();
}

$("#revenue-currency").addEventListener("change", () => {
  if (dashboardData) renderRevenueChart(dashboardData);
});
$("#export-payments").addEventListener("click", () =>
  downloadReport("payments"),
);
$("#export-attendance").addEventListener("click", () =>
  downloadReport("attendance"),
);
$("#refresh").addEventListener("click", () =>
  Promise.all([loadDashboard(), loadPlans(), loadTeam(), loadUserDirectory(), loadMembershipRequests()])
    .catch((error) => announce(error.message)),
);
$("#logout").addEventListener("click", async () => {
  try {
    await requestJson("api/auth/logout.php", {
      method: "POST",
      headers: { "X-CSRF-Token": csrfToken },
    });
    window.navigateWithLoader("login.html");
  } catch (error) {
    announce(error.message);
  }
});

window.addEventListener("smart-gym-language-change", () => {
  renderUserDirectory();
  if (dashboardData) renderDashboard(dashboardData);
  const editingPlan = Boolean($("#plan-id").value);
  $("#save-plan").textContent = t(editingPlan ? "saveChanges" : "createPlan", editingPlan ? "Save changes" : "Create plan");
  $("#plan-dialog-title").textContent = t(editingPlan ? "editPlan" : "createPlan", editingPlan ? "Edit plan" : "Create plan");
  Promise.all([loadPlans(), loadTeam(), loadMembershipRequests()]).catch((error) => announce(error.message));
});

(async function initialize() {
  try {
    const session = await requestJson("api/auth/session.php");
    csrfToken = session.csrf_token;
    if (!session.user) {
      window.navigateWithLoader("login.html");
      return;
    }
    if (session.user.role !== "ADMIN") {
      window.navigateWithLoader(
        session.user.role === "TRAINER"
          ? "trainer.html"
          : session.user.role === "STAFF"
            ? "staff.html"
            : "member.html",
      );
      return;
    }
    $("#admin-name").textContent =
      `${session.user.first_name} ${session.user.last_name}`;
    $("#admin-avatar").textContent = `${session.user.first_name?.[0] || "A"}${session.user.last_name?.[0] || ""}`.toUpperCase();
    $("#membership-starts-on").value = new Date().toISOString().slice(0, 10);
    const today = new Date().toISOString().slice(0, 10);
    const from = new Date(Date.now() - 29 * 86_400_000)
      .toISOString()
      .slice(0, 10);
    $("#report-from").value = from;
    $("#report-to").value = today;
    await loadPaymentSettings();
    await Promise.all([loadDashboard(), loadPlans(), loadTeam(), loadUserDirectory(), loadMembershipRequests()]);
  } catch (error) {
    announce(error.message);
  } finally {
    const skeleton = document.querySelector("[data-skeleton-screen]");
    skeleton?.classList.add("is-hidden");
    skeleton?.setAttribute("aria-hidden", "true");
  }
})();

