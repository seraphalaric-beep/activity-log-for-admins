import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base = "http://127.0.0.1:8092"] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : "");
if (!password || new URL(base).hostname !== "127.0.0.1") throw new Error("Use the disposable loopback site and its pilot password.");

const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
const page = await context.newPage();
page.setDefaultTimeout(15000);
let fixture = null;
let originalSettings = null;

async function signIn() {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
}

async function api(path, data, query = {}) {
  return page.evaluate(async ({ path, data, query }) => {
    const config = window.alfaSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(query)) if (value !== "") url.searchParams.set(key, value);
    url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    return { status: response.status, data: await response.json() };
  }, { path, data, query });
}

async function fixtureCall(operation, fields = {}) {
  const response = await context.request.post(base + "/alfa-fixture.php", { form: { operation, ...fields } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

try {
  await signIn();
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  const settingsResponse = await api("settings");
  assert.equal(settingsResponse.status, 200);
  originalSettings = settingsResponse.data.settings;
  fixture = await fixtureCall("bulk_seed");
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();

  async function applyToEvent(id, action) {
    await page.getByLabel(`Select event ${id}`).check();
    if ("exclude_accounts" === action) {
      await page.locator(".alfa-bulk-actions").waitFor();
      await page.screenshot({ path: ".tmp/network-parity-wp/bulk-actions-desktop.png", animations: "disabled" });
      await page.setViewportSize({ width: 390, height: 844 });
      const bulkBounds = await page.locator(".alfa-bulk-actions").boundingBox();
      assert.ok(bulkBounds && bulkBounds.x >= 0 && bulkBounds.x + bulkBounds.width <= 390, `The bulk controls fit a narrow admin viewport, ${JSON.stringify(bulkBounds)}`);
      await page.screenshot({ path: ".tmp/network-parity-wp/bulk-actions-mobile.png", animations: "disabled" });
      await page.setViewportSize({ width: 1440, height: 950 });
    }
    await page.getByLabel("Apply to selection").selectOption(action);
    page.once("dialog", dialog => dialog.accept());
    const saveResponse = page.waitForResponse(response => response.url().includes("/settings") && response.request().method() === "POST");
    await page.getByRole("button", { name: "Apply", exact: true }).click();
    assert.equal((await saveResponse).status(), 200, "The bulk action settings request succeeds.");
    await page.getByText(/Future recording rules updated/).waitFor();
  }

  await applyToEvent(fixture.eventIds[0], "exclude_accounts");
  await applyToEvent(fixture.eventIds[1], "exclude_items");
  await applyToEvent(fixture.eventIds[2], "disable_actions");

  const ruleState = await fixtureCall("bulk_exclusion_state", { user_id: fixture.userId, item_key: "8802" });
  assert.deepEqual(ruleState, {
    accountExcluded: true,
    itemExcluded: true,
    actionDisabled: true,
    accountSuppressed: true,
    itemSuppressed: true,
    actionEnabled: false,
  });

  const ruleEvents = await api("events", undefined, { action: "recording_rules_changed" });
  assert.equal(ruleEvents.status, 200);
  assert.ok(ruleEvents.data.events.length >= 3, "Each bulk rule change creates a distinct audit event.");
  const eventPayload = JSON.stringify(ruleEvents.data.events);
  assert.ok(!eventPayload.includes(String(fixture.userId)), "The audit details do not expose excluded account identifiers.");
  assert.ok(!eventPayload.includes("8802"), "The audit details do not expose excluded item identifiers.");
  assert.ok(!eventPayload.includes(fixture.marker), "The audit details do not expose fixture item names.");
  for (const event of ruleEvents.data.events) {
    assert.equal(event.item_key, "recording-rules");
    const detailResponse = await api(`events/${event.id}`);
    assert.equal(detailResponse.status, 200);
    assert.ok(Object.values(detailResponse.data.details.changes).every(([before, after]) => /^\d+ selected$/.test(before) && /^\d+ selected$/.test(after)));
  }

  const exportedSettings = await api("settings/export");
  assert.equal(exportedSettings.status, 200);
  assert.ok(!Object.hasOwn(exportedSettings.data.settings, "excluded_items"), "Exact item exclusions stay local to this site during settings export.");

  const nav = page.getByRole("navigation", { name: "Activity Log", exact: true });
  await nav.getByRole("link", { name: "Settings", exact: true }).click();
  await page.getByText("Recording exclusions", { exact: true }).waitFor();
  const excludedItems = page.locator(".alfa-selected-exclusions").filter({ hasText: "Excluded items" });
  const excludedRow = excludedItems.locator("li").filter({ hasText: "identifier 8802" });
  await excludedRow.getByRole("button", { name: "Remove", exact: true }).click();
  await page.getByRole("button", { name: "Save settings", exact: true }).click();
  await page.getByText("Settings saved.", { exact: true }).waitFor();
  const itemRemoval = await api("settings");
  assert.equal(itemRemoval.status, 200);
  assert.ok(!itemRemoval.data.settings.excluded_items.includes("page:8802"), "The item exclusion can be reviewed and removed from Settings.");

  const combinedSettings = itemRemoval.data.settings;
  const nextRetention = combinedSettings.retention === 1 ? 2 : combinedSettings.retention - 1;
  const combinedSave = await api("settings", {
    ...combinedSettings,
    retention: nextRetention,
    excluded_users: [...new Set([...(combinedSettings.excluded_users ?? []), fixture.managerId])],
  });
  assert.equal(combinedSave.status, 200, "A manager can exclude their account while changing another setting.");
  const generalEvents = await api("events", undefined, { action: "plugin_settings_changed" });
  const generalEvent = generalEvents.data.events.find(event => event.item_key === "activity-log-settings");
  assert.ok(generalEvent, "The general settings change remains audited when the actor excludes themselves.");
  const generalDetail = await api(`events/${generalEvent.id}`);
  assert.ok(generalDetail.data.details.changes["Retention period"], "The combined settings audit retains the unrelated setting change.");
  assert.ok(!generalDetail.data.details.changes["Excluded accounts"], "The general audit does not duplicate recording rule details.");

  console.log("Bulk account, exact item, and event type controls passed. Audit details contain only count changes. Exact exclusions stay local during settings export and can be removed in Settings.");
} finally {
  if (originalSettings) {
    await api("settings", originalSettings).catch(() => {});
  }
  if (fixture) {
    await fixtureCall("bulk_cleanup", { baseline: fixture.baseline, user_id: fixture.userId }).catch(() => {});
  }
  await browser.close();
}
