const boardTabs = document.getElementById("boardTabs");
const boardPanel = document.getElementById("boardPanel");
const highlightStrip = document.getElementById("highlightStrip");
const noticeTemplate = document.getElementById("noticeTemplate");
const installButton = document.getElementById("installButton");
const tagFilterBar = document.getElementById("tagFilterBar");
const activeTagChip = document.getElementById("activeTagChip");
const clearTagFilter = document.getElementById("clearTagFilter");
const scopeFilterBar = document.getElementById("scopeFilterBar");
const attachmentModal = document.getElementById("attachmentModal");
const attachmentModalBody = document.getElementById("attachmentModalBody");
const attachmentModalName = document.getElementById("attachmentModalName");
const attachmentModalOpen = document.getElementById("attachmentModalOpen");
const attachmentModalClose = document.getElementById("attachmentModalClose");
const feedbackForm = document.getElementById("feedbackForm");
const feedbackBoard = document.getElementById("feedbackBoard");
const feedbackType = document.getElementById("feedbackType");
const feedbackMessage = document.getElementById("feedbackMessage");
const feedbackAnonymous = document.getElementById("feedbackAnonymous");
const feedbackEmail = document.getElementById("feedbackEmail");
const feedbackOtpPanel = document.getElementById("feedbackOtpPanel");
const feedbackOtp = document.getElementById("feedbackOtp");
const feedbackOtpStatus = document.getElementById("feedbackOtpStatus");
const feedbackVerifyButton = document.getElementById("feedbackVerifyButton");
const feedbackResetButton = document.getElementById("feedbackResetButton");
const feedbackSuccess = document.getElementById("feedbackSuccess");
const feedbackError = document.getElementById("feedbackError");
const calendarList = document.getElementById("calendarList");
const calendarStatus = document.getElementById("calendarStatus");
const calendarOpenLink = document.getElementById("calendarOpenLink");
const calendarSubscribeLink = document.getElementById("calendarSubscribeLink");
const calendarMonthSelect = document.getElementById("calendarMonthSelect");
const appHomeButton = document.getElementById("appHomeButton");
const homeHero = document.getElementById("homeHero");
const appSections = [...document.querySelectorAll("[data-app-section]")];
const appViewLinks = [...document.querySelectorAll("[data-app-view]")];
const appBottomLinks = [...document.querySelectorAll(".app-bottom-link[data-nav-section]")];
const CLIENT_ID_KEY = "nusaceBulletinClientId";
const API_VERSION = "20260602-admin20";
const MAX_NOTICE_PREVIEW_WORDS = 40;
const APP_VIEW_KEY = "nusaceBulletinAppView";

let deferredPrompt;
let boards = [];
let activeTag = null;
let activeScope = "all";
let todayValue = null;
let priorityNotices = [];
let hasReloadedForUpdate = false;
let activeBoardId = "sace";
let feedbackOtpExpiresAt = null;
let feedbackOtpTimer = null;
let currentAppView = "home";
let calendarEventsLoaded = false;
let activeCalendarMonth = "";
const expandedNoticeCards = new Set();
const clientId = getClientId();

function compareNotices(left, right) {
  const leftPinned = Boolean(left.pinned);
  const rightPinned = Boolean(right.pinned);

  if (leftPinned !== rightPinned) {
    return Number(rightPinned) - Number(leftPinned);
  }

  if (left.date !== right.date) {
    return right.date.localeCompare(left.date);
  }

  return (right.updated_at || "").localeCompare(left.updated_at || "");
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => {
    const entities = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      "\"": "&quot;",
      "'": "&#39;"
    };

    return entities[char] || char;
  });
}

