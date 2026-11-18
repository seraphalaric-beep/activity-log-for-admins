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
let reportFixture = null;
let statisticsReportId = null;

async function api(path, data, query = {}) {
  return page.evaluate(async ({ path, data, query }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
      cache: "no-store",
    });
    return { status: response.status, body: await response.json().catch(() => null) };
  }, { path, data, query });
}

async function statisticsDownload(id, format) {
  return page.evaluate(async ({ id, format }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    const path = `reports/saved/${id}/statistics/download`;
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    url.searchParams.set("format", format);
    url.searchParams.set("_wpnonce", config.nonce);
    const response = await fetch(url, { headers: { "X-WP-Nonce": config.nonce }, cache: "no-store" });
    return {
      status: response.status,
      contentType: response.headers.get("content-type"),
      disposition: response.headers.get("content-disposition"),
      securityPolicy: response.headers.get("content-security-policy"),
      body: await response.text(),
    };
  }, { id, format });
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();

  const oldFixtureCleanup = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "report_html_cleanup_all" } });
  assert.equal(oldFixtureCleanup.status(), 200, await oldFixtureCleanup.text());

  const fixtureResponse = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "report_html" } });
  assert.equal(fixtureResponse.status(), 200, await fixtureResponse.text());
  const fixture = await fixtureResponse.json();
  reportFixture = fixture;
  assert.equal(fixture.count, 251);

  const endpoint = await page.evaluate(({ item }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + "report");
    else url.pathname += "report";
    url.searchParams.set("item", item);
    url.searchParams.set("title", "Activity report <script>bad()</script>");
    url.searchParams.set("_wpnonce", config.nonce);
    return { url: url.href, nonce: config.nonce };
  }, { item: fixture.item });

  const invalidNonceUrl = new URL(endpoint.url);
  invalidNonceUrl.searchParams.set("_wpnonce", "invalid");
  const denied = await page.request.get(invalidNonceUrl.href, { headers: { "X-WP-Nonce": "invalid" } });
  assert.ok([401, 403].includes(denied.status()), "The report must reject an invalid REST nonce");
  const report = await page.evaluate(async ({ url, nonce }) => {
    const response = await fetch(url, { headers: { "X-WP-Nonce": nonce }, cache: "no-store" });
    return {
      status: response.status,
      contentType: response.headers.get("content-type"),
      disposition: response.headers.get("content-disposition"),
      cache: response.headers.get("cache-control"),
      nosniff: response.headers.get("x-content-type-options"),
      body: await response.text(),
    };
  }, endpoint);

  assert.equal(report.status, 200);
  assert.match(report.contentType, /text\/html/i);
  assert.match(report.disposition, /attachment; filename="activity-report-/i);
  assert.match(report.cache, /no-store/i);
  assert.equal(report.nosniff, "nosniff");
  assert.match(report.body, /REPORT_HTML_ROW_1/);
  assert.match(report.body, /REPORT_HTML_ROW_251/);
  assert.equal((report.body.match(/<tr>/g) ?? []).length, 252, "The report should stream every matching event across the batch boundary");
  assert.match(report.body, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.match(report.body, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.doesNotMatch(report.body, /<script|<img|<svg/i, "Untrusted event values must be escaped in the HTML report");

  const selectedUrl = new URL(endpoint.url);
  selectedUrl.searchParams.set("columns", "event_id,summary");
  selectedUrl.searchParams.set("branding", "1");
  selectedUrl.searchParams.set("title", "Page review & follow up");
  const selectedReportResponse = await page.request.get(selectedUrl.href, { headers: { "X-WP-Nonce": endpoint.nonce } });
  assert.equal(selectedReportResponse.status(), 200);
  const selectedReport = await selectedReportResponse.text();
  assert.match(selectedReport, /<h1>Page review &amp; follow up<\/h1>/);
  assert.match(selectedReport, /class="report-brand"/);
  assert.match(selectedReport, /<th scope="col">Event ID<\/th><th scope="col">Summary<\/th>/);
  assert.doesNotMatch(selectedReport, /<th scope="col">Actor<\/th>|<th scope="col">Change details<\/th>/);
  assert.match(selectedReport, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);

  const invalidColumnsUrl = new URL(endpoint.url);
  invalidColumnsUrl.searchParams.set("columns", "summary,secret");
  const invalidColumns = await page.request.get(invalidColumnsUrl.href, { headers: { "X-WP-Nonce": endpoint.nonce } });
  assert.equal(invalidColumns.status(), 400, "HTML reports must reject fields outside the allowlist");

  const statistics = await page.evaluate(async ({ item }) => {
    const config = window.cvsjSettings;
    const parts = new Intl.DateTimeFormat("en-GB", { timeZone: config.timezone, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
    const today = `${values.year}-${values.month}-${values.day}`;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + "reports/statistics");
    else url.pathname += "reports/statistics";
    for (const [key, value] of Object.entries({ item, from: today, to: today, group: "category", interval: "daily" })) url.searchParams.set(key, value);
    const response = await fetch(url, { headers: { "X-WP-Nonce": config.nonce }, cache: "no-store" });
    return { status: response.status, body: await response.json(), today };
  }, { item: fixture.item });
  assert.equal(statistics.status, 200);
  assert.equal(statistics.body.total, 251);
  assert.deepEqual(statistics.body.rows, [{ label: "settings", total: 251 }]);

  const groups = await page.evaluate(async ({ item, today }) => {
    const config = window.cvsjSettings;
    const results = {};
    for (const [group, interval] of [["action", "daily"], ["severity", "daily"], ["actor_role", "daily"], ["source", "daily"], ["day", "weekly"], ["day", "monthly"]]) {
      const url = new URL(config.base);
      if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + "reports/statistics");
      else url.pathname += "reports/statistics";
      for (const [key, value] of Object.entries({ item, from: today, to: today, group, interval })) url.searchParams.set(key, value);
      const response = await fetch(url, { headers: { "X-WP-Nonce": config.nonce }, cache: "no-store" });
      results[group + "_" + interval] = { status: response.status, body: await response.json() };
    }
    return results;
  }, { item: fixture.item, today: statistics.today });
  for (const [key, label] of [["action_daily", "setting_changed"], ["severity_daily", "info"], ["actor_role_daily", "administrator"], ["source_daily", "admin"]]) {
    assert.equal(groups[key].status, 200);
    assert.deepEqual(groups[key].body.rows, [{ label, total: 251 }]);
  }
  assert.equal(groups.day_weekly.body.total, 251);
  assert.match(groups.day_weekly.body.rows[0].label, /^\d{4}-\d{2}-\d{2}$/);
  assert.equal(groups.day_monthly.body.total, 251);
  assert.match(groups.day_monthly.body.rows[0].label, /^\d{4}-\d{2}-01$/);

  const savedStatistics = await api("reports/saved", {
    name: "=Statistics & <img src=x onerror=alert(1)>",
    filters: { item: fixture.item, from: statistics.today, to: statistics.today },
    group: "category",
    interval: "daily",
    range_days: 1,
    columns: ["summary"],
    branding: false,
    tags: ["+quarterly"],
  });
  assert.equal(savedStatistics.status, 200);
  statisticsReportId = savedStatistics.body.report.id;
  assert.equal(savedStatistics.body.report.statistics.total, 251);
  assert.deepEqual(savedStatistics.body.report.statistics.rows, [{ label: "settings", total: 251 }]);
  assert.equal(savedStatistics.body.report.statistics.from, statistics.today);
  const savedList = await api("reports/saved");
  const savedEntry = savedList.body.reports.find(entry => entry.id === statisticsReportId);
  assert.ok(savedEntry?.statistics, "The generated statistical result remains available with its saved report.");

  const csvStatistics = await statisticsDownload(statisticsReportId, "csv");
  assert.equal(csvStatistics.status, 200);
  assert.match(csvStatistics.contentType, /text\/csv/i);
  assert.match(csvStatistics.disposition, /attachment; filename="activity-statistics-/i);
  assert.match(csvStatistics.body, /'=Statistics,/);
  assert.match(csvStatistics.body, /'\+quarterly/);
  assert.match(csvStatistics.body, /Site settings.*251/);
  const htmlStatistics = await statisticsDownload(statisticsReportId, "html");
  assert.equal(htmlStatistics.status, 200);
  assert.match(htmlStatistics.contentType, /text\/html/i);
  assert.match(htmlStatistics.securityPolicy, /default-src 'none'/);
  assert.match(htmlStatistics.body, /Statistics/);
  assert.match(htmlStatistics.body, /Site settings/);
  assert.doesNotMatch(htmlStatistics.body, /<img|onerror=|<script/i);

  const reportsNav = page.getByRole("navigation", { name: "Change Vigil", exact: true });
  await reportsNav.getByRole("link", { name: "Reports", exact: true }).click();
  await page.getByRole("heading", { name: "Activity statistics", exact: true }).waitFor();
  await page.getByRole("button", { name: "View saved result", exact: true }).click();
  const snapshotTable = page.getByRole("table", { name: /Saved statistics for/ });
  await snapshotTable.getByText("Site settings", { exact: true }).waitFor();
  assert.equal(await snapshotTable.getByText("251", { exact: true }).count(), 1);
  await page.locator(".cvsj-statistics-snapshot").scrollIntoViewIfNeeded();
  await page.screenshot({ path: ".tmp/network-parity-wp/saved-statistics-desktop.png", animations: "disabled" });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator(".cvsj-statistics-snapshot").scrollIntoViewIfNeeded();
  const snapshotBounds = await page.locator(".cvsj-statistics-snapshot").boundingBox();
  await page.screenshot({ path: ".tmp/network-parity-wp/saved-statistics-mobile.png", animations: "disabled" });
  assert.ok(snapshotBounds && snapshotBounds.x >= 0 && snapshotBounds.x + snapshotBounds.width <= 390, `The saved statistics view fits a narrow admin viewport, ${JSON.stringify(snapshotBounds)}.`);
  await page.setViewportSize({ width: 1440, height: 950 });
  const removedSnapshot = await api(`reports/saved/${statisticsReportId}/delete`, {});
  assert.equal(removedSnapshot.status, 200);
  statisticsReportId = null;

  const longRange = await page.evaluate(async ({ item, today }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + "reports/statistics");
    else url.pathname += "reports/statistics";
    for (const [key, value] of Object.entries({ item, from: "2000-01-01", to: today, group: "category", interval: "daily" })) url.searchParams.set(key, value);
    const response = await fetch(url, { headers: { "X-WP-Nonce": config.nonce }, cache: "no-store" });
    return { status: response.status, body: await response.json() };
  }, { item: fixture.item, today: statistics.today });
  assert.equal(longRange.status, 400, "Statistics must not exceed the site retention period");

  console.log("HTML reports, saved statistics snapshots, private CSV and HTML downloads, formula and markup safety, responsive output, and retention bounds passed");
} finally {
  if (reportFixture) {
    try {
      const cleanup = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "report_html_cleanup", item: reportFixture.item } });
      assert.equal(cleanup.status(), 200);
      assert.equal((await cleanup.json()).deleted, 251);
    } catch {}
  }
  if (statisticsReportId) {
    try { await api(`reports/saved/${statisticsReportId}/delete`, {}); } catch {}
  }
  await browser.close();
}
