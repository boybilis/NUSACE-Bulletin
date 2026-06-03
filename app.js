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
const CLIENT_ID_KEY = "nusaceBulletinClientId";
const API_VERSION = "20260602-admin20";

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
          <p>${escapeHtml(item.text)}</p>
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

function buildNoticeCard(notice, boardName = "") {
  const clone = noticeTemplate.content.cloneNode(true);
  const newIndicator = clone.querySelector(".notice-new-indicator");
  clone.querySelector(".category-pill").textContent = notice.category;
  clone.querySelector(".audience-pill").textContent = boardName ? `${notice.audience} - ${boardName}` : notice.audience;
  clone.querySelector("h3").textContent = notice.title;
  clone.querySelector(".notice-date").textContent = formatDate(notice.date);
  clone.querySelector(".notice-text").textContent = notice.text;
  clone.querySelector(".notice-cta").textContent = noticeStatusLabel(notice);

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

loadBoards();
