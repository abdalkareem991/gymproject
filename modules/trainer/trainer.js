const api = {
  csrfToken: "",
  user: null,
  trainers: [],
  classes: [],
};

const $ = (selector) => document.querySelector(selector);
let selectedClassId = null;
let rosterData = null;
let pendingCancelId = null;

function t(key, fallback = key) {
  const language = document.documentElement.lang === "ar" ? "ar" : "en";
  return window.smartGymTranslations?.[language]?.[key] || fallback;
}

async function request(url, options = {}) {
  const result = await window.apiClient(url, options);
  return { ...(result.data || {}), message: result.message };
}

function announce(message) {
  const notice = $("#notice");
  notice.textContent = message || "";
  notice.classList.toggle("show", Boolean(message));
}

function formatDateTime(value) {
  const normalized = value.includes("T") ? value : value.replace(" ", "T") + "Z";
  return new Intl.DateTimeFormat(document.documentElement.lang === "ar" ? "ar" : "en", {
    weekday: "short", month: "short", day: "numeric", hour: "numeric", minute: "2-digit",
  }).format(new Date(normalized));
}

function classStatus(item) {
  if (item.status !== "SCHEDULED") return item.status;
  const now = Date.now();
  const starts = new Date(item.starts_at.replace(" ", "T") + "Z").getTime();
  const ends = new Date(item.ends_at.replace(" ", "T") + "Z").getTime();
  return starts <= now && ends > now ? "IN_PROGRESS" : item.status;
}

function statusLabel(status) {
  const keys = { SCHEDULED: "trainerStatusScheduled", CANCELLED: "trainerStatusCancelled", COMPLETED: "trainerStatusCompleted", IN_PROGRESS: "trainerStatusInProgress" };
  const fallbacks = { SCHEDULED: "Scheduled", CANCELLED: "Cancelled", COMPLETED: "Completed", IN_PROGRESS: "In progress" };
  return t(keys[status], fallbacks[status] || status);
}

function renderMetrics() {
  const now = new Date();
  const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const endOfDay = startOfDay + 86400000;
  const today = api.classes.filter((item) => {
    const starts = new Date(item.starts_at.replace(" ", "T") + "Z").getTime();
    return starts >= startOfDay && starts < endOfDay && item.status !== "CANCELLED";
  });
  const scheduled = api.classes.filter((item) => ["SCHEDULED", "IN_PROGRESS"].includes(classStatus(item)));
  const booked = scheduled.reduce((sum, item) => sum + Number(item.booked_count || 0), 0);
  const seats = scheduled.reduce((sum, item) => sum + Number(item.capacity || 0), 0);
  $("#kpi-classes-today").textContent = String(today.length);
  $("#kpi-booked-athletes").textContent = String(booked);
  $("#kpi-occupancy").textContent = `${seats ? Math.round((booked / seats) * 100) : 0}%`;
  $("#kpi-completed").textContent = String(api.classes.filter((item) => item.status === "COMPLETED").length);
}

function classCard(item) {
  const card = document.createElement("article");
  card.className = "trainer-class";
  const heading = document.createElement("div");
  heading.className = "trainer-class-heading";
  const title = document.createElement("h3");
  title.textContent = item.title;
  const type = document.createElement("span");
  type.className = "class-type";
  type.textContent = item.class_type || t("classesGroupClass", "Group class");
  heading.append(title, type);

  const status = document.createElement("span");
  const currentStatus = classStatus(item);
  status.className = `class-status ${currentStatus.toLowerCase().replace("_", "-")}`;
  status.textContent = statusLabel(currentStatus);
  heading.append(status);

  const time = document.createElement("p");
  time.className = "class-time";
  time.textContent = `${formatDateTime(item.starts_at)} · ${formatDateTime(item.ends_at)}`;
  const info = document.createElement("p");
  info.className = "class-info";
  const details = [`${item.booked_count} ${t("trainerBooked", "booked")}`, `${t("trainerCapacity", "Capacity")}: ${item.capacity}`];
  if (api.user.role === "ADMIN") details.push(`${t("trainerCoach", "Coach")}: ${item.trainer_first_name} ${item.trainer_last_name}`);
  info.textContent = details.join(" · ");
  const progress = document.createElement("div");
  progress.className = "seat-progress";
  progress.setAttribute("role", "progressbar");
  progress.setAttribute("aria-valuemin", "0");
  progress.setAttribute("aria-valuemax", String(item.capacity));
  progress.setAttribute("aria-valuenow", String(item.booked_count));
  const fill = document.createElement("span");
  fill.style.width = `${Math.min(100, item.capacity ? (item.booked_count / item.capacity) * 100 : 0)}%`;
  progress.append(fill);

  const actions = document.createElement("div");
  actions.className = "class-actions";
  const rosterButton = document.createElement("button");
  rosterButton.type = "button";
  rosterButton.className = "secondary-button";
  rosterButton.textContent = t("trainerViewRoster", "View roster");
  rosterButton.addEventListener("click", () => loadRoster(item));
  actions.append(rosterButton);
  const canCancel = item.status === "SCHEDULED" && new Date(item.starts_at.replace(" ", "T") + "Z") > new Date();
  if (canCancel) {
    const cancelButton = document.createElement("button");
    cancelButton.type = "button";
    cancelButton.className = "text-button";
    cancelButton.textContent = t("trainerCancelClass", "Cancel class");
    cancelButton.addEventListener("click", () => openCancelDialog(item.id));
    actions.append(cancelButton);
  }
  card.append(heading, time, info, progress, actions);
  return card;
}

