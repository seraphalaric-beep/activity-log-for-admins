import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const plugin = "uk-cookie-consent/uk-cookie-consent.php";
const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
page.setDefaultTimeout(12000);
let originalSettings = null;
let pluginWasActive = null;

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(options.query ?? {})) url.searchParams.set(key, value);
    url.searchParams.set("_cvsj_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
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
  const response = await page.request.post(base + "/cvsj-fixture.php", { form: { operation } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

async function setPluginActive(active) {
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
    await page.screenshot({ path: ".tmp/termly-activation-failed.png", fullPage: true });
    throw new Error(`Termly state did not update. ${message}`);
  }
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
  const original = await api("settings");
  assert.equal(original.status, 200);
  originalSettings = original.data.settings;
  await fixture("reset");

  await page.goto(base + "/wp-admin/plugins.php");
  const row = page.locator(`tr[data-plugin="${plugin}"]`);
  await row.waitFor();
  pluginWasActive = 0 < await row.locator(".deactivate a").count();
  await setPluginActive(true);

  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  assert.equal(defaults.data.settings.termly, false, "Termly recording must default off");
  const disabledState = await fixture("termly_hook_state");
  assert.equal(disabledState.loaded, true, "Termly must be active for this check");
  assert.equal(disabledState.bannerHook, false, "Consent banner hook must stay absent while recording is off");
  assert.equal(disabledState.autoblockerHook, false, "Automatic blocker hook must stay absent while recording is off");
  assert.equal(disabledState.scanHook, false, "Scan settings hook must stay absent while recording is off");
  const disabled = await fixture("termly");
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, "No Termly event should be recorded while disabled");

  const enabled = { ...defaults.data.settings, termly: true };
  assert.equal((await api("settings", { data: enabled })).status, 200);
  await page.reload();
  const enabledState = await fixture("termly_hook_state");
  assert.equal(enabledState.bannerHook, true, "Consent banner hook should register after opt in");
  assert.equal(enabledState.autoblockerHook, true, "Automatic blocker hook should register after opt in");
  assert.equal(enabledState.scanHook, true, "Scan settings hook should register after opt in");
  assert.equal(enabledState.keyDeleteHook, true, "Connection deletion hook should register after opt in");

  const probe = await fixture("termly");
  assert.equal(probe.supported, true);
  assert.equal(probe.pluginVersion, "3.3.1");
  assert.equal(probe.eventCount, 7, "Banner, blocker, scan, allow list, connection, key rotation, and disconnect changes should be captured");
  for (const action of ["termly_connected", "termly_disconnected", "termly_banner_changed", "termly_autoblocker_changed", "termly_scan_schedule_changed", "termly_robot_allowlist_changed"]) {
    assert.ok(probe.actions.includes(action), `Missing ${action} event`);
  }
  assert.equal(probe.unchangedScanIgnored, true, "An unchanged scan setting save should not create an event");
  assert.equal(probe.privacyRedacted, true, "Termly API key values must never be stored in activity events");

  const events = await api("events", { query: { category: "termly" } });
  assert.equal(events.status, 200);
  const bannerEvent = events.data.events.find(event => event.action_key === "termly_banner_changed");
  assert.ok(bannerEvent, "Consent banner change should appear in REST results");
  const detail = await api(`events/${bannerEvent.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.action_key, "termly_banner_changed");
  assert.doesNotMatch(JSON.stringify(detail.data), /TERMLY_SECRET_FIXTURE|ROTATED_FIXTURE/);

  const csv = await api("export", { query: { category: "termly" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("termly_banner_changed"));
  assert.ok(csv.data.includes("termly_connected"));
  assert.doesNotMatch(csv.data, /TERMLY_SECRET_FIXTURE|ROTATED_FIXTURE/);

  const settingsExport = await api("settings/export");
  assert.equal(settingsExport.status, 200);
  assert.equal(settingsExport.data.settings.termly, true, "Settings export should include the integration choice");
  const olderExport = structuredClone(settingsExport.data);
  delete olderExport.settings.termly;
  const olderImport = await api("settings/import", { data: olderExport });
  assert.equal(olderImport.status, 200, "Settings exported before Termly support should remain importable");
  assert.equal(olderImport.data.settings.termly, false, "Older settings files should default the new integration to off");
  assert.equal((await api("settings", { data: enabled })).status, 200, "The integration should be re enabled after the compatibility check");

  await page.goto(base + `/wp-admin/admin.php?page=change-vigil-site-journal&category=termly`);
  const activityTable = page.getByRole("table", { name: "Activity events" });
  await activityTable.waitFor();
  await page.getByRole("button", { name: /Consent banner changed/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("consent banner", { exact: true }).waitFor();
  await page.screenshot({ path: ".tmp/termly-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Change Vigil" }).getByRole("link", { name: "Settings", exact: true }).click();
  const integration = page.getByRole("checkbox", { name: /Termly/ });
  assert.equal(await integration.isChecked(), true);
  await page.getByText(/API keys, account details, cookie maps/i).waitFor();
  await page.screenshot({ path: ".tmp/termly-settings.png", fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal&category=termly");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/termly-mobile.png", fullPage: true });

  await page.setViewportSize({ width: 1440, height: 950 });
  console.log("Termly opt in, connection redaction, consent settings, unchanged saves, REST, CSV, settings compatibility, details, and narrow layout checks passed");
} finally {
  if (originalSettings) {
    await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
    await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
    await api("settings", { data: originalSettings });
  }
  if (null !== pluginWasActive) await setPluginActive(pluginWasActive);
  await browser.close();
}