function getClientId() {
  try {
    const existing = window.localStorage.getItem(CLIENT_ID_KEY);
    if (existing) {
      return existing;
    }

    const generated = `client-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
    window.localStorage.setItem(CLIENT_ID_KEY, generated);
    return generated;
  } catch (error) {
    return `client-${Date.now()}-fallback`;
  }
}

function formatDate(value) {
  const parsedDate = new Date(value);

  if (Number.isNaN(parsedDate.getTime())) {
    return value;
  }

  return parsedDate.toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric"
  });
}

function dateDiffInDays(left, right) {
  const leftDate = new Date(`${left}T00:00:00`);
  const rightDate = new Date(`${right}T00:00:00`);

  if (Number.isNaN(leftDate.getTime()) || Number.isNaN(rightDate.getTime())) {
    return Number.POSITIVE_INFINITY;
  }

  return Math.floor((leftDate - rightDate) / 86400000);
}

function isInCurrentMonth(value, today) {
  return typeof value === "string" && typeof today === "string" && value.slice(0, 7) === today.slice(0, 7);
}

function noticeAgeInDays(notice) {
  const sourceValue = notice.created_at || notice.date;
  if (!sourceValue || !todayValue) {
    return Number.POSITIVE_INFINITY;
  }

  const normalizedSource = sourceValue.includes("T") ? sourceValue.slice(0, 10) : sourceValue;
  return dateDiffInDays(todayValue, normalizedSource);
}

function isNewNotice(notice) {
  const age = noticeAgeInDays(notice);
  return age >= 0 && age <= 2;
}

function matchesScope(notice) {
  if (!todayValue || activeScope === "all") {
    return true;
  }

  if (activeScope === "weekly") {
    const diff = dateDiffInDays(todayValue, notice.date);
    return diff >= 0 && diff <= 6;
  }

  if (activeScope === "monthly") {
    return isInCurrentMonth(notice.date, todayValue);
  }

  return true;
}

function noticeStatusLabel(notice) {
  if (notice.scope_status === "scheduled") {
    return notice.visible_from ? `Upcoming ${formatDate(notice.visible_from)}` : "Upcoming";
  }

  return notice.pinned ? "Pinned" : "Current";
}

function filterNotices(notices) {
  return notices.filter((notice) => matchesScope(notice));
}

function topTagsFromNotices(notices, limit = 3) {
  const counts = new Map();

  notices.forEach((notice) => {
    (notice.tags || []).forEach((tag) => {
      const normalizedTag = String(tag || "").trim();
      if (!normalizedTag) {
        return;
      }

      counts.set(normalizedTag, (counts.get(normalizedTag) || 0) + 1);
    });
  });

  return [...counts.entries()]
    .sort((left, right) => {
      if (right[1] !== left[1]) {
        return right[1] - left[1];
      }

      return left[0].localeCompare(right[0]);
    })
    .slice(0, limit)
    .map(([tag]) => tag);
}

function renderHighlights() {
  const featured = [...priorityNotices]
    .sort(compareNotices)
    .slice(0, 4);

  highlightStrip.innerHTML = featured
    .map(
      (item) => `
        <article class="highlight-card">
          ${isNewNotice(item) ? '<span class="notice-new-indicator" aria-label="New notice"></span>' : ""}
          <span class="eyebrow">${escapeHtml(item.board_name || item.board || "")}</span>
          <h3>${escapeHtml(item.title)}</h3>
          <hr class="notice-divider">
          <div class="notice-text-block">
            <p class="notice-text">${escapeHtml(item.text)}</p>
            <button type="button" class="notice-read-more" hidden>Read more</button>
          </div>
          <div class="highlight-card-footer">
            ${item.attachment && item.attachment.path ? `<div class="notice-attachment"><button type="button" class="attachment-link" data-attachment='${escapeHtml(JSON.stringify(item.attachment))}'>Attachment: ${escapeHtml(item.attachment.name || "View file")}</button></div>` : ""}
            <p class="notice-date">${item.pinned ? "Pinned" : `Visible ${escapeHtml(formatDate(item.visible_from || item.date))}`}</p>
          </div>
        </article>
      `
    )
    .join("");

  highlightStrip.querySelectorAll("[data-attachment]").forEach((button) => {
    button.addEventListener("click", () => {
      try {
        const raw = button.getAttribute("data-attachment") || "";
        openAttachmentModal(JSON.parse(raw));
      } catch (error) {
      }
    });
  });

  highlightStrip.querySelectorAll(".highlight-card").forEach((card, index) => {
    const textNode = card.querySelector(".notice-text");
    const toggleButton = card.querySelector(".notice-read-more");
    const fullText = featured[index]?.text || "";
    setupNoticeReadMore(card, textNode, toggleButton, fullText);
  });
}

function updateScopeButtons() {
  scopeFilterBar.querySelectorAll("[data-scope]").forEach((button) => {
    button.classList.toggle("is-active", button.dataset.scope === activeScope);
  });
}

function renderTabs(activeId) {
  boardTabs.innerHTML = "";

  boards.forEach((board) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "board-tab";
    button.textContent = board.name;
    button.setAttribute("aria-selected", String(activeTag === null && board.id === activeId));
    button.addEventListener("click", () => {
      activeTag = null;
      renderBoard(board.id);
    });
    boardTabs.appendChild(button);
  });
}

function populateFeedbackBoards() {
  if (!feedbackBoard) {
    return;
  }

  const previousValue = feedbackBoard.value;
  feedbackBoard.innerHTML = '<option value="">Select a department</option>';

  boards.forEach((board) => {
    const option = document.createElement("option");
    option.value = board.id;
    option.textContent = board.name;
    feedbackBoard.appendChild(option);
  });

  feedbackBoard.value = previousValue || activeBoardId || "";
  if (!feedbackBoard.value && feedbackBoard.options.length > 1) {
    feedbackBoard.selectedIndex = 1;
  }
}

function createTagChip(tag) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "tag-chip";
  button.textContent = tag;
  button.addEventListener("click", () => renderTaggedNotices(tag));
  return button;
}

function normalizeAttachmentUrl(path) {
  return typeof path === "string" ? path.replace(/^\.?\//, "") : "";
}

function clearFeedbackMessages() {
  if (feedbackSuccess) {
    feedbackSuccess.hidden = true;
    feedbackSuccess.textContent = "";
  }

  if (feedbackError) {
    feedbackError.hidden = true;
    feedbackError.textContent = "";
  }
}

function setFeedbackError(message) {
  if (!feedbackError) {
    return;
  }

  feedbackError.textContent = message;
  feedbackError.hidden = false;
  if (feedbackSuccess) {
    feedbackSuccess.hidden = true;
  }
}

function setFeedbackSuccess(message) {
  if (!feedbackSuccess) {
    return;
  }

  feedbackSuccess.textContent = message;
  feedbackSuccess.hidden = false;
  if (feedbackError) {
    feedbackError.hidden = true;
  }
}

function setFeedbackFieldsDisabled(disabled) {
  [feedbackBoard, feedbackType, feedbackMessage, feedbackAnonymous, feedbackEmail].forEach((field) => {
    if (field) {
      field.disabled = disabled;
    }
  });
}

function resetFeedbackOtpState() {
  feedbackOtpExpiresAt = null;

  if (feedbackOtpTimer !== null) {
    window.clearInterval(feedbackOtpTimer);
    feedbackOtpTimer = null;
  }

  if (feedbackOtpPanel) {
    feedbackOtpPanel.hidden = true;
  }

  if (feedbackOtp) {
    feedbackOtp.value = "";
  }

  if (feedbackOtpStatus) {
    feedbackOtpStatus.textContent = "Enter the code from your email.";
  }

  setFeedbackFieldsDisabled(false);
}

function resetFeedbackForm() {
  feedbackForm?.reset();
  if (feedbackAnonymous) {
    feedbackAnonymous.checked = true;
  }
  populateFeedbackBoards();
  if (feedbackBoard && activeBoardId) {
    feedbackBoard.value = activeBoardId;
  }
  resetFeedbackOtpState();
  clearFeedbackMessages();
}

function formatOtpCountdown(targetTime) {
  const remainingMs = Math.max(0, targetTime - Date.now());
  const totalSeconds = Math.ceil(remainingMs / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, "0")}`;
}

function startFeedbackOtpCountdown(expiresAt) {
  feedbackOtpExpiresAt = new Date(expiresAt).getTime();
  if (Number.isNaN(feedbackOtpExpiresAt)) {
    setFeedbackError("Invalid OTP expiry returned by the server.");
    return;
  }

  if (feedbackOtpPanel) {
    feedbackOtpPanel.hidden = false;
  }

  setFeedbackFieldsDisabled(true);

  const tick = () => {
    if (!feedbackOtpStatus || feedbackOtpExpiresAt === null) {
      return;
    }

    const remaining = feedbackOtpExpiresAt - Date.now();
    if (remaining <= 0) {
      feedbackOtpStatus.textContent = "OTP expired. Request a new code.";
      if (feedbackOtpTimer !== null) {
        window.clearInterval(feedbackOtpTimer);
        feedbackOtpTimer = null;
      }
      return;
    }

    feedbackOtpStatus.textContent = `Enter the code from your email. Expires in ${formatOtpCountdown(feedbackOtpExpiresAt)}.`;
  };

  if (feedbackOtpTimer !== null) {
    window.clearInterval(feedbackOtpTimer);
  }

  tick();
  feedbackOtpTimer = window.setInterval(tick, 1000);
}

async function requestFeedbackOtp() {
  clearFeedbackMessages();

  const response = await fetch("api/feedback.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      action: "request_otp",
      board_id: feedbackBoard?.value || "",
      type: feedbackType?.value || "",
      message: feedbackMessage?.value || "",
      email: feedbackEmail?.value || "",
      is_anonymous: Boolean(feedbackAnonymous?.checked)
    })
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || `Request failed: ${response.status}`);
  }

  setFeedbackSuccess(payload.message || "OTP sent.");
  startFeedbackOtpCountdown(payload.expires_at);
}

