import assert from "node:assert/strict";
import { mkdir, readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument, level = "full", output = "test-results"] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
const { version } = JSON.parse(await readFile(new URL("../package.json", import.meta.url), "utf8"));
const skipRegression = process.env.ALFA_SKIP_REGRESSION === "1";
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");
const launchOptions = { headless: true };
if (process.env.PLAYWRIGHT_CHANNEL !== "chromium") launchOptions.channel = process.env.PLAYWRIGHT_CHANNEL ?? "chrome";
const browser = await chromium.launch(launchOptions);
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
page.setDefaultTimeout(12000);
const pageErrors = [];
const activityRequests = [];
page.on("pageerror", error => pageErrors.push(error.message));
page.on("request", request => {
  const url = new URL(request.url());
  const route = url.searchParams.get("rest_route") ?? url.pathname;
  if (route.endsWith("/activity-log-for-admins/v1/events")) activityRequests.push(url);
});
const plugin = "activity-log-for-admins/activity-log-for-admins.php";
const screen = "/wp-admin/admin.php?page=activity-log-for-admins";
const navigation = page.getByRole("navigation", { name: "Activity Log", exact: true });

function assertPrivateNoStore(response, label) {
  assert.match(response.cacheControl ?? "", /\bprivate\b/i, `${label} response must be private`);
  assert.match(response.cacheControl ?? "", /\bno-store\b/i, `${label} response must not be stored`);
  assert.doesNotMatch(response.cacheControl ?? "", /\bpublic\b/i, `${label} response must not be public`);
}

async function signIn(tab, user, value) {
  await tab.goto(base + "/wp-login.php");
  await tab.locator("#user_login").fill(user);
  await tab.locator("#user_pass").fill(value);
  await tab.locator("#wp-submit").click();
  await tab.waitForLoadState("domcontentloaded");
}

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
    const data = contentType.includes("application/json") ? await response.json() : await response.text();
    return { status: response.status, data, cacheControl: response.headers.get("cache-control"), url: url.toString() };
  }, { path, options });
}

