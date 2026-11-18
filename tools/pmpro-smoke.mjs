import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const plugin = "paid-memberships-pro/paid-memberships-pro.php";
const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
page.setDefaultTimeout(12000);
let originalSettings = null;
let pluginWasActive = false;

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
    await page.screenshot({ path: ".tmp/pmpro-activation-failed.png", fullPage: true });
    throw new Error(`Paid Memberships Pro state did not update. ${message}`);
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
  assert.equal(defaults.data.settings.pmpro, false, "Paid Memberships Pro recording must default off");
  const disabledState = await fixture("pmpro_hook_state");
  assert.equal(disabledState.loaded, true, "Paid Memberships Pro must be active for this check");
  assert.equal(disabledState.membershipHook, false, "The membership hook must stay absent while the integration is off");
  assert.equal(disabledState.levelCreateHook, false, "The level creation hook must stay absent while the integration is off");
  const disabled = await fixture("pmpro_disabled");
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, "No membership event should be captured while the integration is off");

  const enabled = { ...defaults.data.settings, pmpro: true };
  assert.equal((await api("settings", { data: enabled })).status, 200);
  await page.reload();
  const enabledState = await fixture("pmpro_hook_state");
  assert.equal(enabledState.membershipHook, true, "The membership hook should register after opt in");
  assert.equal(enabledState.levelCreateHook, true, "The level creation hook should register after opt in");
  assert.equal(enabledState.levelBeforeUpdateHook, true, "The level update comparison hook should register after opt in");
  assert.equal(enabledState.levelUpdateHook, true, "The level update hook should register after opt in");
  assert.equal(enabledState.levelDeleteHook, true, "The level deletion hook should register after opt in");

  const probe = await fixture("pmpro");
  assert.equal(probe.supported, true);
  assert.ok(probe.pluginVersion);
  assert.equal(probe.createdLevelCount, 2, "Both membership level creations should be captured");
  assert.equal(probe.updatedLevelCount, 1, "The changed level should be captured and the unchanged save ignored");
  assert.deepEqual(probe.updateHookTrace, [
    ["before", probe.levelIds[0]], ["after", probe.levelIds[0]],
    ["before", probe.levelIds[0]], ["after", probe.levelIds[0]],
  ], "PMPro level saves should call both documented update hooks");
  assert.equal(probe.membershipEventCount, 3, "Membership grant, change, and cancellation should be captured");
  assert.equal(probe.deletedLevelCount, 1, "Membership level deletion should be captured");
  assert.equal(probe.privacyRedacted, true, "Member identity, level names, descriptions, and contact details must not be stored");
  assert.equal(probe.membershipChanges.length, 2, "Membership event details should show only previous and new level identifiers");
  assert.ok(probe.membershipChanges.every(value => typeof value === "string"));

  const events = await api("events", { query: { item: `user:${probe.userId}`, category: "pmpro" } });
  assert.equal(events.status, 200);
  const membershipEvent = events.data.events.find(event => event.action_key === "membership_changed");
  assert.ok(membershipEvent, "Membership change should appear in REST results");
  const detail = await api(`events/${membershipEvent.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.action_key, "membership_changed");

  const csv = await api("export", { query: { category: "pmpro" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("membership_changed"));
  assert.ok(csv.data.includes("membership_level_created"));
  assert.doesNotMatch(csv.data, /PRIVATE_PMPRO_|pmprofixture|@example\.invalid/);

  const settingsExport = await api("settings/export");
  assert.equal(settingsExport.status, 200);
  assert.equal(settingsExport.data.settings.pmpro, true, "Settings export should include the integration choice");
  const olderExport = structuredClone(settingsExport.data);
  delete olderExport.settings.pmpro;
  const olderImport = await api("settings/import", { data: olderExport });
  assert.equal(olderImport.status, 200, "Settings exported before this integration should remain importable");
  assert.equal(olderImport.data.settings.pmpro, false, "An older export should default the new integration to off");
  assert.equal((await api("settings", { data: enabled })).status, 200, "The integration should be re enabled after the compatibility check");

  await page.goto(base + `/wp-admin/admin.php?page=change-vigil-site-journal&category=pmpro&item=${encodeURIComponent(`user:${probe.userId}`)}`);
  const activityTable = page.getByRole("table", { name: "Activity events" });
  await activityTable.waitFor();
  await page.getByRole("button", { name: /Membership access changed/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("Membership level IDs", { exact: true }).waitFor();
  await dialog.getByText("Changed Paid Memberships Pro access", { exact: false }).waitFor().catch(() => {});
  await page.screenshot({ path: ".tmp/pmpro-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Change Vigil" }).getByRole("link", { name: "Settings", exact: true }).click();
  const integration = page.getByRole("checkbox", { name: /Paid Memberships Pro/ });
  assert.equal(await integration.isChecked(), true);
  await page.getByText(/Membership access can reveal personal information/i).waitFor();
  await page.screenshot({ path: ".tmp/pmpro-settings.png", fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + `/wp-admin/admin.php?page=change-vigil-site-journal&category=pmpro`);
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/pmpro-mobile.png", fullPage: true });

  await page.setViewportSize({ width: 1440, height: 950 });
  console.log("Paid Memberships Pro opt in, level lifecycle, membership changes, unchanged saves, REST, CSV, privacy, details, and narrow layout checks passed");
} finally {
  if (originalSettings) {
    await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
    await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
    await api("settings", { data: originalSettings });
  }
  await setPluginActive(pluginWasActive);
  await browser.close();
}