async function verifyFeedbackOtp() {
  clearFeedbackMessages();

  const response = await fetch("api/feedback.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      action: "verify_otp",
      board_id: feedbackBoard?.value || "",
      email: feedbackEmail?.value || "",
      otp: feedbackOtp?.value || ""
    })
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || `Request failed: ${response.status}`);
  }

  resetFeedbackForm();
  setFeedbackSuccess(payload.message || "Feedback submitted successfully.");
}

function reactionData(notice, type) {
  return notice?.reactions?.[type] || { count: 0, reacted: false };
}

async function toggleReaction(notice, reactionType, reactionButton) {
  reactionButton.disabled = true;

  try {
    const response = await fetch("api/reactions.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        notice_id: notice.id,
        reaction_type: reactionType,
        client_id: clientId
      })
    });

    const payload = await response.json();
    if (!response.ok) {
      throw new Error(payload.error || `Request failed: ${response.status}`);
    }

    notice.reactions = payload.reactions || notice.reactions || {};
    updateReactionButton(reactionButton, reactionData(notice, reactionType));
  } catch (error) {
    window.alert(error.message);
  } finally {
    reactionButton.disabled = false;
  }
}

function updateReactionButton(button, state) {
  button.classList.toggle("is-active", Boolean(state?.reacted));
  const countNode = button.querySelector(".reaction-count");
  if (countNode) {
    countNode.textContent = String(state?.count || 0);
  }
}

