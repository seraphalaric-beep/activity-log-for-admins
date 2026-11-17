import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const activityPlugin = "activity-log-for-admins/activity-log-for-admins.php";
const mainwpPlugin = "mainwp/mainwp.php";
const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
page.setDefaultTimeout(12000);
let originalSettings = null;
const pluginStates = new Map();

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const config = window.alfaSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(options.query ?? {})) url.searchParams.set(key, value);
    url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: options.data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: options.data && JSON.stringify(options.data),
    });
    const contentType = response.headers.get("content-type") ?? "";
    return { status: response.status, data: contentType.includes("application/json") ? await response.json() : await response.text() };
  }, { path, options });
}

async function fixture(operation) {
  const response = await page.request.post(base + "/alfa-fixture.php", { form: { operation } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

async function setPluginActive(plugin, active) {
  await page.goto(base + "/wp-admin/plugins.php");
  const row = page.locator(`tr[data-plugin="${plugin}"]`);
  await row.waitFor();
  const currentlyActive = 0 < await row.locator(".deactivate a").count();
  if (currentlyActive !== active) {
    await row.locator(currentlyActive ? ".deactivate a" : ".activate a").click();
    await page.waitForLoadState("domcontentloaded");
  }
  const activeAfterChange = 0 < await row.locator(".deactivate a").count();
  if (activeAfterChange !== active) {
    const message = (await page.locator("#wpbody-content").innerText()).slice(0, 2200);
    await page.screenshot({ path: ".tmp/mainwp-activation-failed.png", fullPage: true });
    throw new Error(`MainWP state did not update. ${message}`);
  }
}

async function getPluginActive(plugin) {
  await page.goto(base + "/wp-admin/plugins.php");
  const row = page.locator(`tr[data-plugin="${plugin}"]`);
  await row.waitFor();
  return 0 < await row.locator(".deactivate a").count();
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  for (const plugin of [activityPlugin, mainwpPlugin]) pluginStates.set(plugin, await getPluginActive(plugin));
  await setPluginActive(activityPlugin, true);
  await setPluginActive(mainwpPlugin, true);
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  const original = await api("settings");
  assert.equal(original.status, 200);
  originalSettings = original.data.settings;
  await fixture("reset");

  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  assert.equal(defaults.data.settings.mainwp, false, "MainWP recording must default off");
  const disabledState = await fixture("mainwp_hook_state");
  assert.equal(disabledState.loaded, true, "MainWP Dashboard must be active for this check");
  assert.equal(disabledState.siteAddHook, false, "Child site hooks must stay absent while recording is off");
  assert.equal(disabledState.siteSyncHook, false, "Synchronization hook must stay absent while recording is off");
  assert.equal(disabledState.syncRequestFilter, false, "Child log transfer must stay absent while recording is off");
  assert.equal(disabledState.clientHook, false, "Client hooks must stay absent while recording is off");
  const disabled = await fixture("mainwp");
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, "No MainWP event should be captured while disabled");

  const enabled = { ...defaults.data.settings, mainwp: true };
  assert.equal((await api("settings", { data: enabled })).status, 200);
  await page.reload();
  const enabledState = await fixture("mainwp_hook_state");
  assert.equal(enabledState.siteAddHook, true, "Child site hook should register after opt in");
  assert.equal(enabledState.siteDeleteHook, true, "Child site removal hook should register after opt in");
  assert.equal(enabledState.siteSyncHook, true, "Synchronization hook should register after opt in");
  assert.equal(enabledState.syncRequestFilter, true, "Dashboard sync should request child log batches after opt in");
  assert.equal(enabledState.clientHook, true, "Client hook should register after opt in");
  assert.equal(enabledState.clientDeleteHook, true, "Client removal hook should register after opt in");

  const probe = await fixture("mainwp");
  assert.equal(probe.supported, true);
  assert.equal(probe.eventCount, 12, "All child site, client, status, and synchronization events should be captured");
  for (const action of [
    "mainwp_site_added", "mainwp_site_updated", "mainwp_site_deleted", "mainwp_site_suspended", "mainwp_site_resumed",
    "mainwp_site_sync_succeeded", "mainwp_site_sync_failed", "mainwp_client_created", "mainwp_client_updated",
    "mainwp_client_deleted", "mainwp_client_suspended", "mainwp_client_resumed",
  ]) assert.ok(probe.actions.includes(action), `Missing ${action} event`);
  assert.equal(probe.privacyRedacted, true, "Child addresses, client contact values, credentials, and sync payloads must never be stored");

  const transfer = await fixture("mainwp_transfer");
  assert.equal(transfer.eventCount, 1, "A child event should be imported once even when MainWP retries the sync");
  assert.equal(transfer.cursorBefore, 0, "A new child site should start at event ID zero");
  assert.equal(transfer.cursorAfter, 450, "The Dashboard should advance the child cursor after a successful import");
  assert.ok(transfer.exportCount > 0, "The Dashboard should request a bounded child event batch");
  assert.equal(transfer.exportHasImported, false, "Imported child events must not be sent back to another MainWP Dashboard");
  assert.equal(transfer.privacySafe, true, "Imported details should be text sanitized before storage");

  const events = await api("events", { query: { category: "mainwp" } });
  assert.equal(events.status, 200);
  const failedSync = events.data.events.find(event => event.action_key === "mainwp_site_sync_failed");
  assert.ok(failedSync, "Failed synchronization should appear in REST results");
  const detail = await api(`events/${failedSync.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.action_key, "mainwp_site_sync_failed");
  assert.doesNotMatch(JSON.stringify(detail.data), /PRIVATE_MAINWP_|MAINWP_SECRET_FIXTURE|example\.invalid/);

  const childEvents = await api("events", { query: { site_id: String(transfer.siteId) } });
  assert.equal(childEvents.status, 200);
  assert.equal(childEvents.data.events.length, 1);
  assert.equal(childEvents.data.events[0].origin_site_id, String(transfer.siteId));
  assert.equal(childEvents.data.events[0].actor_id, "0", "Child account IDs must not be treated as Dashboard account IDs");
  const childDetails = await api(`events/${childEvents.data.events[0].id}`);
  assert.equal(childDetails.data.details.changes.Title[1], "New title");
  assert.doesNotMatch(JSON.stringify(childDetails.data), /<img|onerror=/);

  const csv = await api("export", { query: { category: "mainwp" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("mainwp_site_sync_failed"));
  assert.ok(csv.data.includes("mainwp_client_created"));
  assert.doesNotMatch(csv.data, /PRIVATE_MAINWP_|MAINWP_SECRET_FIXTURE|example\.invalid/);
  const childCsv = await api("export", { query: { site_id: String(transfer.siteId) } });
  assert.equal(childCsv.status, 200);
  assert.ok(childCsv.data.includes('"MainWP site ID"'));
  assert.ok(childCsv.data.includes(",907,450,"));

  const settingsExport = await api("settings/export");
  assert.equal(settingsExport.status, 200);
  assert.equal(settingsExport.data.settings.mainwp, true, "Settings export should include the integration choice");
  const olderExport = structuredClone(settingsExport.data);
  delete olderExport.settings.mainwp;
  const olderImport = await api("settings/import", { data: olderExport });
  assert.equal(olderImport.status, 200, "Settings exported before MainWP support should remain importable");
  assert.equal(olderImport.data.settings.mainwp, false, "Older settings files should default the new integration to off");
  assert.equal((await api("settings", { data: enabled })).status, 200, "The integration should be re enabled after the compatibility check");

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=mainwp");
  const activityTable = page.getByRole("table", { name: "Activity events" });
  await activityTable.waitFor();
  await page.getByRole("button", { name: /Child site synchronization failed/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("Synchronization", { exact: true }).waitFor();
  await dialog.getByText("Failed", { exact: true }).waitFor();
  await page.screenshot({ path: ".tmp/mainwp-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Activity Log" }).getByRole("link", { name: "Settings", exact: true }).click();
  const integration = page.getByRole("checkbox", { name: /MainWP/ });
  assert.equal(await integration.isChecked(), true);
  await page.getByText(/Enable this on both the Dashboard and each child site/i).waitFor();
  await page.screenshot({ path: ".tmp/mainwp-settings.png", fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=mainwp");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/mainwp-mobile.png", fullPage: true });

  console.log("MainWP opt in, conditional hooks, child and client lifecycle, synchronization outcomes, privacy redaction, REST, CSV, settings compatibility, details, and narrow layout checks passed");
} finally {
  if (originalSettings && pluginStates.get(activityPlugin)) {
    await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
    await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
    await api("settings", { data: originalSettings });
  }
  for (const [plugin, wasActive] of [...pluginStates].reverse()) await setPluginActive(plugin, wasActive);
  await browser.close();
}
