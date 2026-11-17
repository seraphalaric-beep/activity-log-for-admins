import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Use the disposable loopback site and its pilot password.");

const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
const page = await context.newPage();
page.setDefaultTimeout(12000);
let fixture = null;
let savedReportId = null;

async function signIn() {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
}

async function api(path, query = {}, data = null) {
  return page.evaluate(async ({ path, query, data }) => {
    const config = window.alfaSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, String(value));
    url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    return { status: response.status, data: await response.json() };
  }, { path, query, data });
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
  const settings = await api("settings");
  assert.equal(settings.status, 200);
  fixture = await fixtureCall("investigation_filters_seed");

  for (const event of fixture.events) {
    const byEvent = await api("events", { event_id: event.id });
    assert.equal(byEvent.status, 200);
    assert.equal(byEvent.data.events.length, 1, "An exact event identifier returns only its event.");
    assert.equal(Number(byEvent.data.events[0].id), event.id);

    const byItem = await api("events", { item_kind: event.kind, item_id: event.item_id });
    assert.equal(byItem.status, 200);
    assert.equal(byItem.data.events.length, 1, "Item type and exact item identifier combine as an exact filter.");
    assert.equal(Number(byItem.data.events[0].id), event.id);
  }

  const target = fixture.events[0];
  const targetEvent = await api("events", { event_id: target.id });
  assert.equal(targetEvent.status, 200);
  const targetDate = await page.evaluate(iso => new Intl.DateTimeFormat("en-CA", { timeZone: window.alfaSettings.timezone || "UTC" }).format(new Date(iso)), targetEvent.data.events[0].time_iso);
  const saved = await api("reports/saved", {}, {
    name: "Exact item investigation",
    filters: {
      event_id: String(target.id),
      item_kind: target.kind,
      item_id: target.item_id,
      from: targetDate,
      to: targetDate,
    },
    group: "category",
    interval: "daily",
    range_days: Math.min(7, settings.data.settings.retention),
    columns: ["event_id", "summary"],
  });
  assert.equal(saved.status, 200, "Exact filters are accepted by saved report templates.");
  savedReportId = saved.data.report.id;
  assert.equal(saved.data.report.statistics.total, 1, "Saved statistics honor exact investigation filters.");
  const savedList = await api("reports/saved");
  assert.equal(savedList.status, 200);
  const restoredReport = savedList.data.reports.find(report => report.id === savedReportId);
  assert.equal(restoredReport.filters.event_id, String(target.id));
  assert.equal(restoredReport.filters.item_kind, target.kind);
  assert.equal(restoredReport.filters.item_id, target.item_id);

  assert.equal((await api("events", { event_id: "0" })).status, 400, "Zero is rejected as an event identifier.");
  assert.equal((await api("events", { item_kind: "not_registered" })).status, 400, "Unknown item types are rejected.");
  assert.equal((await api("events", { item_id: "x".repeat(192) })).status, 400, "Oversized exact item identifiers are rejected.");

  const shared = new URL("/wp-admin/admin.php", base);
  shared.searchParams.set("page", "activity-log-for-admins");
  shared.searchParams.set("event_id", String(target.id));
  shared.searchParams.set("item_kind", target.kind);
  shared.searchParams.set("item_id", target.item_id);
  await page.goto(shared.href);
  await page.locator(".alfa-event-link").waitFor();
  assert.equal(await page.locator(".alfa-event-link").count(), 1, "The shareable URL restores every exact filter.");
  await page.getByRole("button", { name: /Filters/ }).click();
  assert.equal(await page.getByLabel("Exact event ID").inputValue(), String(target.id));
  assert.equal(await page.getByLabel("Affected item type").inputValue(), target.kind);
  assert.equal(await page.getByLabel("Exact item ID").inputValue(), target.item_id);
  const exportHref = await page.getByRole("link", { name: "Export CSV" }).getAttribute("href");
  const exportUrl = new URL(exportHref);
  assert.equal(exportUrl.searchParams.get("event_id"), String(target.id));
  assert.equal(exportUrl.searchParams.get("item_kind"), target.kind);
  assert.equal(exportUrl.searchParams.get("item_id"), target.item_id);
  const csv = await page.evaluate(async href => {
    const response = await fetch(href, { credentials: "same-origin", cache: "no-store" });
    return { status: response.status, text: await response.text() };
  }, exportHref);
  assert.equal(csv.status, 200, "Filtered CSV export accepts the exact filters.");
  assert.ok(csv.text.includes(target.item_id));
  assert.ok(!fixture.events.slice(1).some(event => csv.text.includes(event.item_id)), "CSV excludes other fixture items.");
  const reportHref = await page.getByRole("link", { name: "Download HTML report" }).getAttribute("href");
  const reportUrl = new URL(reportHref);
  assert.equal(reportUrl.searchParams.get("event_id"), String(target.id));
  assert.equal(reportUrl.searchParams.get("item_kind"), target.kind);
  assert.equal(reportUrl.searchParams.get("item_id"), target.item_id);
  const html = await page.evaluate(async href => {
    const response = await fetch(href, { credentials: "same-origin", cache: "no-store" });
    return { status: response.status, text: await response.text() };
  }, reportHref);
  assert.equal(html.status, 200, "Filtered HTML report accepts the exact filters.");
  assert.ok(html.text.includes(target.item_name));
  assert.ok(!fixture.events.slice(1).some(event => html.text.includes(event.item_name)), "HTML report excludes other fixture items.");
  await page.screenshot({ path: ".tmp/network-parity-wp/investigation-filters-desktop.png", animations: "disabled" });
  await page.setViewportSize({ width: 390, height: 844 });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), "The filters fit a narrow admin viewport.");
  assert.equal(await page.locator(".alfa-filter-grid").evaluate(element => getComputedStyle(element).gridTemplateColumns.split(" ").length), 1, "Filter controls use a readable single column on narrow screens.");
  await page.screenshot({ path: ".tmp/network-parity-wp/investigation-filters-mobile.png", animations: "disabled" });

  console.log("Exact event, item type, and item identifier filters passed REST validation and shared URL restoration on desktop and narrow screens.");
} finally {
  if (savedReportId) await api(`reports/saved/${savedReportId}/delete`, {}, {}).catch(() => {});
  if (fixture) await fixtureCall("investigation_filters_cleanup", { marker: fixture.marker }).catch(() => {});
  await browser.close();
}