function openAttachmentModal(attachment) {
  if (!attachmentModal || !attachmentModalBody || !attachmentModalName || !attachmentModalOpen) {
    return;
  }

  const source = normalizeAttachmentUrl(attachment?.path || "");
  if (!source) {
    return;
  }

  attachmentModalName.textContent = attachment.name || "Notice attachment";
  attachmentModalOpen.href = source;

  if (attachment.kind === "image") {
    attachmentModalBody.innerHTML = `<img src="${escapeHtml(source)}" alt="${escapeHtml(attachment.name || "Notice attachment")}" class="attachment-preview-image">`;
  } else if (attachment.kind === "pdf") {
    attachmentModalBody.innerHTML = `<iframe src="${escapeHtml(source)}" class="attachment-preview-frame" title="${escapeHtml(attachment.name || "Notice attachment")}"></iframe>`;
  } else {
    attachmentModalBody.innerHTML = `<p>Preview is not available for this attachment.</p>`;
  }

  attachmentModal.hidden = false;
  document.body.classList.add("modal-open");
}

function closeAttachmentModal() {
  if (!attachmentModal || !attachmentModalBody) {
    return;
  }

  attachmentModal.hidden = true;
  attachmentModalBody.innerHTML = "";
  document.body.classList.remove("modal-open");
}

function noticePreviewText(value, maxWords = MAX_NOTICE_PREVIEW_WORDS) {
  const source = String(value || "").trim();
  const paragraphs = source.split(/\n\s*\n/).map((paragraph) => paragraph.trim()).filter(Boolean);
  let usedWords = 0;
  let truncated = false;
  const previewParagraphs = [];

  for (const paragraph of paragraphs) {
    const words = paragraph.split(/\s+/).filter(Boolean);
    if (usedWords >= maxWords) {
      truncated = true;
      break;
    }

    const remaining = maxWords - usedWords;
    if (words.length <= remaining) {
      previewParagraphs.push(paragraph);
      usedWords += words.length;
      continue;
    }

    previewParagraphs.push(`${words.slice(0, remaining).join(" ")}...`);
    usedWords += remaining;
    truncated = true;
    break;
  }

  if (previewParagraphs.length === 0) {
    return { text: source, truncated: false };
  }

  return {
    text: previewParagraphs.join("\n\n"),
    truncated
  };
}

function collapseNoticeCard(card) {
  if (!(card instanceof HTMLElement)) {
    return;
  }

  const textNode = card.querySelector(".notice-text");
  const toggleButton = card.querySelector(".notice-read-more");
  const fullText = card.dataset.fullText || "";
  const preview = noticePreviewText(fullText);

  if (!(textNode instanceof HTMLElement) || !(toggleButton instanceof HTMLButtonElement)) {
    return;
  }

  textNode.textContent = preview.text;
  card.classList.remove("is-expanded");
  toggleButton.textContent = "Read more";
  toggleButton.hidden = !preview.truncated;
  expandedNoticeCards.delete(card);
}

function collapseExpandedNoticeCards(exceptCard = null) {
  [...expandedNoticeCards].forEach((card) => {
    if (card !== exceptCard) {
      collapseNoticeCard(card);
    }
  });
}

function setupNoticeReadMore(card, textNode, toggleButton, fullText) {
  if (!(card instanceof HTMLElement) || !(textNode instanceof HTMLElement) || !(toggleButton instanceof HTMLButtonElement)) {
    return;
  }

  card.dataset.fullText = fullText;
  const preview = noticePreviewText(fullText);
  textNode.textContent = preview.text;
  toggleButton.hidden = !preview.truncated;

  if (!preview.truncated) {
    return;
  }

  toggleButton.addEventListener("click", (event) => {
    event.preventDefault();
    const expanded = card.classList.toggle("is-expanded");

    if (expanded) {
      collapseExpandedNoticeCards(card);
      textNode.textContent = fullText;
      toggleButton.textContent = "Show less";
      expandedNoticeCards.add(card);
      return;
    }

    collapseNoticeCard(card);
  });
}

