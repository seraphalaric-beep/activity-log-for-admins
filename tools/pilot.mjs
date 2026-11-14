import assert from "node:assert/strict";
import { mkdir, readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, password, level = "full", output = "test-results"] = process.argv.slice(2);
const { version } = JSON.parse(await readFile(new URL("../package.json", import.meta.url), "utf8"));
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");
const browser = await chromium.launch({ channel: "chrome", headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
page.setDefaultTimeout(12000);
const pageErrors = [];
page.on("pageerror", error => pageErrors.push(error.message));
const plugin = "activity-log-for-admins/activity-log-for-admins.php";
const screen = "/wp-admin/admin.php?page=activity-log-for-admins";
const navigation = page.getByRole("navigation", { name: "Activity Log", exact: true });

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
    const response = await fetch(url, {
      method: options.data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: options.data && JSON.stringify(options.data),
    });
    return { status: response.status, data: await response.json() };
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
  const activate = page.locator(`tr[data-plugin="${plugin}"] .activate a`);
  if (await activate.count()) await activate.click();
  await page.goto(base + screen);
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  assert.equal((await api("settings")).data.settings.retention, 90);
  await navigation.getByRole("link", { name: "Settings", exact: true }).click();
  await page.getByText(`Activity Log for Admins · Version ${version}`).waitFor();
  assert.deepEqual(pageErrors, []);
  if (level !== "full") {
    console.log("ZIP activation, REST, UI and Settings footer passed");
  } else {
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
    const { data: all } = await api("events");
    assert.ok(all.events.some(event => event.action_key === "sign_in_failed" && event.actor_kind === "anonymous"));
    assert.ok(all.events.some(event => event.action_key === "user_deleted"));
    assert.ok(all.events.some(event => event.action_key === "signed_in" && event.actor_name === "pilotadmin"));
    assert.ok(all.events.some(event => event.action_key === "role_changed" && event.actor_id !== event.item_key));
    assert.equal(all.events.filter(event => event.action_key === "role_changed").length, 1);
    assert.ok(all.events.some(event => event.action_key === "setting_changed"));
    assert.ok(all.events.some(event => event.action_key === "content_trashed"));
    assert.ok(all.events.some(event => event.action_key === "content_restored"));
    assert.ok(all.events.some(event => event.action_key === "content_status" && event.item_key === String(seeded.scheduled) && event.actor_kind === "system"));
    assert.ok(all.events.some(event => event.action_key === "plugin_installed"));
    assert.ok(all.events.some(event => event.action_key === "plugin_activated"));
    assert.ok(all.events.some(event => event.action_key === "plugin_updated"));
    assert.equal(all.events.filter(event => event.action_key === "plugin_updated").length, 1);
    assert.ok(all.events.some(event => event.action_key === "plugin_deleted"));
    assert.ok(all.events.some(event => event.action_key === "theme_installed"));
    assert.ok(all.events.some(event => event.action_key === "theme_switched"));
    assert.ok(all.events.some(event => event.action_key === "theme_updated"));
    assert.ok(all.events.some(event => event.action_key === "theme_deleted"));
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
    assert.ok(cleanup.batch && cleanup.deleted && cleanup.cleanupFailure && cleanup.writeFailure && cleanup.primaryWrite && cleanup.reactivated);
    await navigation.getByRole("link", { name: "Settings", exact: true }).click();
    await page.getByRole("spinbutton", { name: "Keep events for (days)" }).fill("60");
    await page.getByRole("button", { name: "Save settings" }).click();
    await page.getByText("Settings saved.").waitFor();
    assert.equal((await api("settings")).data.settings.retention, 60);
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
    console.log(JSON.stringify({ investigationAutomationMs: Math.round(elapsed), benchmark, checks: "Passed" }, null, 2));
  }
} finally {
  await browser.close();
}
