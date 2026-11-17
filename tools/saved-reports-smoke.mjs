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
let savedId = null;

async function api(path, data) {
  return page.evaluate(async ({ path, data }) => {
    const config = window.alfaSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    url.searchParams.set("_alfa_request", Date.now() + "-" + Math.random().toString(36).slice(2));
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    return { status: response.status, body: await response.json().catch(() => null) };
  }, { path, data });
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();

  const settings = await api("settings");
  assert.equal(settings.status, 200);
  const template = await api("reports/saved", {
    name: "Quarterly access review",
    filters: { actor_role: "administrator", from: "2026-10-01", to: "2026-10-31" },
    group: "actor_role",
    interval: "monthly",
    range_days: Math.min(30, settings.body.settings.retention),
    columns: ["event_id", "time", "summary"],
    branding: true,
    tags: ["access review", "quarterly"],
  });
  assert.equal(template.status, 200);
  savedId = template.body.report.id;
  assert.match(savedId, /^[0-9a-f-]{36}$/i);
  assert.equal(template.body.report.filters.actor_role, "administrator");
  assert.equal("from" in template.body.report.filters, false);
  assert.equal("to" in template.body.report.filters, false);
  assert.deepEqual(template.body.report.columns, ["event_id", "time", "summary"]);
  assert.equal(template.body.report.branding, true);
  assert.deepEqual(template.body.report.tags, ["access review", "quarterly"]);

  const listed = await api("reports/saved");
  assert.equal(listed.status, 200);
  assert.equal(listed.body.reports.some(report => report.id === savedId && report.name === "Quarterly access review"
    && report.columns.join(",") === "event_id,time,summary" && report.branding
    && report.tags.join(",") === "access review,quarterly"), true);
  const settingsExport = await api("settings/export");
  assert.equal(settingsExport.status, 200);
  assert.equal("saved_reports" in settingsExport.body.settings, false);
  assert.equal("email_alert_recipients" in settingsExport.body.settings, false);

  if (settings.body.settings.retention < 3650) {
    const excessiveRange = await api("reports/saved", {
      name: "Too long report",
      filters: {},
      group: "category",
      interval: "daily",
      range_days: settings.body.settings.retention + 1,
    });
    assert.equal(excessiveRange.status, 400, "Saved date ranges must fit the site's selected retention period");
  }
  const invalidFilter = await api("reports/saved", {
    name: "Invalid report",
    filters: { secret: "value" },
    group: "category",
    interval: "daily",
    range_days: 1,
  });
  assert.equal(invalidFilter.status, 400, "Saved templates must reject unknown filters");

  const invalidColumns = await api("reports/saved", {
    name: "Invalid fields",
    filters: {},
    group: "category",
    interval: "daily",
    range_days: 1,
    columns: ["secret"],
  });
  assert.equal(invalidColumns.status, 400, "Saved reports must reject fields outside the report allowlist");

  const tooManyTags = await api("reports/saved", {
    name: "Too many tags",
    filters: {},
    group: "category",
    interval: "daily",
    range_days: 1,
    tags: ["one", "two", "three", "four", "five", "six"],
  });
  assert.equal(tooManyTags.status, 400, "Saved reports must limit the number of tags");

  const removed = await api("reports/saved/" + savedId + "/delete", {});
  assert.deepEqual(removed, { status: 200, body: { deleted: true } });
  savedId = null;
  const afterDelete = await api("reports/saved");
  assert.equal(afterDelete.body.reports.length, 0);
  console.log("Saved report access, local retention, validated filters, fields and tags, relative date range, optional branding, export privacy, and deletion passed");
} finally {
  if (savedId) {
    try { await api("reports/saved/" + savedId + "/delete", {}); } catch {}
  }
  await browser.close();
}