function initializeNoticeCardCollapseHandlers() {
  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }

    const activeCard = target.closest(".notice-card, .highlight-card");
    collapseExpandedNoticeCards(activeCard);
  });

  window.addEventListener("scroll", () => {
    [...expandedNoticeCards].forEach((card) => {
      const rect = card.getBoundingClientRect();
      const isVisible = rect.bottom > 40 && rect.top < window.innerHeight - 40;
      if (!isVisible) {
        collapseNoticeCard(card);
      }
    });
  }, { passive: true });
}

function setActiveBottomNav(sectionId) {
  appBottomLinks.forEach((link) => {
    link.classList.toggle("is-active", link.dataset.navSection === sectionId);
  });
}

function formatCalendarDate(dateValue) {
  return new Intl.DateTimeFormat("en-PH", {
    weekday: "short",
    month: "short",
    day: "numeric",
    year: "numeric"
  }).format(dateValue);
}

function formatCalendarTime(dateValue) {
  return new Intl.DateTimeFormat("en-PH", {
    hour: "numeric",
    minute: "2-digit"
  }).format(dateValue);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function renderCalendarEvents(events) {
  if (!calendarList || !calendarStatus) {
    return;
  }

  if (!Array.isArray(events) || events.length === 0) {
    calendarStatus.textContent = "No published calendar events are available right now.";
    calendarList.innerHTML = "";
    return;
  }

  calendarStatus.textContent = `${events.length} published calendar event${events.length === 1 ? "" : "s"} loaded.`;
  calendarList.innerHTML = events.map((event) => {
    const startsAt = new Date(event.starts_at);
    const endsAt = event.ends_at ? new Date(event.ends_at) : null;
    const hasEnd = endsAt instanceof Date && !Number.isNaN(endsAt.valueOf());
    const sameDay = hasEnd ? startsAt.toDateString() === endsAt.toDateString() : true;
    const dateLine = event.is_all_day
      ? `${formatCalendarDate(startsAt)} · All day`
      : hasEnd && sameDay
        ? `${formatCalendarDate(startsAt)} · ${formatCalendarTime(startsAt)} - ${formatCalendarTime(endsAt)}`
        : `${formatCalendarDate(startsAt)} · ${formatCalendarTime(startsAt)}`;
    const endLine = !event.is_all_day && hasEnd && !sameDay
      ? `<p class="calendar-event-meta">Ends ${escapeHtml(formatCalendarDate(endsAt))} · ${escapeHtml(formatCalendarTime(endsAt))}</p>`
      : "";
    const location = event.location
      ? `<p class="calendar-event-meta">Location: ${escapeHtml(event.location)}</p>`
      : "";
    const description = event.description
      ? `<p class="calendar-event-description">${escapeHtml(event.description)}</p>`
      : "";
    const openLink = event.url
      ? `<a class="calendar-event-link" href="${escapeHtml(event.url)}" target="_blank" rel="noopener">Open event link</a>`
      : "";

    return `
      <article class="calendar-event-card">
        <div class="calendar-event-date">${escapeHtml(formatCalendarDate(startsAt))}</div>
        <div class="calendar-event-body">
          <p class="eyebrow">Published Calendar Event</p>
          <h3>${escapeHtml(event.title || "Untitled event")}</h3>
          <p class="calendar-event-meta">${escapeHtml(dateLine)}</p>
          ${endLine}
          ${location}
          ${description}
          ${openLink}
        </div>
      </article>
    `;
  }).join("");
}

function renderCalendarMonthOptions(months, selectedMonth) {
  if (!calendarMonthSelect) {
    return;
  }

  if (!Array.isArray(months) || months.length === 0) {
    calendarMonthSelect.innerHTML = '<option value="">No months available</option>';
    calendarMonthSelect.disabled = true;
    return;
  }

  calendarMonthSelect.disabled = false;
  calendarMonthSelect.innerHTML = months.map((month) => `
    <option value="${escapeHtml(month.value || "")}"${month.value === selectedMonth ? " selected" : ""}>${escapeHtml(month.label || month.value || "")}</option>
  `).join("");
}

async function loadCalendarEvents(force = false, month = activeCalendarMonth) {
  if (!calendarList || !calendarStatus) {
    return;
  }

  if (calendarEventsLoaded && !force && month === activeCalendarMonth) {
    return;
  }

  calendarStatus.textContent = "Loading calendar events...";

  try {
    const query = month ? `?month=${encodeURIComponent(month)}` : "";
    const response = await fetch(`api/calendar.php${query}`, {
      headers: {
        "Accept": "application/json"
      }
    });
    const payload = await response.json();

    if (calendarOpenLink && payload.calendarHtmlUrl) {
      calendarOpenLink.href = payload.calendarHtmlUrl;
    }

    if (calendarSubscribeLink && payload.calendarIcsUrl) {
      calendarSubscribeLink.href = payload.calendarIcsUrl;
    }

    if (!response.ok) {
      throw new Error(payload.error || "Unable to load the published calendar.");
    }

    activeCalendarMonth = payload.selectedMonth || month || "";
    renderCalendarMonthOptions(payload.availableMonths || [], activeCalendarMonth);
    renderCalendarEvents(payload.events || []);
    calendarEventsLoaded = true;
  } catch (error) {
    calendarStatus.textContent = error instanceof Error
      ? error.message
      : "Unable to load the published calendar.";
    calendarList.innerHTML = "";
  }
}

function persistAppView(view) {
  try {
    if (view === "home") {
      window.sessionStorage.removeItem(APP_VIEW_KEY);
      window.history.replaceState(null, "", window.location.pathname + window.location.search);
      return;
    }

    window.sessionStorage.setItem(APP_VIEW_KEY, view);
    window.history.replaceState(null, "", `${window.location.pathname}${window.location.search}#${view}`);
  } catch (error) {
  }
}

function initialAppView() {
  const hashView = window.location.hash.replace(/^#/, "");
  if (["boards", "highlights", "calendar", "feedback"].includes(hashView)) {
    return hashView;
  }

  try {
    const storedView = window.sessionStorage.getItem(APP_VIEW_KEY) || "";
    if (["boards", "highlights", "calendar", "feedback"].includes(storedView)) {
      return storedView;
    }
  } catch (error) {
  }

  return "home";
}

function initializeBottomNav() {
  if (appViewLinks.length === 0) {
    return;
  }

  appViewLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      const targetView = link.dataset.appView;
      if (!targetView) {
        return;
      }

      event.preventDefault();
      showAppView(targetView);
    });
  });
}

