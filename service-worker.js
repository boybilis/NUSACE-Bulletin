const CACHE_NAME = "nusace-bulletin-v17";
const UPDATE_STATE_CACHE = "nusace-bulletin-update-state-v1";
const UPDATE_STATE_URL = new URL("./__update-state__.json", self.location.href).href;
const UPDATE_CHECK_URL = new URL("./api/updates.php", self.location.href).href;
const APP_ASSETS = [
  "./",
  "./index.html",
  "./manifest.json",
  "./assets/img/NU lipa.png",
  "./assets/img/icon-192.png",
  "./assets/img/icon-512.png",
  "./assets/img/icon-192-maskable.png",
  "./assets/img/icon-512-maskable.png",
  "./assets/img/apple-touch-icon.png",
  "./assets/img/favicon-32.png"
];
let updateCheckPromise = null;

function defaultUpdateState() {
  return {
    initialized: false,
    known_notice_ids: [],
    known_calendar_ids: [],
    unread_notice_ids: [],
    unread_calendar_ids: [],
    checked_at: ""
  };
}

async function loadUpdateState() {
  const cache = await caches.open(UPDATE_STATE_CACHE);
  const response = await cache.match(UPDATE_STATE_URL);
  if (!response) {
    return defaultUpdateState();
  }

  try {
    return {
      ...defaultUpdateState(),
      ...(await response.json())
    };
  } catch (error) {
    return defaultUpdateState();
  }
}

async function saveUpdateState(state) {
  const cache = await caches.open(UPDATE_STATE_CACHE);
  await cache.put(
    UPDATE_STATE_URL,
    new Response(JSON.stringify(state), {
      headers: {
        "Content-Type": "application/json"
      }
    })
  );
}

async function setApplicationBadge(total) {
  try {
    if (self.registration && typeof self.registration.setAppBadge === "function") {
      if (total > 0) {
        await self.registration.setAppBadge(total);
      } else if (typeof self.registration.clearAppBadge === "function") {
        await self.registration.clearAppBadge();
      }
      return;
    }

    if (self.navigator && typeof self.navigator.setAppBadge === "function") {
      if (total > 0) {
        await self.navigator.setAppBadge(total);
      } else if (typeof self.navigator.clearAppBadge === "function") {
        await self.navigator.clearAppBadge();
      }
    }
  } catch (error) {
  }
}

async function publishUpdateCounts(state) {
  const counts = {
    notices: state.unread_notice_ids.length,
    calendar: state.unread_calendar_ids.length
  };
  counts.total = counts.notices + counts.calendar;

  await setApplicationBadge(counts.total);

  const clientList = await self.clients.matchAll({
    type: "window",
    includeUncontrolled: true
  });
  clientList.forEach((client) => {
    client.postMessage({
      type: "UPDATE_COUNTS",
      counts
    });
  });
}

function uniqueIds(values) {
  return [...new Set(values.filter(Boolean))].slice(-2000);
}

async function showUpdateNotification(newNotices, newCalendarEvents) {
  const noticeCount = newNotices.length;
  const calendarCount = newCalendarEvents.length;
  const parts = [];

  if (noticeCount > 0) {
    parts.push(`${noticeCount} new notice${noticeCount === 1 ? "" : "s"}`);
  }
  if (calendarCount > 0) {
    parts.push(`${calendarCount} new calendar entr${calendarCount === 1 ? "y" : "ies"}`);
  }

  const latestItem = newNotices[0] || newCalendarEvents[0];
  const destination = noticeCount > 0 ? "./#boards" : "./#calendar";

  try {
    await self.registration.showNotification("NU Lipa SACE Bulletin", {
      body: latestItem?.title
        ? `${parts.join(" and ")}: ${latestItem.title}`
        : parts.join(" and "),
      icon: "./assets/img/icon-192.png",
      badge: "./assets/img/favicon-32.png",
      tag: "nusace-bulletin-updates",
      renotify: true,
      data: {
        url: destination
      }
    });
  } catch (error) {
  }
}

