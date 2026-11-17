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

let wpformsInitiallyActive = false;
let originalSettings = null;
try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");

  const absent = await fixture("wpforms_hook_state");
  assert.deepEqual(absent, { loaded: false, registered: false }, "An inactive WPForms extension must add no submission hook");
  await page.goto(base + "/wp-admin/plugins.php");
  const wpformsRow = page.locator('tr[data-plugin="wpforms-lite/wpforms.php"]');
  await wpformsRow.waitFor();
  wpformsInitiallyActive = 0 < await wpformsRow.locator(".deactivate a").count();
  if (!wpformsInitiallyActive) {
    await wpformsRow.locator(".activate a").click();
    await page.waitForLoadState("domcontentloaded");
  }

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  await fixture("reset");
  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  originalSettings = defaults.data.settings;
  assert.equal(defaults.data.settings.wpforms, false, "WPForms recording must be opt in");
  const disabledHook = await fixture("wpforms_hook_state");
  assert.deepEqual(disabledHook, { loaded: true, registered: false }, "Disabling WPForms recording must remove the submission hook on the next request");
  const disabled = await fixture("wpforms_disabled");
  assert.equal(disabled.supported, true, "WPForms Lite must be installed for this focused check");
  assert.equal(disabled.capturedWhileDisabled, false, "Form data must not fall through to generic events while the integration is off");

  assert.equal((await api("settings", { data: { ...defaults.data.settings, wpforms: true } })).status, 200);
  await page.reload();
  const enabledHook = await fixture("wpforms_hook_state");
  assert.deepEqual(enabledHook, { loaded: true, registered: true });
  const probe = await fixture("wpforms");
  assert.equal(probe.supported, true);
  for (const action of ["wpforms_form_created", "wpforms_form_edited", "wpforms_form_status_changed", "wpforms_form_trashed", "wpforms_form_restored", "wpforms_form_deleted"]) {
    assert.ok(probe.actions.includes(action), `Missing WPForms event ${action}`);
  }
  assert.ok(probe.actions.includes("wpforms_entry_submitted"), "Missing stored submission event");
  assert.equal(probe.unchangedIgnored, true, "Unchanged form saves must not be recorded");
  assert.equal(probe.zeroEntryIdIgnored, true, "WPForms Lite submissions without stored entries must not be recorded");
  assert.equal(probe.submissionCapturedByForm, true, "Stored submissions must be attributed to their form without entry details");
  assert.equal(probe.privacyRedacted, true, "Form definitions, answers, email, and entry identifiers must not be recorded");

  const events = await api("events", { query: { item: `wpforms_form:${probe.formId}`, category: "wpforms" } });
  assert.equal(events.status, 200);
  const edited = events.data.events.find(event => event.action_key === "wpforms_form_edited");
  assert.ok(edited, "The form edit must be listed through REST");
  const detail = await api(`events/${edited.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.category, "wpforms");
  assert.deepEqual(detail.data.details.changes["Form definition"], ["Not recorded", "Changed"]);
  const csv = await api("export", { query: { category: "wpforms" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("wpforms_entry_submitted"));
  assert.doesNotMatch(csv.data, /PRIVATE_WPFORMS_|private(?:-zero)?@example\.invalid|87654321/);

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=wpforms&q=" + encodeURIComponent(probe.formName));
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  await page.getByRole("button", { name: /Renamed WPForms form/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("Form definition", { exact: true }).waitFor();
  await dialog.getByText("Changed", { exact: true }).waitFor();
  await page.screenshot({ path: ".tmp/wpforms-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Activity Log" }).getByRole("link", { name: "Settings", exact: true }).click();
  const integration = page.getByRole("checkbox", { name: /Record form changes and successful stored submissions by form/ });
  await integration.waitFor();
  assert.equal(await integration.isChecked(), true);
  await page.getByText(/Form definitions, submitted answers, and individual submission identifiers are never stored/i).waitFor();
  await page.screenshot({ path: ".tmp/wpforms-settings.png", fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=wpforms");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/wpforms-mobile.png", fullPage: true });
  assert.equal((await api("settings", { data: originalSettings })).status, 200);

  console.log("WPForms opt in, form lifecycle, privacy, stored submission signal, REST, CSV, detail panel, settings, and narrow layout checks passed");
} finally {
  if (originalSettings) {
    await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
    await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
    await api("settings", { data: originalSettings });
  }
  if (!wpformsInitiallyActive) {
    await page.goto(base + "/wp-admin/plugins.php");
    const restoreRow = page.locator('tr[data-plugin="wpforms-lite/wpforms.php"]');
    await restoreRow.waitFor();
    const deactivate = restoreRow.locator(".deactivate a");
    if (await deactivate.count()) {
      await deactivate.click();
      await page.waitForLoadState("domcontentloaded");
    }
  }
  await browser.close();
}