function showAppView(view, options = {}) {
  const { scroll = true, persist = true } = options;
  currentAppView = view;

  const isHome = view === "home";
  homeHero?.classList.toggle("app-view-hidden", !isHome);
  appHomeButton?.classList.toggle("app-view-hidden", isHome);

  appSections.forEach((section) => {
    section.classList.toggle("app-view-hidden", isHome || section.id !== view);
  });

  if (persist) {
    persistAppView(view);
  }

  if (isHome) {
    setActiveBottomNav("");
    if (scroll) {
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
    return;
  }

  setActiveBottomNav(view);
  if (view === "calendar") {
    loadCalendarEvents();
  }
  if (scroll) {
    requestAnimationFrame(() => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }
}

function buildNoticeCard(notice, boardName = "") {
  const clone = noticeTemplate.content.cloneNode(true);
  const card = clone.querySelector(".notice-card");
  const newIndicator = clone.querySelector(".notice-new-indicator");
  const noticeText = clone.querySelector(".notice-text");
  const readMoreButton = clone.querySelector(".notice-read-more");
  clone.querySelector(".category-pill").textContent = notice.category;
  clone.querySelector(".audience-pill").textContent = boardName ? `${notice.audience} - ${boardName}` : notice.audience;
  clone.querySelector("h3").textContent = notice.title;
  clone.querySelector(".notice-date").textContent = formatDate(notice.date);
  clone.querySelector(".notice-cta").textContent = noticeStatusLabel(notice);
  setupNoticeReadMore(card, noticeText, readMoreButton, notice.text);

  if (isNewNotice(notice)) {
    newIndicator.hidden = false;
  }

  const tagContainer = clone.querySelector(".notice-tags");
  (notice.tags || []).forEach((tag) => {
    tagContainer.appendChild(createTagChip(tag));
  });

  const attachmentContainer = clone.querySelector(".notice-attachment");
  const attachmentButton = clone.querySelector(".attachment-link");
  if (notice.attachment && notice.attachment.path) {
    attachmentContainer.hidden = false;
    attachmentButton.textContent = `Attachment: ${notice.attachment.name || "View file"}`;
    attachmentButton.addEventListener("click", () => openAttachmentModal(notice.attachment));
  }

  clone.querySelectorAll(".reaction-btn").forEach((button) => {
    const reactionType = button.getAttribute("data-reaction-type") || "";
    updateReactionButton(button, reactionData(notice, reactionType));
    button.addEventListener("click", () => toggleReaction(notice, reactionType, button));
  });

  return clone;
}

function updateTagFilterBar() {
  const hasActiveTag = activeTag !== null;
  tagFilterBar.hidden = !hasActiveTag;
  if (hasActiveTag) {
    activeTagChip.textContent = activeTag;
  }
}

function renderEmptyBoard(message) {
  boardPanel.innerHTML = `
    <section class="board-overview">
      <div class="board-copy">
        <p class="eyebrow">No active notices</p>
        <h3>No notices available for this filter</h3>
        <p>${escapeHtml(message)}</p>
      </div>
    </section>
  `;
}

function renderLoadingBoard(message) {
  boardPanel.innerHTML = `
    <section class="board-overview">
      <div class="board-copy">
        <p class="eyebrow">Loading notices</p>
        <h3>Fetching the latest board feed</h3>
        <p>${escapeHtml(message)}</p>
      </div>
    </section>
  `;
}

async function fetchBoard(boardId) {
  const response = await fetch(`api/boards.php?v=${API_VERSION}&client_id=${encodeURIComponent(clientId)}&board_id=${encodeURIComponent(boardId)}`, {
    cache: "no-store"
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || `Request failed: ${response.status}`);
  }

  return payload.board || null;
}

async function ensureBoardNoticesLoaded(boardId) {
  const board = boards.find((item) => item.id === boardId);
  if (!board) {
    return null;
  }

  if (board.noticesLoaded) {
    return board;
  }

  const payload = await fetchBoard(boardId);
  if (!payload) {
    throw new Error("Board payload is missing.");
  }

  board.notices = Array.isArray(payload.notices) ? payload.notices : [];
  board.noticesLoaded = true;
  return board;
}

async function ensureAllBoardNoticesLoaded() {
  await Promise.all(
    boards.map(async (board) => {
      await ensureBoardNoticesLoaded(board.id);
    })
  );
}

async function renderBoard(boardId) {
  const activeBoard = boards.find((board) => board.id === boardId) || boards[0];

  if (!activeBoard) {
    renderEmptyBoard("Please check again after the bulletin feed is updated.");
    return;
  }

  activeBoardId = activeBoard.id;
  updateTagFilterBar();
  updateScopeButtons();
  renderTabs(activeBoard.id);

  if (!activeBoard.noticesLoaded) {
    renderLoadingBoard(`Loading notices for ${activeBoard.name}.`);

    try {
      await ensureBoardNoticesLoaded(activeBoard.id);
    } catch (error) {
      renderEmptyBoard(error.message);
      return;
    }
  }

  const visibleNotices = filterNotices([...activeBoard.notices]).sort(compareNotices);
  const topTags = topTagsFromNotices(visibleNotices);

  if (visibleNotices.length === 0) {
    renderEmptyBoard("There are no active notices for the selected board within the current filter scope.");
    return;
  }

  boardPanel.innerHTML = `
    <section class="board-overview">
      <div class="board-head">
        <div class="board-copy">
          <p class="eyebrow">${escapeHtml(activeBoard.audience)}</p>
          <h3>${escapeHtml(activeBoard.name)} Bulletin Board</h3>
          <p>${escapeHtml(activeBoard.tone)}</p>
        </div>
        <div class="pill-row">
          ${topTags.map((item) => `<button type="button" class="pill board-tag-pill" data-board-tag="${escapeHtml(item)}">${escapeHtml(item)}</button>`).join("")}
        </div>
      </div>
      <div class="notice-grid" id="noticeGrid"></div>
    </section>
  `;

  const noticeGrid = document.getElementById("noticeGrid");
  visibleNotices.forEach((notice) => {
    noticeGrid.appendChild(buildNoticeCard(notice));
  });

  boardPanel.querySelectorAll("[data-board-tag]").forEach((button) => {
    button.addEventListener("click", () => renderTaggedNotices(button.getAttribute("data-board-tag") || ""));
  });
}

async function renderTaggedNotices(tag) {
  activeTag = tag;
  updateTagFilterBar();
  updateScopeButtons();
  renderTabs("");

  renderLoadingBoard(`Loading notices tagged "${tag}" across all boards.`);

  try {
    await ensureAllBoardNoticesLoaded();
  } catch (error) {
    renderEmptyBoard(error.message);
    return;
  }

  const matchingNotices = filterNotices(
    boards.flatMap((board) =>
      board.notices
        .filter((notice) => (notice.tags || []).includes(tag))
        .map((notice) => ({
          ...notice,
          boardName: board.name
        }))
    )
  ).sort(compareNotices);

  if (matchingNotices.length === 0) {
    renderEmptyBoard(`There are no active notices tagged "${tag}" within the selected scope.`);
    return;
  }

  boardPanel.innerHTML = `
    <section class="board-overview">
      <div class="board-head">
        <div class="board-copy">
          <p class="eyebrow">Tag Results</p>
          <h3>Notices tagged with "${escapeHtml(tag)}"</h3>
          <p>Showing active notices across the NULIPA-SACE bulletin boards within the selected scope.</p>
        </div>
        <div class="pill-row">
          <span class="pill">${matchingNotices.length} matching notice${matchingNotices.length === 1 ? "" : "s"}</span>
        </div>
      </div>
      <div class="notice-grid" id="noticeGrid"></div>
    </section>
  `;

  const noticeGrid = document.getElementById("noticeGrid");
  matchingNotices.forEach((notice) => {
    noticeGrid.appendChild(buildNoticeCard(notice, notice.boardName));
  });
}

async function loadBoards() {
  try {
    const response = await fetch(`api/boards.php?v=${API_VERSION}&client_id=${encodeURIComponent(clientId)}`, {
      cache: "no-store"
    });

    if (!response.ok) {
      throw new Error(`Request failed: ${response.status}`);
    }

    const payload = await response.json();
    boards = Array.isArray(payload.boards) ? payload.boards.map((board) => ({
      ...board,
      notices: Array.isArray(board.notices) ? board.notices : [],
      noticesLoaded: Array.isArray(board.notices) && board.notices.length > 0
    })) : [];
    priorityNotices = Array.isArray(payload.priorityNotices) ? payload.priorityNotices : [];
    todayValue = payload.today || null;
    activeBoardId = payload.defaultBoardId || "sace";
    populateFeedbackBoards();
    if (feedbackBoard) {
      feedbackBoard.value = activeBoardId;
      if (!feedbackBoard.value && feedbackBoard.options.length > 1) {
        feedbackBoard.selectedIndex = 1;
      }
    }
    renderHighlights();
    await renderBoard(activeBoardId);
  } catch (error) {
    highlightStrip.innerHTML = `
      <article class="highlight-card">
        <span class="eyebrow">Connection issue</span>
        <h3>Unable to load the bulletin feed</h3>
        <p>${escapeHtml(error.message)}</p>
        <p class="notice-date">Refresh the page after checking the server.</p>
      </article>
    `;
    renderEmptyBoard("Please check again after the server issue is resolved.");
  }
}

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  deferredPrompt = event;
  installButton.hidden = false;
});

