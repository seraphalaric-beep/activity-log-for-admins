const config = window.alfaSettings;
export { config };

const keys = ["q", "from", "to", "actor", "actor_id", "actor_kind", "action", "item", "severity"];

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
    filters,
  };
}

export function viewUrl(filters, settings = false) {
  const url = new URL(location.href);
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
  if (settings) url.searchParams.set("view", "settings");
  else url.searchParams.delete("view");
  return url;
}

export function apiUrl(path, query = {}) {
  const url = new URL(config.base, location.href);
  if (url.searchParams.has("rest_route")) {
    url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
  } else {
    url.pathname += path;
  }
  for (const [key, value] of Object.entries(query)) {
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
