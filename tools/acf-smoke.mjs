import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
page.setDefaultTimeout(12000);

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

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");

  await page.goto(base + "/wp-admin/plugins.php");
  const acfRow = page.locator('tr[data-plugin="advanced-custom-fields/acf.php"]');
  await acfRow.waitFor();
  const acfInitiallyActive = 0 < await acfRow.locator(".deactivate a").count();
  if (acfInitiallyActive) {
    await acfRow.locator(".deactivate a").click();
    await page.waitForLoadState("domcontentloaded");
  }
  const absentExtension = await fixture("acf_hook_state");
  assert.deepEqual(absentExtension, { loaded: false, registered: false }, "An inactive ACF extension must add no update hook");
  await page.goto(base + "/wp-admin/plugins.php");
  const currentAcfRow = page.locator('tr[data-plugin="advanced-custom-fields/acf.php"]');
  await currentAcfRow.waitFor();
  const activateAcf = currentAcfRow.locator(".activate a");
  if (await activateAcf.count()) {
    await activateAcf.click();
    await page.waitForLoadState("domcontentloaded");
  }

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  await fixture("reset");
  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  assert.equal(defaults.data.settings.acf, false, "ACF recording must be opt in");
  const disabledHook = await fixture("acf_hook_state");
  assert.deepEqual(disabledHook, { loaded: true, registered: false }, "Disabling ACF recording must remove its update hook on the next request");
  const disabled = await fixture("acf_disabled");
  assert.equal(disabled.supported, true, "Advanced Custom Fields must be installed for this focused check");
  assert.equal(disabled.eventCount, 0, "Custom fields must not be captured while the integration is off");

  const enabledSettings = { ...defaults.data.settings, acf: true };
  assert.equal((await api("settings", { data: enabledSettings })).status, 200);
  await page.reload();
  const probe = await fixture("acf");
  assert.equal(probe.supported, true);
  assert.equal(probe.hookRegistered, true);
  assert.equal(probe.eventCount, 1, "Multiple changed fields should be grouped into one event");
  assert.equal(probe.ordinaryLabelVisible, true);
  assert.equal(probe.sensitiveLabelGeneric, true);
  assert.equal(probe.valuesRedacted, true, "Field values and field names must not reach event storage");
  if (probe.productSupported) assert.equal(probe.productCaptured, true, "Product field labels should be recorded without values");

  const events = await api("events", { query: { item: `page:${probe.postId}`, category: "acf" } });
  assert.equal(events.status, 200);
  const event = events.data.events.find(row => row.action_key === "acf_fields_changed");
  assert.ok(event, "The ACF event must be available through REST");
  const detail = await api(`events/${event.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.category, "acf");
  assert.deepEqual(detail.data.details.changes["Editorial marker"], ["Not recorded", "Changed"]);
  assert.deepEqual(detail.data.details.changes["Sensitive custom field"], ["Not recorded", "Changed"]);
  const csv = await api("export", { query: { category: "acf" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("acf_fields_changed"));
  assert.doesNotMatch(csv.data, /before-[a-z0-9]+@example\.invalid|after-[a-z0-9]+@example\.invalid|Before marker|After marker/i);

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=acf");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  await page.getByRole("button", { name: /Changed 2 custom fields/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("Editorial marker", { exact: true }).waitFor();
  await dialog.getByText("Sensitive custom field", { exact: true }).waitFor();
  await dialog.getByText("Not recorded", { exact: true }).first().waitFor();
  await dialog.getByText("After marker", { exact: true }).waitFor({ state: "hidden" });
  await page.screenshot({ path: ".tmp/acf-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Activity Log" }).getByRole("link", { name: "Settings", exact: true }).click();
  const acfCheckbox = page.getByRole("checkbox", { name: /Advanced Custom Fields/ });
  await acfCheckbox.waitFor();
  assert.equal(await acfCheckbox.isChecked(), true);
  await page.getByText(/Values are never stored/i).waitFor();
  await page.getByText(/Up to 40 labels appear in each event/i).waitFor();
  await page.screenshot({ path: ".tmp/acf-settings.png", fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=acf");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/acf-mobile.png", fullPage: true });
  assert.equal((await api("settings", { data: defaults.data.settings })).status, 200);

  await page.goto(base + "/wp-admin/plugins.php");
  const endAcfRow = page.locator('tr[data-plugin="advanced-custom-fields/acf.php"]');
  await endAcfRow.waitFor();
  const deactivateAtEnd = endAcfRow.locator(".deactivate a");
  if (await deactivateAtEnd.count()) {
    await deactivateAtEnd.click();
    await page.waitForLoadState("domcontentloaded");
  }
  const absentAgain = await fixture("acf_hook_state");
  assert.deepEqual(absentAgain, { loaded: false, registered: false });
  if (acfInitiallyActive) {
    await page.goto(base + "/wp-admin/plugins.php");
    const restoreRow = page.locator('tr[data-plugin="advanced-custom-fields/acf.php"]');
    await restoreRow.waitFor();
    const reactivateAcf = restoreRow.locator(".activate a");
    if (await reactivateAcf.count()) {
      await reactivateAcf.click();
      await page.waitForLoadState("domcontentloaded");
    }
  }

  console.log("Advanced Custom Fields opt in, privacy, unchanged save, product capture, REST, CSV, detail panel, settings, and narrow layout checks passed");
} finally {
  await browser.close();
}