installButton.addEventListener("click", async () => {
  if (!deferredPrompt) {
    return;
  }

  deferredPrompt.prompt();
  await deferredPrompt.userChoice;
  deferredPrompt = null;
  installButton.hidden = true;
});

clearTagFilter?.addEventListener("click", () => {
  activeTag = null;
  renderBoard(activeBoardId || "sace");
});

appHomeButton?.addEventListener("click", (event) => {
  event.preventDefault();
  showAppView("home");
});

feedbackForm?.addEventListener("submit", async (event) => {
  event.preventDefault();

  try {
    await requestFeedbackOtp();
  } catch (error) {
    setFeedbackError(error.message);
  }
});

feedbackVerifyButton?.addEventListener("click", async () => {
  try {
    await verifyFeedbackOtp();
  } catch (error) {
    setFeedbackError(error.message);
  }
});

feedbackResetButton?.addEventListener("click", () => {
  resetFeedbackForm();
});

calendarMonthSelect?.addEventListener("change", () => {
  const nextMonth = calendarMonthSelect.value || "";
  if (nextMonth === activeCalendarMonth && calendarEventsLoaded) {
    return;
  }

  loadCalendarEvents(true, nextMonth);
});

attachmentModalClose?.addEventListener("click", closeAttachmentModal);
attachmentModal?.addEventListener("click", (event) => {
  if (event.target instanceof HTMLElement && event.target.hasAttribute("data-close-attachment-modal")) {
    closeAttachmentModal();
  }
});

