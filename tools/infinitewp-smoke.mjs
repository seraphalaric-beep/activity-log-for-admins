import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const activityPlugin = "activity-log-for-admins/activity-log-for-admins.php";
const infinitewpPlugin = "iwp-client/init.php";
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
    url.searchParams.set("_alfa_request", Date.now() + "-" + Math.random().toString(36).slice(2));
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

async function pluginActive(plugin) {
  await page.goto(base + "/wp-admin/plugins.php");
  const row = page.locator('tr[data-plugin="' + plugin + '"]');
  if (0 === await row.count()) {
    const body = await page.locator("body").innerText().catch(() => "");
    const rows = await page.locator("#the-list tr").allInnerTexts();
    throw new Error("Plugin row was not found for " + plugin + ". Current URL: " + page.url() + ". Rows: " + rows.slice(0, 12).join(" || ") + ". Page text: " + body.slice(-600));
  }
  await row.waitFor();
  return 0 < await row.locator(".deactivate a").count();
}

async function setPluginActive(plugin, active) {
  const current = await pluginActive(plugin);
  if (current !== active) {
    const row = page.locator('tr[data-plugin="' + plugin + '"]');
    await row.locator(current ? ".deactivate a" : ".activate a").click();
    await page.waitForLoadState("domcontentloaded");
    assert.equal(await pluginActive(plugin), active, "Could not set " + plugin + " to the requested state");
  }
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");

  const installedState = await pluginActive(infinitewpPlugin);
  pluginStates.set(infinitewpPlugin, installedState);
  const activityState = await pluginActive(activityPlugin);
  pluginStates.set(activityPlugin, activityState);
  await setPluginActive(activityPlugin, true);
  await setPluginActive(infinitewpPlugin, true);

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  const original = await api("settings");
  assert.equal(original.status, 200);
  originalSettings = original.data.settings;

  assert.equal(originalSettings.infinitewp, false, "InfiniteWP recording must default off");
  const defaults = await fixture("infinitewp_hook_state");
  assert.equal(defaults.loaded, true, "The official InfiniteWP Client must be active");
  assert.equal(defaults.backupHook, false, "No InfiniteWP listener should run before opt in");
  const disabled = await fixture("infinitewp_disabled");
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, "InfiniteWP activity must not be recorded while disabled");

  assert.equal((await api("settings", { data: { ...originalSettings, infinitewp: true } })).status, 200);
  await page.reload();
  const enabled = await fixture("infinitewp_hook_state");
  assert.equal(enabled.backupHook, true, "InfiniteWP activity listener should register after opt in");

  const result = await fixture("infinitewp");
  assert.equal(result.supported, true);
  assert.equal(result.backupCount, 1, "One completed backup should create one Activity Log event");
  assert.equal(result.eventCount, 1, "Non backup InfiniteWP details must not be copied as duplicate events");
  assert.equal(result.backupAction, "infinitewp_backup_completed");
  assert.equal(result.backupSource, "infinitewp");
  assert.equal(result.backupActor, "pilotadmin");
  assert.equal(result.backupScope, "Files and database");
  assert.equal(result.validRequestSource, "infinitewp", "A matching InfiniteWP request context should be identified as the source");
  assert.notEqual(result.mismatchedActorSource, "infinitewp", "A request that names a different account must not be attributed to InfiniteWP");
  assert.equal(result.privacySafe, true, "Backup names, paths, storage details, and secrets must not be copied into event details");

  const exported = await api("settings/export");
  assert.equal(exported.status, 200);
  assert.equal(exported.data.settings.infinitewp, true, "Settings export should include the integration choice");
  console.log("InfiniteWP opt in, simulated request source classification, backup event allowlist, actor matching, and settings export passed");
} finally {
  if (originalSettings) {
    try {
      await api("settings", { data: originalSettings });
      await page.reload();
    } catch {}
  }
  for (const [plugin, active] of [...pluginStates.entries()].reverse()) {
    try {
      await setPluginActive(plugin, active);
    } catch {}
  }
  await browser.close();
}
