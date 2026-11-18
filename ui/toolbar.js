import "./toolbar.css";

const config = window.cvsjToolbar;
const feedItem = document.querySelector("#wp-admin-bar-cvsj-live-activity-feed > .ab-item");

if (config && feedItem) {
  const panel = document.createElement("div");
  panel.className = "ab-item cvsj-toolbar-feed-content";
  panel.setAttribute("aria-label", "Recent activity");
  const status = document.createElement("p");
  status.className = "cvsj-toolbar-feed-status";
  status.setAttribute("role", "status");
  status.textContent = "Loading recent activity";
  const list = document.createElement("ul");
  list.className = "cvsj-toolbar-event-list";
  feedItem.replaceWith(panel);
  panel.append(status, list);

  let busy = false;
  let controller = null;

  function actorLabel(event) {
    return event.actor_name || "Unknown";
  }

  function render(events) {
    list.replaceChildren();
    if (!events.length) {
      status.textContent = "No activity has been recorded yet";
      return;
    }
    status.textContent = "Recent changes";
    for (const event of events.slice(0, 5)) {
      const item = document.createElement("li");
      const link = document.createElement("a");
      link.href = event.url;
      link.className = "cvsj-toolbar-event";
      const summary = document.createElement("span");
      summary.className = "cvsj-toolbar-event-summary";
      summary.textContent = event.summary;
      const meta = document.createElement("span");
      meta.className = "cvsj-toolbar-event-meta";
      meta.textContent = [event.item_name, actorLabel(event), event.time].filter(Boolean).join(" · ");
      link.append(summary, meta);
      item.append(link);
      list.append(item);
    }
  }

  async function refresh() {
    if (busy || document.visibilityState !== "visible") return;
    busy = true;
    controller = new AbortController();
    try {
      const response = await fetch(config.endpoint, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { "X-WP-Nonce": config.nonce },
        signal: controller.signal,
      });
      const payload = await response.json();
      if (!response.ok || !Array.isArray(payload?.events)) throw new Error("Activity unavailable");
      render(payload.events);
    } catch (error) {
      if (error.name !== "AbortError") status.textContent = "Activity is temporarily unavailable";
    } finally {
      busy = false;
      controller = null;
    }
  }

  refresh();
  window.setInterval(refresh, 30000);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") controller?.abort();
    else refresh();
  });
}