window.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    closeAttachmentModal();
  }
});

scopeFilterBar?.querySelectorAll("[data-scope]").forEach((button) => {
  button.addEventListener("click", () => {
    activeScope = button.dataset.scope || "all";
    renderHighlights();
    if (activeTag !== null) {
      renderTaggedNotices(activeTag);
      return;
    }
    renderBoard(activeBoardId || "sace");
  });
});

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register(`service-worker.js?v=${API_VERSION}`, {
      updateViaCache: "none"
    }).then((registration) => {
      if (registration.waiting) {
        registration.waiting.postMessage({ type: "SKIP_WAITING" });
      }

      registration.addEventListener("updatefound", () => {
        const newWorker = registration.installing;
        if (!newWorker) {
          return;
        }

        newWorker.addEventListener("statechange", () => {
          if (newWorker.state === "installed" && navigator.serviceWorker.controller) {
            newWorker.postMessage({ type: "SKIP_WAITING" });
          }
        });
      });

      registration.update().catch(() => {});
    }).catch(() => {});
  });

  navigator.serviceWorker.addEventListener("controllerchange", () => {
    if (hasReloadedForUpdate) {
      return;
    }

    hasReloadedForUpdate = true;
    window.location.reload();
  });
}

initializeNoticeCardCollapseHandlers();
initializeBottomNav();
showAppView(initialAppView(), { scroll: false, persist: false });
loadBoards();