async function performUpdateCheck(notify = true) {
  const response = await fetch(UPDATE_CHECK_URL, {
    cache: "no-store",
    headers: {
      "Accept": "application/json"
    }
  });
  if (!response.ok) {
    throw new Error(`Update check failed: ${response.status}`);
  }

  const payload = await response.json();
  const notices = Array.isArray(payload.notices) ? payload.notices : [];
  const calendarEvents = Array.isArray(payload.calendar_events) ? payload.calendar_events : [];
  const state = await loadUpdateState();
  const knownNoticeIds = new Set(state.known_notice_ids);
  const knownCalendarIds = new Set(state.known_calendar_ids);
  const newNotices = state.initialized
    ? notices.filter((notice) => notice.id && !knownNoticeIds.has(notice.id))
    : [];
  const newCalendarEvents = state.initialized
    ? calendarEvents.filter((calendarEvent) => calendarEvent.id && !knownCalendarIds.has(calendarEvent.id))
    : [];

  state.initialized = true;
  state.known_notice_ids = uniqueIds([
    ...state.known_notice_ids,
    ...notices.map((notice) => notice.id)
  ]);
  state.known_calendar_ids = uniqueIds([
    ...state.known_calendar_ids,
    ...calendarEvents.map((calendarEvent) => calendarEvent.id)
  ]);
  state.unread_notice_ids = uniqueIds([
    ...state.unread_notice_ids,
    ...newNotices.map((notice) => notice.id)
  ]);
  state.unread_calendar_ids = uniqueIds([
    ...state.unread_calendar_ids,
    ...newCalendarEvents.map((calendarEvent) => calendarEvent.id)
  ]);
  state.checked_at = payload.generated_at || new Date().toISOString();

  await saveUpdateState(state);
  await publishUpdateCounts(state);

  if (notify && (newNotices.length > 0 || newCalendarEvents.length > 0)) {
    await showUpdateNotification(newNotices, newCalendarEvents);
  }

  return state;
}

function checkForUpdates(notify = true) {
  if (!updateCheckPromise) {
    updateCheckPromise = performUpdateCheck(notify)
      .finally(() => {
        updateCheckPromise = null;
      });
  }

  return updateCheckPromise;
}

async function markUpdatesRead(category) {
  const state = await loadUpdateState();
  if (category === "notices") {
    state.unread_notice_ids = [];
  }
  if (category === "calendar") {
    state.unread_calendar_ids = [];
  }

  await saveUpdateState(state);
  await publishUpdateCounts(state);
}

async function networkFirst(request) {
  const cache = await caches.open(CACHE_NAME);

  try {
    const networkRequest = new Request(request, { cache: "no-store" });
    const networkResponse = await fetch(networkRequest);

    if (networkResponse && networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }

    return networkResponse;
  } catch (error) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    throw error;
  }
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME && key !== UPDATE_STATE_CACHE)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
      .then(() => checkForUpdates(false).catch(() => {}))
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
    return;
  }

  if (event.data && event.data.type === "CHECK_UPDATES") {
    event.waitUntil(checkForUpdates(event.data.notify !== false).catch(() => {}));
    return;
  }

  if (event.data && event.data.type === "GET_UPDATE_COUNTS") {
    event.waitUntil(
      loadUpdateState()
        .then((state) => publishUpdateCounts(state))
        .catch(() => {})
    );
    return;
  }

  if (event.data && event.data.type === "MARK_UPDATES_READ") {
    event.waitUntil(markUpdatesRead(String(event.data.category || "")).catch(() => {}));
  }
});

self.addEventListener("periodicsync", (event) => {
  if (event.tag === "nusace-bulletin-updates") {
    event.waitUntil(checkForUpdates(true).catch(() => {}));
  }
});

self.addEventListener("sync", (event) => {
  if (event.tag === "nusace-bulletin-updates") {
    event.waitUntil(checkForUpdates(true).catch(() => {}));
  }
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || "./#boards", self.location.href).href;

  event.waitUntil(
    self.clients.matchAll({
      type: "window",
      includeUncontrolled: true
    }).then(async (clientList) => {
      for (const client of clientList) {
        if ("focus" in client) {
          if ("navigate" in client) {
            await client.navigate(targetUrl);
          }
          return client.focus();
        }
      }

      return self.clients.openWindow(targetUrl);
    })
  );
});

self.addEventListener("fetch", (event) => {
  const requestUrl = new URL(event.request.url);
  const isSameOrigin = requestUrl.origin === self.location.origin;
  const isNavigation = event.request.mode === "navigate";
  const isCodeAsset =
    isSameOrigin &&
    (requestUrl.pathname.endsWith(".css") ||
      requestUrl.pathname.endsWith(".js") ||
      requestUrl.pathname.endsWith(".html"));

  if (isNavigation || isCodeAsset) {
    event.respondWith(networkFirst(event.request));
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cachedResponse) => cachedResponse || fetch(event.request))
  );
});