async function fixture(operation) {
  const response = await page.request.post(base + "/alfa-fixture.php", { form: { operation } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

try {
  await signIn(page, "pilotadmin", password);
  await page.goto(base + "/wp-admin/plugins.php");
  const pluginRow = page.locator(`tr[data-plugin="${plugin}"]`);
  assert.match(await pluginRow.innerText(), /By Bryan Mc Namara/);
  const activate = pluginRow.locator(".activate a");
  if (await activate.count()) await activate.click();
  await fixture("reset");
  await page.goto(base + screen);
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  await page.getByText(/\d+ events? on this page/).waitFor();
  assert.ok(activityRequests.some(url => url.searchParams.has("_alfa_request")), "Activity list requests must bypass shared caches");
  const settingsResponse = await api("settings");
  assert.equal(settingsResponse.data.settings.retention, 90);
  assert.equal(settingsResponse.data.settings.woocommerce, false);
  assert.equal(settingsResponse.data.settings.redirection, false);
  assert.equal(settingsResponse.data.settings.email_alerts, false);
  assert.equal(settingsResponse.data.settings.capture_comments, true);
  assertPrivateNoStore(settingsResponse, "Settings");
  await navigation.getByRole("link", { name: "Settings", exact: true }).click();
  await page.getByText(`Activity Log for Admins · Version ${version}`).waitFor();
  assert.deepEqual(pageErrors, []);
  if (level !== "full") {
    console.log("ZIP activation, REST, UI and Settings footer passed");
  } else {
    const disabledIntegrations = await fixture("integrations");
    assert.deepEqual(disabledIntegrations.orderActions, []);
    assert.deepEqual(disabledIntegrations.redirectActions, []);
    await page.getByRole("checkbox", { name: /WooCommerce/ }).check();
    await page.getByRole("checkbox", { name: /Redirection/ }).check();
    await page.getByRole("button", { name: "Save settings" }).click();
    await page.getByText("Settings saved.", { exact: true }).waitFor();
    const integrationSettings = (await api("settings")).data.settings;
    assert.equal(integrationSettings.woocommerce, true);
    assert.equal(integrationSettings.redirection, true);
    const integrationEvents = await fixture("integrations");
    assert.deepEqual(integrationEvents.orderActions.sort(), ["order_created", "order_refunded", "order_status_changed"]);
    assert.deepEqual(integrationEvents.redirectActions.sort(), ["redirect_deleted", "redirect_disabled", "redirect_enabled", "redirect_updated"]);
    assert.equal(integrationEvents.privacyOk, true);
    await page.getByRole("checkbox", { name: /Email warning digests/ }).check();
    await page.getByRole("button", { name: "Save settings" }).click();
    await page.getByText("Settings saved.", { exact: true }).waitFor();
    assert.equal((await fixture("warning")).scheduled, true);
    const emailDelivery = await fixture("alert_delivery");
    assert.equal(emailDelivery.sent, true);
    assert.equal(emailDelivery.plainText, true);
    assert.equal(emailDelivery.summaryOnly, true);
    assert.equal(emailDelivery.safeSubject, true);
    await page.getByRole("checkbox", { name: /Email warning digests/ }).uncheck();
    await page.getByRole("button", { name: "Save settings" }).click();
    await page.getByText("Settings saved.", { exact: true }).waitFor();
    assert.equal((await fixture("warning")).scheduled, false);
    const multisite = await fixture("multisite");
    if (multisite.supported) {
      assert.equal(multisite.tableCreatedOnNewSite, true);
      assert.equal(multisite.schemaInitializedOnNewSite, true);
      assert.equal(multisite.scheduledOnNewSite, true);
      assert.equal(multisite.eventStoredOnNewSite, true);
      assert.equal(multisite.eventIsolatedFromMainSite, true);
      assert.equal(multisite.siteRetention, 7);
      assert.equal(multisite.retentionIsolatedFromMainSite, true);
      assert.equal(multisite.cleanupRescheduledOnUpgrade, true);
    }
    await fixture("reset");
    const signInProbe = await browser.newPage();
    await signIn(signInProbe, "pilotadmin", password);
    await signInProbe.close();
    await navigation.getByRole("link", { name: "Activity", exact: true }).click();
    const seeded = await fixture("events");
    const badLogin = await browser.newPage();
    await signIn(badLogin, "pilotadmin", "incorrect-pilot-password");
    await badLogin.locator("#login_error").waitFor();
    await badLogin.close();
    await page.reload();
    const allResponse = await api("events");
    assertPrivateNoStore(allResponse, "Activity list");
    assert.ok(new URL(allResponse.url).searchParams.has("_alfa_request"));
    const all = allResponse.data;
    const rowsFor = async (action, query = {}) => (await api("events", { query: { action, ...query } })).data.events;
    assert.ok((await rowsFor("sign_in_failed")).some(event => event.actor_kind === "anonymous"));
    assert.ok((await rowsFor("user_deleted", { item: `user:${seeded.deletedUser}` })).length);
    if (multisite.supported) {
      assert.equal(seeded.siteRemovalCaptured, true);
      assert.ok((await rowsFor("user_removed_from_site", { item: `user:${seeded.removedUser}` })).length);
    }
    assert.ok((await rowsFor("signed_in")).some(event => event.actor_name === "pilotadmin"));
    const roleEvents = await rowsFor("role_changed");
    assert.ok(roleEvents.some(event => event.actor_id !== event.item_key));
    assert.equal(roleEvents.length, 1);
    assert.ok((await rowsFor("setting_changed")).length);
    assert.ok((await rowsFor("content_trashed")).length);
    assert.ok((await rowsFor("content_restored")).length);
    assert.ok((await rowsFor("content_status", { item: `page:${seeded.scheduled}` })).some(event => event.actor_kind === "system"));
    assert.ok((await rowsFor("plugin_installed")).length);
    assert.ok((await rowsFor("plugin_activated")).length);
    assert.equal((await rowsFor("plugin_updated")).length, 1);
    assert.ok((await rowsFor("plugin_deleted")).length);
    assert.ok((await rowsFor("theme_installed")).length);
    assert.ok((await rowsFor("theme_switched")).length);
    assert.ok((await rowsFor("theme_updated")).length);
    assert.ok((await rowsFor("theme_deleted")).length);

    const coverage = seeded.coverage;
    assert.equal(coverage.profileCaptured, true);
    assert.equal(coverage.profileValuesCaptured, true);
    assert.equal(coverage.profileEmailRedacted, true);
    assert.equal(coverage.menuDestinationCaptured, true);
    assert.equal(coverage.passwordCaptured, true);
    assert.equal(coverage.signedOutCaptured, true);
    assert.equal(coverage.privacyOk, true);
    assert.ok((await rowsFor("content_created", { item: `alfa_book:${coverage.book}` })).length);
    assert.ok((await rowsFor("content_edited", { item: `alfa_book:${coverage.book}` })).length);
    assert.ok((await rowsFor("term_created", { item: `term:alfa_genre:${coverage.genre}` })).length);
    assert.ok((await rowsFor("term_edited", { item: `term:alfa_genre:${coverage.genre}` })).length);
    assert.ok((await rowsFor("terms_changed", { q: coverage.suffix })).length >= 3);
    for (const action of ["comment_created", "comment_edited", "comment_status", "comment_deleted"]) {
      assert.ok((await rowsFor(action, { q: coverage.suffix })).length, `${action} should be captured`);
    }
    for (const action of ["menu_created", "menu_item_added", "menu_item_updated", "menu_item_deleted", "menu_deleted"]) {
      assert.ok((await rowsFor(action, { q: coverage.suffix })).length, `${action} should be captured`);
    }
    const widgetKey = `widget_alfa_pilot_${coverage.suffix.replaceAll("-", "_")}`;
    assert.ok((await rowsFor("widget_changed", { item: `widget:${widgetKey}` })).length);
    const commentSettings = (await api("settings")).data.settings;
    assert.equal((await api("settings", { data: { ...commentSettings, capture_comments: false } })).status, 200);
    const commentsPaused = await fixture("comment_events");
    assert.equal((await rowsFor("comment_created", { item: `comment:${commentsPaused.commentId}` })).length, 0);
    assert.equal((await api("settings", { data: { ...(await api("settings")).data.settings, capture_comments: true } })).status, 200);
    const commentsResumed = await fixture("comment_events");
    assert.equal((await rowsFor("comment_created", { item: `comment:${commentsResumed.commentId}` })).length, 1);
    const profile = await rowsFor("user_profile_changed", { item: `user:${coverage.profileUser}` });
    assert.ok(profile.length);
    const profileDetails = await api(`events/${profile[0].id}`);
    assert.ok(profileDetails.data.details.changes["Email address"]);
    assert.ok(profileDetails.data.details.changes["Display name"]);
    assert.ok((await rowsFor("password_changed", { item: `user:${coverage.profileUser}` })).length >= 2);
    assert.ok((await rowsFor("signed_out", { item: `user:${coverage.profileUser}` })).length);
    assertPrivateNoStore(await api(`events/${all.events[0].id}`), "Event details");
    assertPrivateNoStore(await api("export"), "CSV export");

    let dashboardEvent = "";
    if (skipRegression) {
      await page.goto(base + screen);
    } else {
      await page.goto(base + "/wp-admin/index.php");
      const dashboardSummary = page.locator(".alfa-dashboard-event-summary").first();
      await dashboardSummary.waitFor();
      dashboardEvent = await dashboardSummary.innerText();
      assert.ok(all.events.some(event => event.summary === dashboardEvent), "Dashboard event must exist in the full list response");
      await page.getByRole("link", { name: "View activity", exact: true }).click();
    }
    const activityTable = page.getByRole("table", { name: "Activity events" });
    await activityTable.waitFor();
    if (!skipRegression) {
      await activityTable.getByText(dashboardEvent, { exact: true }).waitFor();
    }
    assert.equal((await api("events", { query: { q: "Housing Guide", action: "content_edited" } })).data.events.length, 1);

    await page.evaluate(async id => {
      const config = window.alfaSettings;
      const url = new URL(config.base);
      if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", "/wp/v2/pages/" + id);
      else url.pathname = "/wp-json/wp/v2/pages/" + id;
      const response = await fetch(url, { method: "POST", headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" }, body: JSON.stringify({ title: "REST editor change" }) });
      if (!response.ok) throw new Error(await response.text());
    }, seeded.restPage);
    const renamed = await api("events", { query: { q: "REST editor fixture", action: "content_edited" } });
    assert.equal(renamed.data.events.length, 1);
    assert.ok(renamed.data.events[0].summary.includes("REST editor change"));
    await page.goto(base + screen + `&item=${encodeURIComponent(`alfa_book:${coverage.book}`)}`);
    const filteredActivity = page.getByRole("table", { name: "Activity events" });
    await filteredActivity.waitFor();
    await filteredActivity.getByText(/Pilot book/).first().waitFor();
    await page.goto(base + screen);
    await page.getByRole("table", { name: "Activity events" }).waitFor();

    const started = performance.now();
    await page.getByRole("textbox", { name: "Search activity" }).fill("Housing Guide");
    await page.getByRole("button", { name: "Search", exact: true }).click();
    const event = page.getByRole("button", { name: "Changed “Housing Guide” from Published to Draft" });
    await event.focus();
    await page.keyboard.press("Enter");
    const details = page.getByRole("dialog");
    await details.getByText("Previous", { exact: true }).first().waitFor();
    await details.getByText("Published", { exact: true }).waitFor();
    await details.getByText("Draft", { exact: true }).waitFor();
    await details.getByRole("button", { name: "pilotadmin" }).waitFor();
    const elapsed = performance.now() - started;
    assert.ok(elapsed < 30000);
    await mkdir(output, { recursive: true });
    await page.screenshot({ path: output + "/activity-details.png", animations: "disabled" });
    await page.keyboard.press("Escape");
    await details.waitFor({ state: "hidden" });
    await page.waitForFunction(() => document.activeElement?.matches(".alfa-event-link"));
    assert.equal(await event.evaluate(node => document.activeElement === node), true);
    await page.reload();
    await event.waitFor();
    assert.equal(new URL(page.url()).searchParams.get("q"), "Housing Guide");
    await page.getByRole("button", { name: "Edited “Housing Guide”" }).click();
    await details.getByRole("link", { name: "Compare WordPress revisions" }).waitFor();
    await page.keyboard.press("Escape");
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    await page.getByLabel("Action", { exact: true }).selectOption("content_status");
    await page.getByLabel("Severity", { exact: true }).selectOption("notice");
    await page.getByRole("button", { name: "Apply filters" }).click();
    await event.waitFor();
    assert.equal((await api("events", { query: { from: "2026-02-30" } })).status, 400);
    assert.equal((await api("events", { query: { from: "2026-11-13", to: "2026-11-13" } })).status, 200);

    await fixture("seed");
    const first = await api("events", { query: { q: "Routine seeded activity" } });
    assert.equal(first.data.events.length, 50);
    const second = await api("events", { query: { q: "Routine seeded activity", before: first.data.next } });
    assert.ok(BigInt(second.data.events[0].id) < BigInt(first.data.events.at(-1).id));
    await page.goto(base + screen + "&q=Export+fixture");
    await page.getByRole("table", { name: "Activity events" }).waitFor();
    const downloadPromise = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export CSV" }).click();
    const download = await downloadPromise;
    const csv = await readFile(await download.path(), "utf8");
    assert.equal(csv.trimEnd().split(/\r?\n/).length, 553);
    assert.ok(csv.includes("'=1+1"));
    await page.goto(base + screen);
    await page.getByRole("table", { name: "Activity events" }).waitFor();
    await page.screenshot({ path: output + "/activity.png" });

    const mobile = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await signIn(mobile, "pilotadmin", password);
    await mobile.goto(base + screen + "&q=Housing+Guide");
    await mobile.getByRole("table", { name: "Activity events" }).waitFor();
    assert.ok(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await mobile.screenshot({ path: output + "/activity-mobile.png" });
    await mobile.getByRole("button", { name: "Changed “Housing Guide” from Published to Draft" }).click();
    await mobile.getByRole("dialog").getByText("Published", { exact: true }).waitFor();
    await mobile.screenshot({ path: output + "/activity-details-mobile.png", animations: "disabled" });
    await mobile.close();

    const before = await fixture("permissions");
    assert.deepEqual(before.anonymous, [401, 401, 401, 401, 401]);
    assert.deepEqual(before.editor, [403, 403, 403, 403, 403]);
    assert.equal((await api("settings", { data: { retention: 0, erase: false } })).status, 400);
    const cleanup = await fixture("health");
    assert.ok(cleanup.batch && cleanup.deleted && cleanup.cleanupFailure && cleanup.writeFailure && cleanup.primaryWrite && cleanup.reactivated && cleanup.upgradeRescheduled && cleanup.scheduleFailure);
    await navigation.getByRole("link", { name: "Settings", exact: true }).click();
    const scheduleFailure = await api("settings");
    assert.ok(scheduleFailure.data.health.schedule_error);
    assert.equal(scheduleFailure.data.health.next_cleanup, null);
    await page.getByText(/Last cleanup scheduling failure:/).waitFor();
    await page.getByText(/Automatic cleanup is unscheduled\./).waitFor();
    assert.equal((await fixture("age_seed")).seeded, 1001);
    await page.getByRole("spinbutton", { name: "Keep events for (days)" }).fill("1");
    await page.getByRole("button", { name: "Save settings" }).click();
    await page.getByText("Settings saved.").waitFor();
    assert.equal((await api("settings")).data.settings.retention, 1);
    assert.equal((await fixture("expired_count")).count, 1);
    assert.ok((await api("settings")).data.health.last_cleanup);
    await page.getByRole("spinbutton", { name: "Keep events for (days)" }).fill("60");
    await page.getByRole("button", { name: "Save settings" }).click();
    await page.getByText("Settings saved.").waitFor();
    const recoveredSettings = await api("settings");
    assert.equal(recoveredSettings.data.settings.retention, 60);
    assert.equal(recoveredSettings.data.health.schedule_error, null);
    assert.ok(recoveredSettings.data.health.next_cleanup);
    await page.getByText(/Last cleanup scheduling failure:/).waitFor({ state: "hidden" });
    await page.screenshot({ path: output + "/settings.png" });
    assert.deepEqual(pageErrors, []);
    const benchmark = await fixture("benchmark");
    assert.equal(benchmark.seeded, 100000);
    assert.ok(benchmark.firstP95 < 1000 && benchmark.filteredP95 < 1000, JSON.stringify(benchmark));
    const p95 = samples => Math.round(samples.sort((a, b) => a - b)[Math.ceil(samples.length * .95) - 1]);
    const timeRequests = async run => {
      await run();
      const samples = [];
      for (let attempt = 0; attempt < 25; attempt++) {
        const start = performance.now();
        await run();
        samples.push(performance.now() - start);
      }
      return p95(samples);
    };
    benchmark.restFirstP95 = await timeRequests(async () => assert.equal((await api("events")).status, 200));
    benchmark.restFilteredP95 = await timeRequests(async () =>
      assert.equal((await api("events", { query: { q: "Routine seeded activity 8999", actor: "1", action: "content_edited", severity: "info" } })).status, 200));
    assert.ok(benchmark.restFirstP95 < 1000 && benchmark.restFilteredP95 < 1000, JSON.stringify(benchmark));
    const publicPage = async () => {
      const response = await fetch(base);
      assert.ok(response.ok);
      const body = await response.text();
      assert.ok(!body.includes("/build/admin.js") && !body.includes("/build/admin.css"));
    };
    benchmark.publicEnabledP95 = await timeRequests(publicPage);
    assert.equal((await fixture("deactivate")).inactive, true);
    try {
      benchmark.publicDisabledP95 = await timeRequests(publicPage);
    } finally {
      assert.equal((await fixture("activate")).active, true);
    }
    const uninstall = await fixture("uninstall");
    assert.deepEqual(uninstall, { preserved: true, erased: true });
    console.log(JSON.stringify({ investigationAutomationMs: Math.round(elapsed), regression: skipRegression ? "Skipped" : "Passed", benchmark, checks: "Passed" }, null, 2));
  }
} finally {
  await browser.close();
}
