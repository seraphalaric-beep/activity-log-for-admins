const config = window.alfaSettings;
export { config };

const keys = ["q", "from", "to", "event_id", "actor", "actor_id", "actor_kind", "actor_role", "source", "action", "category", "item", "item_kind", "item_id", "severity", "site_id", ...(config.networkMode ? ["network_site_id"] : [])];

export function readView() {
  const params = new URLSearchParams(location.search);
  const filters = Object.fromEntries(keys.map(key => [key, params.get(key) ?? ""]));
  if (filters.actor_id) {
    filters.actor = "";
    filters.actor_kind = "user";
  } else if (filters.actor_kind && filters.actor_kind !== "user") {
    filters.actor = "";
  }
  return {
    settings: params.get("view") === "settings",
    reports: params.get("view") === "reports",
    sites: params.get("view") === "sites",
    sessions: params.get("view") === "sessions",
    filters,
    eventId: /^\d{1,20}$/.test(params.get("event") ?? "") ? params.get("event") : "",
    reportGroup: params.get("report_group") ?? "category",
    reportInterval: params.get("report_interval") ?? "daily",
  };
}

export function viewUrl(filters, destination = "activity", report = null) {
  const url = new URL(location.href);
  url.searchParams.delete("event");
  const normalized = { ...filters };
  if (normalized.actor_id?.trim()) {
    normalized.actor = "";
    normalized.actor_kind = "user";
  } else if (normalized.actor_kind && normalized.actor_kind !== "user") {
    normalized.actor = "";
  }
  for (const key of keys) {
    url.searchParams.delete(key);
    if (normalized[key]?.trim()) url.searchParams.set(key, normalized[key].trim());
  }
  const view = true === destination ? "settings" : false === destination ? "activity" : destination;
  if (view === "settings" || view === "reports" || view === "sites" || view === "sessions") url.searchParams.set("view", view);
  else url.searchParams.delete("view");
  if ("reports" === view) {
    if (report?.group) url.searchParams.set("report_group", report.group);
    if (report?.interval) url.searchParams.set("report_interval", report.interval);
  } else if ("settings" !== view && "sites" !== view) {
    url.searchParams.delete("report_group");
    url.searchParams.delete("report_interval");
  }
  return url;
}

export function apiUrl(path, query = {}) {
  const url = new URL(config.base, location.href);
  if (url.searchParams.has("rest_route")) {
    url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
  } else {
    url.pathname += path;
  }
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value) url.searchParams.set(key, value);
  }
  url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
  return url;
}

export async function request(path, { query, data, signal } = {}) {
  const response = await fetch(apiUrl(path, query), {
    method: data ? "POST" : "GET",
    credentials: "same-origin",
    cache: "no-store",
    headers: {
      "X-WP-Nonce": config.nonce,
      ...(data && { "Content-Type": "application/json" }),
    },
    body: data && JSON.stringify(data),
    signal,
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload) {
    throw new Error(payload?.message ?? "WordPress could not complete the request. Please try again.");
  }
  return payload;
}