function renderClasses() {
  const list = $("#class-list");
  $("#class-count").textContent = String(api.classes.length);
  list.replaceChildren();
  if (!api.classes.length) {
    const empty = document.createElement("p");
    empty.className = "muted";
    empty.textContent = t("trainerNoClasses", "No sessions are scheduled yet.");
    list.append(empty);
  } else {
    for (const item of api.classes) list.append(classCard(item));
  }
  renderMetrics();
}

function renderTrainerOptions() {
  const field = $("#trainer-select-field");
  if (api.user.role !== "ADMIN") return;
  field.hidden = false;
  const select = $("#trainer-select");
  select.replaceChildren(new Option(t("trainerChooseCoach", "Choose a trainer"), ""));
  for (const trainer of api.trainers) select.add(new Option(`${trainer.first_name} ${trainer.last_name}`, trainer.id));
  $("#create-button").disabled = api.trainers.length === 0;
}

async function loadSchedule() {
  const data = await request("api/trainer/classes.php");
  api.classes = data.classes || [];
  api.trainers = data.trainers || [];
  renderTrainerOptions();
  renderClasses();
}

function renderRoster(data) {
  rosterData = data;
  const classInfo = data.class;
  $("#roster-title").textContent = classInfo.title;
  $("#roster-count").textContent = `${data.roster.length} / ${classInfo.capacity}`;
  const started = new Date(classInfo.starts_at.replace(" ", "T") + "Z") <= new Date();
  const hint = classInfo.status === "CANCELLED"
    ? t("trainerClassCancelledHint", "This class was cancelled.")
    : started ? t("trainerAttendanceHint", "Mark each booked member as attended or absent.") : t("trainerAttendanceLaterHint", "Attendance controls will be available when the class starts.");
  $("#roster-hint").textContent = hint;
  const counts = data.roster.reduce((result, booking) => {
    if (booking.booking_status === "ATTENDED") result.present += 1;
    else if (booking.booking_status === "NO_SHOW") result.absent += 1;
    else if (booking.booking_status === "BOOKED") result.pending += 1;
    return result;
  }, { present: 0, absent: 0, pending: 0 });
  $("#attendance-present").textContent = String(counts.present);
  $("#attendance-absent").textContent = String(counts.absent);
  $("#attendance-pending").textContent = String(counts.pending);
  $("#attendance-summary").hidden = false;

  const list = $("#roster-list");
  list.replaceChildren();
  if (!data.roster.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 6;
    cell.className = "muted";
    cell.textContent = t("trainerNoMembers", "No members have booked this class.");
    row.append(cell);
    list.append(row);
    return;
  }
  for (const booking of data.roster) {
    const row = document.createElement("tr");
    const memberId = document.createElement("td");
    memberId.textContent = booking.member_code || String(booking.user_id);
    const name = document.createElement("td");
    name.textContent = `${booking.first_name} ${booking.last_name}`.trim();
    const phone = document.createElement("td");
    phone.textContent = booking.phone || "—";
    const subscriptionCell = document.createElement("td");
    const subscription = document.createElement("span");
    subscription.className = `booking-status ${booking.subscription_status.toLowerCase()}`;
    subscription.textContent = booking.subscription_status === "ACTIVE" ? t("trainerSubscriptionActive", "Active") : t("trainerSubscriptionInactive", "Inactive");
    subscriptionCell.append(subscription);
    const attendanceCell = document.createElement("td");
    const attendance = document.createElement("span");
    attendance.className = `booking-status ${booking.booking_status.toLowerCase()}`;
    const attendanceKeys = { BOOKED: ["trainerStatusPending", "Pending"], ATTENDED: ["trainerStatusPresent", "Present"], NO_SHOW: ["trainerStatusAbsent", "Absent"] };
    const attendanceText = attendanceKeys[booking.booking_status] || ["trainerStatusCancelled", "Cancelled"];
    attendance.textContent = t(attendanceText[0], attendanceText[1]);
    attendanceCell.append(attendance);
    const actionsCell = document.createElement("td");
    const controls = document.createElement("div");
    controls.className = "attendance-controls";
    if (booking.booking_status === "BOOKED" && started && classInfo.status !== "CANCELLED") {
      for (const [statusValue, labelKey, fallback] of [["ATTENDED", "trainerMarkPresent", "Present"], ["NO_SHOW", "trainerMarkAbsent", "Absent"]]) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "mini-button";
        button.textContent = t(labelKey, fallback);
        button.addEventListener("click", () => markAttendance(booking.booking_id, statusValue, button));
        controls.append(button);
      }
    } else {
      controls.textContent = "—";
    }
    actionsCell.append(controls);
    row.append(memberId, name, phone, subscriptionCell, attendanceCell, actionsCell);
    list.append(row);
  }
}

