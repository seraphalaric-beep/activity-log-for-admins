import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base = "http://127.0.0.1:8092"] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : "");
if (!password || new URL(base).hostname !== "127.0.0.1") throw new Error("Use the disposable loopback network and its pilot password.");

const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
const page = await context.newPage();
page.setDefaultTimeout(15000);
const pageErrors = [];
let fixture = null;
page.on("pageerror", error => pageErrors.push(error.message));

async function api(path, query = {}, data = undefined) {
  return page.evaluate(async ({ path, query, data }) => {
    const config = window.alfaSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
    url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    const type = response.headers.get("content-type") ?? "";
    return { status: response.status, data: type.includes("application/json") ? await response.json() : await response.text() };
  }, { path, query, data });
}

async function fixtureCall(operation, fields = {}) {
  const response = await context.request.post(base + "/alfa-fixture.php", { form: { operation, ...fields } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/network/admin.php?page=activity-log-for-admins-network");
  if (await page.getByRole("heading", { name: "Network Activity Log", exact: true }).count() === 0) {
    console.error(JSON.stringify({
      url: page.url(),
      title: await page.title(),
      body: (await page.locator("body").innerText()).slice(0, 1200),
      config: await page.evaluate(() => typeof window.alfaSettings === "undefined" ? null : window.alfaSettings),
      links: await page.locator("#adminmenu a").evaluateAll(links => links.filter(link => link.textContent.includes("Activity Log")).map(link => ({ text: link.textContent.trim(), href: link.href }))),
      root: await page.locator("#alfa-root").count(),
    }));
  }
  await page.getByRole("heading", { name: "Network Activity Log", exact: true }).waitFor();
  const config = await page.evaluate(() => window.alfaSettings);
  assert.equal(config.networkMode, true);
  assert.equal(config.canManage, true);
  assert.match(config.base, /activity-log-for-admins\/v1\/network\/$/);
  assert.equal(await page.getByRole("link", { name: "Reports", exact: true }).count(), 0);
  assert.equal(await page.getByRole("navigation", { name: "Activity Log", exact: true }).getByRole("link", { name: "Settings", exact: true }).count(), 0);

  const initial = await api("events");
  assert.equal(initial.status, 200);
  fixture = await fixtureCall("network_center");
  const query = { q: fixture.marker };
  const first = await api("events", query);
  assert.equal(first.status, 200, JSON.stringify(first.data));
  assert.equal(first.data.events.length, 50);
  assert.ok(first.data.next, "A second page is available for the 53 matching events.");
  assert.ok(first.data.events.every(event => event.network_site_name && event.network_site_id));
  assert.ok(first.data.events.some(event => event.network_site_id === String(fixture.siteId)));

  const second = await api("events", { ...query, before: first.data.next });
  assert.equal(second.status, 200, JSON.stringify(second.data));
  assert.equal(second.data.events.length, 3);
  const compositeIds = [...first.data.events, ...second.data.events].map(event => `${event.network_site_id}:${event.id}`);
  assert.equal(new Set(compositeIds).size, 53, "Composite site and event IDs keep multisite pagination stable.");

  const child = await api("events", { ...query, network_site_id: String(fixture.siteId) });
  assert.equal(child.status, 200);
  assert.equal(child.data.events.length, 50);
  assert.ok(child.data.events.every(event => event.network_site_id === String(fixture.siteId)));
  assert.ok(child.data.next);
  const warning = await api("events", { ...query, network_site_id: String(fixture.siteId), severity: "warning" });
  assert.equal(warning.data.events.length, 1);
  assert.equal(warning.data.events[0].severity, "warning");
  assert.equal((await api("events", { ...query, network_site_id: "999999999" })).status, 400);

  const detail = await api(`events/${fixture.siteId}/${fixture.childEventId}`);
  assert.equal(detail.status, 200, JSON.stringify(detail.data));
  assert.equal(detail.data.network_site_id, String(fixture.siteId));
  assert.match(detail.data.network_site_name, /Network center test site/);
  assert.deepEqual(detail.data.details.changes.Title, ["Previous title", "Updated title"]);
  assert.deepEqual(detail.data.notes, []);
  const note = "Network investigation note fixture";
  assert.equal((await api(`events/${fixture.siteId}/${fixture.childEventId}/notes`, {}, { note })).status, 200);
  const noted = await api(`events/${fixture.siteId}/${fixture.childEventId}`);
  assert.equal(noted.data.notes.length, 1);
  assert.equal(noted.data.notes[0].note, note);

  const csv = await api("export", query);
  assert.equal(csv.status, 200);
  assert.match(csv.data, /"Network site ID","Network site"/);
  assert.match(csv.data, new RegExp(fixture.marker));
  assert.equal(csv.data.trim().split(/\r?\n/).length, 54, "Network CSV contains all matching events beyond the visible page.");

  await page.getByRole("button", { name: /Filters/ }).click();
  const siteFilter = page.getByLabel("Network site ID", { exact: true });
  await siteFilter.fill(String(fixture.siteId));
  await page.getByRole("button", { name: "Apply filters" }).click();
  assert.equal(new URL(page.url()).searchParams.get("network_site_id"), String(fixture.siteId));
  await page.getByRole("button", { name: /Network center probe .* child event 51/ }).waitFor();
  await page.reload();
  await page.getByRole("heading", { name: "Network Activity Log", exact: true }).waitFor();
  await page.getByRole("button", { name: /Filters/ }).click();
  assert.equal(await page.getByLabel("Network site ID", { exact: true }).inputValue(), String(fixture.siteId));
  await page.getByRole("button", { name: /Network center probe .* child event 51/ }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("Network center test site, Site #" + fixture.siteId, { exact: true }).waitFor();
  await dialog.getByText(note, { exact: true }).waitFor();
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.setViewportSize({ width: 390, height: 844 });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), "The network view fits a narrow screen.");
  await page.screenshot({ path: ".tmp/network-parity-wp/network-center-mobile.png", animations: "disabled" });

  await page.getByRole("navigation", { name: "Activity Log", exact: true }).getByRole("link", { name: "Sites", exact: true }).click();
  await page.getByRole("heading", { name: "Network sites", exact: true }).waitFor();
  await page.getByLabel("Search network sites", { exact: true }).fill(String(fixture.siteId));
  await page.getByRole("button", { name: "Search sites" }).click();
  const siteRow = page.getByRole("row").filter({ hasText: `Site #${fixture.siteId}` });
  await siteRow.getByText("Network center test site", { exact: true }).waitFor();
  assert.equal(await page.getByText(`Activity Log for Admins, Version ${config.version}`, { exact: true }).count(), 1);
  await siteRow.getByText(/Manage .* categories/).click();
  const contentCategory = siteRow.getByRole("checkbox", { name: "Posts and pages", exact: true });
  assert.equal(await contentCategory.isChecked(), true);
  const originalSiteSettings = await api(`sites/${fixture.siteId}/settings`);
  assert.equal(originalSiteSettings.status, 200);
  const updatedRetention = originalSiteSettings.data.retention === 1 ? 2 : originalSiteSettings.data.retention - 1;
  await contentCategory.uncheck();
  await siteRow.getByRole("spinbutton", { name: `Retention days for Network center test site` }).fill(String(updatedRetention));
  await siteRow.getByRole("button", { name: "Save", exact: true }).click();
  await siteRow.getByText("Site settings saved.", { exact: true }).waitFor();
  const changedSiteSettings = await api(`sites/${fixture.siteId}/settings`);
  assert.equal(changedSiteSettings.data.categories.content.enabled, false);
  assert.equal(changedSiteSettings.data.retention, updatedRetention);
  const mainSiteSettings = await api("sites/1/settings");
  assert.equal(mainSiteSettings.data.categories.content.enabled, true, "Site category settings stay isolated.");
  assert.equal(mainSiteSettings.data.retention, 90, "Site retention stays isolated.");
  assert.equal((await api(`sites/${fixture.siteId}/settings`, {}, { categories: { unsupported: true } })).status, 400);
  assert.equal((await api("sites/999999999/settings")).status, 404);
  const restored = await api(`sites/${fixture.siteId}/settings`, {}, {
    retention: originalSiteSettings.data.retention,
    categories: Object.fromEntries(Object.entries(originalSiteSettings.data.categories).map(([key, category]) => [key, category.enabled])),
  });
  assert.equal(restored.status, 200);
  await page.screenshot({ path: ".tmp/network-parity-wp/network-sites-mobile.png", animations: "disabled" });

  const editor = await fixtureCall("access_user");
  const editorContext = await browser.newContext();
  const editorPage = await editorContext.newPage();
  await editorPage.goto(base + "/wp-login.php");
  await editorPage.locator("#user_login").fill("piloteditor");
  await editorPage.locator("#user_pass").fill(editor.editorPassword);
  await editorPage.locator("#wp-submit").click();
  await editorPage.waitForLoadState("domcontentloaded");
  const nonceResponse = await editorContext.request.post(base + "/alfa-fixture.php", { form: { operation: "nonce" } });
  assert.equal(nonceResponse.status(), 200);
  const { nonce } = await nonceResponse.json();
  const denied = await editorContext.request.get(base + "/wp-json/activity-log-for-admins/v1/network/events", { headers: { "X-WP-Nonce": nonce } });
  assert.equal(denied.status(), 403, "Site editors cannot read the network activity route.");
  await editorContext.close();

  assert.deepEqual(pageErrors, []);
  console.log("Network activity, site settings, composite pagination, event details, notes, complete CSV export, access control, and narrow layout passed.");
} finally {
  if (fixture) {
    const cleanup = await context.request.post(base + "/alfa-fixture.php", { form: { operation: "network_center_cleanup", marker: fixture.marker, site_id: fixture.siteId } });
    assert.equal(cleanup.status(), 200, await cleanup.text());
  }
  await browser.close();
}
