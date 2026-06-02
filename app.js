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

let deferredPrompt;
let boards = [];
let activeTag = null;
let activeScope = "all";
let todayValue = null;
let priorityNotices = [];
let hasReloadedForUpdate = false;

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

function filterNotices(notices) {
  return notices.filter((notice) => matchesScope(notice));
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
          <p>${escapeHtml(item.text)}</p>
          ${item.attachment && item.attachment.path ? `<div class="notice-attachment"><button type="button" class="attachment-link" data-attachment='${escapeHtml(JSON.stringify(item.attachment))}'>Attachment: ${escapeHtml(item.attachment.name || "View file")}</button></div>` : ""}
          <p class="notice-date">${item.pinned ? "Pinned" : `Visible ${escapeHtml(formatDate(item.visible_from || item.date))}`}</p>
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
  clone.querySelector(".notice-cta").textContent = notice.pinned ? "Pinned" : "Current";

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

function renderBoard(boardId) {
  const activeBoard = boards.find((board) => board.id === boardId) || boards[0];

  if (!activeBoard) {
    renderEmptyBoard("Please check again after the bulletin feed is updated.");
    return;
  }

  updateTagFilterBar();
  updateScopeButtons();
  renderTabs(activeBoard.id);

  const visibleNotices = filterNotices([...activeBoard.notices]).sort(compareNotices);

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
          ${activeBoard.highlights.map((item) => `<span class="pill">${escapeHtml(item)}</span>`).join("")}
        </div>
      </div>
      <div class="notice-grid" id="noticeGrid"></div>
    </section>
  `;

  const noticeGrid = document.getElementById("noticeGrid");
  visibleNotices.forEach((notice) => {
    noticeGrid.appendChild(buildNoticeCard(notice));
  });
}

function renderTaggedNotices(tag) {
  activeTag = tag;
  updateTagFilterBar();
  updateScopeButtons();
  renderTabs("");

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
    const response = await fetch("api/boards.php?v=20260602-admin14", {
      cache: "no-store"
    });

    if (!response.ok) {
      throw new Error(`Request failed: ${response.status}`);
    }

    const payload = await response.json();
    boards = Array.isArray(payload.boards) ? payload.boards : [];
    priorityNotices = Array.isArray(payload.priorityNotices) ? payload.priorityNotices : [];
    todayValue = payload.today || null;
    renderHighlights();
    renderBoard(payload.defaultBoardId || "sace");
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
  renderBoard("sace");
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
    renderBoard("sace");
  });
});

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("service-worker.js?v=20260602-admin14", {
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