async function loadRoster(item) {
  selectedClassId = item.id;
  $("#roster-title").textContent = item.title;
  $("#roster-hint").textContent = t("trainerLoadingRoster", "Loading roster…");
  $("#roster-list").replaceChildren();
  try {
    renderRoster(await request(`api/trainer/roster.php?class_id=${encodeURIComponent(item.id)}`));
    $("#roster").scrollIntoView({ behavior: "smooth", block: "start" });
  } catch (error) {
    $("#roster-hint").textContent = error.message;
  }
}

function openCancelDialog(classId) {
  pendingCancelId = classId;
  const dialog = $("#cancel-dialog");
  if (typeof dialog.showModal === "function") dialog.showModal();
  else if (window.confirm(t("trainerCancelCopy", "Members will see that this class is cancelled."))) confirmCancel();
}

async function confirmCancel() {
  if (!pendingCancelId) return;
  const classId = pendingCancelId;
  const button = $("#cancel-dialog-confirm");
  button.disabled = true;
  try {
    const result = await request("api/trainer/cancel-class.php", {
      method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": api.csrfToken },
      body: JSON.stringify({ class_id: classId }),
    });
    $("#cancel-dialog").close();
    pendingCancelId = null;
    announce(result.message);
    await loadSchedule();
    const selected = api.classes.find((item) => item.id === selectedClassId);
    if (selected) await loadRoster(selected);
  } catch (error) {
    announce(error.message);
  } finally {
    button.disabled = false;
  }
}

async function markAttendance(bookingId, status, button) {
  button.disabled = true;
  try {
    const result = await request("api/trainer/attendance.php", {
      method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": api.csrfToken },
      body: JSON.stringify({ booking_id: bookingId, status }),
    });
    announce(result.message);
    await loadSchedule();
    const selected = api.classes.find((item) => item.id === selectedClassId);
    if (selected) await loadRoster(selected);
  } catch (error) {
    announce(error.message);
    button.disabled = false;
  }
}

function rerenderForLanguage() {
  renderClasses();
  if (rosterData) renderRoster(rosterData);
}

$("#class-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = $("#create-button");
  button.disabled = true;
  $("#form-message").textContent = "";
  const payload = Object.fromEntries(new FormData(form).entries());
  payload.starts_at = new Date(payload.starts_at).toISOString();
  payload.ends_at = new Date(payload.ends_at).toISOString();
  try {
    const result = await request("api/trainer/classes.php", {
      method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": api.csrfToken }, body: JSON.stringify(payload),
    });
    $("#form-message").textContent = result.message;
    form.reset();
    $('#class-form [name="capacity"]').value = 12;
    await loadSchedule();
  } catch (error) {
    $("#form-message").textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

$("#refresh").addEventListener("click", () => loadSchedule().catch((error) => announce(error.message)));
$("#cancel-dialog-close").addEventListener("click", () => { pendingCancelId = null; $("#cancel-dialog").close(); });
$("#cancel-dialog-confirm").addEventListener("click", confirmCancel);
$("#cancel-dialog").addEventListener("cancel", () => { pendingCancelId = null; });
$("#logout").addEventListener("click", async () => {
  try {
    await request("api/auth/logout.php", { method: "POST", headers: { "X-CSRF-Token": api.csrfToken } });
    window.navigateWithLoader("login.html");
  } catch (error) { announce(error.message); }
});
window.addEventListener("smart-gym-language-change", rerenderForLanguage);

(async function initialize() {
  const skeleton = document.querySelector("[data-skeleton-screen]");
  try {
    const session = await request("api/auth/session.php");
    api.csrfToken = session.csrf_token;
    if (!session.user) {
      window.navigateWithLoader("login.html");
      return;
    }
    if (!["TRAINER", "ADMIN"].includes(session.user.role)) {
      window.navigateWithLoader(session.user.role === "STAFF" ? "staff.html" : "member.html");
      return;
    }
    api.user = session.user;
    const fullName = `${session.user.first_name} ${session.user.last_name}`.trim();
    $("#trainer-name").textContent = fullName;
    $("#trainer-avatar").textContent = fullName.charAt(0).toUpperCase() || "T";
    const minimum = new Date(Date.now() + 60000);
    const localMinimum = new Date(minimum.getTime() - minimum.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    $("#starts-at").min = localMinimum;
    $("#ends-at").min = localMinimum;
    await loadSchedule();
  } catch (error) {
    announce(error.message);
    $("#class-list").replaceChildren();
    const message = document.createElement("p");
    message.className = "muted";
    message.textContent = error.message;
    $("#class-list").append(message);
  } finally {
    if (skeleton) skeleton.classList.add("is-hidden");
  }
})();
