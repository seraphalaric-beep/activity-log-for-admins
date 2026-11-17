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
let fixture = null;
let templateId = null;
let scheduleId = null;
let originalSettings = null;
let editorContext = null;
const historyIds = [];

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

  const seeded = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_seed" } });
  assert.equal(seeded.status(), 200, await seeded.text());
  fixture = await seeded.json();
  assert.equal(fixture.count, 1001);

  const settings = await api("settings");
  assert.equal(settings.status, 200);
  originalSettings = settings.body.settings;
  const template = await api("reports/saved", {
    name: "Scheduled report pilot",
    filters: { item: fixture.item },
    group: "category",
    interval: "daily",
    range_days: 1,
    columns: ["event_id", "summary"],
    branding: true,
  });
  assert.equal(template.status, 200);
  templateId = template.body.report.id;

  const invalid = await api("reports/schedules", {
    template_id: templateId,
    cadence: "weekly",
    hour: 9,
    recipients: ["not an email"],
  });
  assert.equal(invalid.status, 400, "Scheduled reports must reject invalid recipient addresses");
  const invalidFormat = await api("reports/schedules", {
    template_id: templateId,
    cadence: "weekly",
    hour: 9,
    format: "xml",
    recipients: ["qa@example.test"],
  });
  assert.equal(invalidFormat.status, 400, "Scheduled reports must reject unsupported attachment formats");

  const created = await api("reports/schedules", {
    template_id: templateId,
    cadence: "quarterly",
    hour: 9,
    recipients: ["qa@example.test"],
  });
  assert.equal(created.status, 200);
  scheduleId = created.body.id;
  assert.equal(created.body.cadence, "quarterly");
  assert.equal(created.body.format, "csv", "CSV remains the default attachment format");
  assert.deepEqual(created.body.recipients, ["qa@example.test"]);

  const noEditorAccess = await api("settings", { ...originalSettings, viewer_roles: [], manager_roles: [], viewer_users: [], manager_users: [] });
  assert.equal(noEditorAccess.status, 200);
  const editorSeed = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "access_user" } });
  assert.equal(editorSeed.status(), 200);
  const editorPassword = (await editorSeed.json()).editorPassword;
  editorContext = await browser.newContext();
  const editorPage = await editorContext.newPage();
  await editorPage.goto(base + "/wp-login.php");
  await editorPage.locator("#user_login").fill("piloteditor");
  await editorPage.locator("#user_pass").fill(editorPassword);
  await editorPage.locator("#wp-submit").click();
  await editorPage.waitForLoadState("domcontentloaded");
  const editorNonceResponse = await editorContext.request.post(base + "/alfa-fixture.php", { form: { operation: "nonce" } });
  assert.equal(editorNonceResponse.status(), 200);
  const editorBase = await page.evaluate(() => window.alfaSettings.base);
  const editorNonce = (await editorNonceResponse.json()).nonce;
  const schedulesUrl = new URL(editorBase);
  if (schedulesUrl.searchParams.has("rest_route")) schedulesUrl.searchParams.set("rest_route", schedulesUrl.searchParams.get("rest_route") + "reports/schedules");
  else schedulesUrl.pathname += "reports/schedules";
  assert.equal((await editorContext.request.get(schedulesUrl.href, { headers: { "X-WP-Nonce": editorNonce } })).status(), 403, "Editors must not read scheduled recipients");
  assert.equal((await editorContext.request.post(schedulesUrl.href, {
    headers: { "X-WP-Nonce": editorNonce },
    data: { template_id: templateId, cadence: "daily", hour: 9, recipients: ["editor@example.test"] },
  })).status(), 403, "Editors must not create scheduled email reports");
  const restoredSettings = await api("settings", originalSettings);
  assert.equal(restoredSettings.status, 200);

  const listed = await api("reports/schedules");
  assert.equal(listed.status, 200);
  assert.equal(listed.body.schedules.some(schedule => schedule.id === scheduleId && schedule.recipients[0] === "qa@example.test" && schedule.format === "csv"), true);
  const exported = await api("settings/export");
  assert.equal(exported.status, 200);
  assert.equal("report_schedules" in exported.body.settings, false);
  assert.equal("email_alert_recipients" in exported.body.settings, false);

  const due = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_due", id: scheduleId } });
  assert.equal(due.status(), 200, await due.text());
  const run = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_run" } });
  assert.equal(run.status(), 200, await run.text());
  const result = await run.json();
  assert.equal(result.job_remaining, false);
  assert.equal(result.mail_attempts, 1);
  assert.equal(result.path_exists_after, false, "The private attachment must be deleted after delivery");
  assert.ok(result.mail);
  assert.equal(result.mail.inside_wordpress, false, "The report attachment must stay outside the public WordPress directory");
  assert.match(result.mail.path, /\.csv$/);
  assert.deepEqual(result.mail.to, ["qa@example.test"]);
  assert.match(result.mail.subject, /Scheduled activity report: Scheduled report pilot/);
  assert.match(result.mail.attachment, /SCHEDULED_REPORT_ROW_1001/);
  assert.match(result.mail.attachment, /'=1\+1/, "Spreadsheet formulas must be treated as text in emailed CSV");
  assert.equal(result.mail.attachment.split(/\r?\n/).filter(Boolean).length, 1006, "The attachment must contain all 1001 events and its five metadata rows");
  assert.doesNotMatch(result.mail.attachment, /Actor role|Affected item|Change details/, "Only fields selected in the saved template belong in the report");

  const delivered = await api("reports/schedules");
  const csvHistoryEntry = delivered.body.history.find(entry => entry.format === "csv");
  if (csvHistoryEntry) historyIds.push(csvHistoryEntry.id);
  const schedule = delivered.body.schedules.find(item => item.id === scheduleId);
  assert.equal(schedule.last_status, "sent");
  assert.equal(delivered.body.history[0].event_count, 1001);
  assert.equal(delivered.body.history[0].status, "sent");
  assert.equal(delivered.body.history[0].format, "csv");

  const removed = await api(`reports/schedules/${scheduleId}/delete`, {});
  assert.deepEqual(removed, { status: 200, body: { deleted: true } });
  scheduleId = null;

  const htmlCreated = await api("reports/schedules", {
    template_id: templateId,
    cadence: "monthly",
    hour: 9,
    format: "html",
    recipients: ["html@example.test"],
  });
  assert.equal(htmlCreated.status, 200);
  scheduleId = htmlCreated.body.id;
  assert.equal(htmlCreated.body.format, "html");

  const htmlDue = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_due", id: scheduleId } });
  assert.equal(htmlDue.status(), 200, await htmlDue.text());
  const htmlRun = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_run" } });
  assert.equal(htmlRun.status(), 200, await htmlRun.text());
  const htmlResult = await htmlRun.json();
  assert.equal(htmlResult.job_remaining, false);
  assert.equal(htmlResult.mail_attempts, 1);
  assert.equal(htmlResult.path_exists_after, false, "The private HTML attachment must be deleted after delivery");
  assert.ok(htmlResult.mail);
  assert.equal(htmlResult.mail.inside_wordpress, false);
  assert.match(htmlResult.mail.path, /\.html$/);
  assert.deepEqual(htmlResult.mail.to, ["html@example.test"]);
  assert.match(htmlResult.mail.body, /HTML file/);
  assert.match(htmlResult.mail.attachment, /^<!doctype html>/i);
  assert.match(htmlResult.mail.attachment, /SCHEDULED_REPORT_ROW_1001/);
  assert.match(htmlResult.mail.attachment, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(htmlResult.mail.attachment, /<script|<img/i, "Recorded markup must remain escaped in the scheduled HTML attachment");
  assert.match(htmlResult.mail.attachment, /<\/tbody><\/table><\/main><\/body><\/html>$/i);
  assert.equal((htmlResult.mail.attachment.match(/<\/html>/gi) ?? []).length, 1, "The HTML document must close once after all background batches");
  assert.equal((htmlResult.mail.attachment.match(/<tr>/gi) ?? []).length, 1002, "The HTML attachment must contain all 1001 events and one header row");
  assert.doesNotMatch(htmlResult.mail.attachment, /Actor role|Affected item|Change details/, "Only fields selected in the saved template belong in the HTML report");

  const htmlDelivered = await api("reports/schedules");
  const htmlSchedule = htmlDelivered.body.schedules.find(item => item.id === scheduleId);
  assert.equal(htmlSchedule.format, "html");
  assert.equal(htmlSchedule.last_status, "sent");
  assert.equal(htmlDelivered.body.history[0].format, "html");
  assert.equal(htmlDelivered.body.history[0].event_count, 1001);

  const csvHistory = htmlDelivered.body.history.find(entry => entry.format === "csv");
  const htmlHistory = htmlDelivered.body.history.find(entry => entry.format === "html");
  if (htmlHistory) historyIds.push(htmlHistory.id);
  assert.ok(csvHistory?.download_url, "CSV history should expose an authorized download link");
  assert.ok(htmlHistory?.download_url, "HTML history should expose an authorized download link");
  assert.equal("file" in csvHistory, false, "Private server filenames must not be exposed to the browser");
  const csvCopy = await page.request.get(csvHistory.download_url);
  assert.equal(csvCopy.status(), 200);
  assert.match(csvCopy.headers()["content-type"], /text\/csv/i);
  assert.match(await csvCopy.text(), /SCHEDULED_REPORT_ROW_1001/);
  const htmlCopy = await page.request.get(htmlHistory.download_url);
  assert.equal(htmlCopy.status(), 200);
  assert.match(htmlCopy.headers()["content-type"], /text\/html/i);
  assert.match(await htmlCopy.text(), /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);

  const editorHistoryDownload = await editorContext.request.get(csvHistory.download_url);
  assert.equal(editorHistoryDownload.status(), 403, "Editors must not download private report history");
  const editorHistoryNonceResponse = await editorContext.request.post(base + "/alfa-fixture.php", { form: { operation: "nonce" } });
  assert.equal(editorHistoryNonceResponse.status(), 200);
  const editorHistoryNonce = (await editorHistoryNonceResponse.json()).nonce;
  const editorDeleteUrl = new URL(editorBase);
  if (editorDeleteUrl.searchParams.has("rest_route")) editorDeleteUrl.searchParams.set("rest_route", editorDeleteUrl.searchParams.get("rest_route") + `reports/history/${csvHistory.id}/delete`);
  else editorDeleteUrl.pathname += `reports/history/${csvHistory.id}/delete`;
  assert.equal((await editorContext.request.post(editorDeleteUrl.href, {
    headers: { "X-WP-Nonce": editorHistoryNonce }, data: {},
  })).status(), 403, "Editors must not delete private report history");

  const expiredCopy = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_expire_history", id: csvHistory.id } });
  assert.equal(expiredCopy.status(), 200, await expiredCopy.text());
  assert.equal((await expiredCopy.json()).expired, true, "Report copies must be removed when site retention expires");
  historyIds.splice(historyIds.indexOf(csvHistory.id), 1);
  const deletedHistory = await api(`reports/history/${htmlHistory.id}/delete`, {});
  assert.deepEqual(deletedHistory, { status: 200, body: { deleted: true } });
  historyIds.splice(historyIds.indexOf(htmlHistory.id), 1);
  historyIds.length = 0;
  const remainingHistory = await api("reports/schedules");
  assert.equal(remainingHistory.body.history.some(entry => entry.id === csvHistory.id || entry.id === htmlHistory.id), false);

  const htmlRemoved = await api(`reports/schedules/${scheduleId}/delete`, {});
  assert.deepEqual(htmlRemoved, { status: 200, body: { deleted: true } });
  scheduleId = null;

  const retryCreated = await api("reports/schedules", {
    template_id: templateId,
    cadence: "weekly",
    hour: 9,
    format: "csv",
    recipients: ["retry@example.test"],
  });
  assert.equal(retryCreated.status, 200);
  scheduleId = retryCreated.body.id;
  const retryDue = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_due", id: scheduleId } });
  assert.equal(retryDue.status(), 200, await retryDue.text());
  const retryRun = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_run", fail_attempts: "2" } });
  assert.equal(retryRun.status(), 200, await retryRun.text());
  const retryResult = await retryRun.json();
  assert.equal(retryResult.job_remaining, false);
  assert.equal(retryResult.mail_attempts, 3, "Delivery should retry twice before succeeding");
  assert.equal(retryResult.retry_delays.length, 2);
  assert.ok(retryResult.retry_delays[0] >= 240 && retryResult.retry_delays[0] <= 300, "The first retry should use a five minute delay");
  assert.ok(retryResult.retry_delays[1] >= 840 && retryResult.retry_delays[1] <= 900, "The second retry should use a fifteen minute delay");
  assert.deepEqual(retryResult.mail.to, ["retry@example.test"]);
  assert.match(retryResult.mail.attachment, /SCHEDULED_REPORT_ROW_1001/);
  const retryDelivered = await api("reports/schedules");
  const retrySchedule = retryDelivered.body.schedules.find(item => item.id === scheduleId);
  assert.equal(retrySchedule.last_status, "sent");
  const retryHistory = retryDelivered.body.history[0];
  assert.equal(retryHistory.status, "sent");
  assert.equal(retryHistory.event_count, 1001);
  assert.ok(retryHistory.download_url);
  historyIds.push(retryHistory.id);
  const retryHistoryRemoved = await api(`reports/history/${retryHistory.id}/delete`, {});
  assert.deepEqual(retryHistoryRemoved, { status: 200, body: { deleted: true } });
  historyIds.length = 0;
  const retryScheduleRemoved = await api(`reports/schedules/${scheduleId}/delete`, {});
  assert.deepEqual(retryScheduleRemoved, { status: 200, body: { deleted: true } });
  scheduleId = null;

  const templateRemoved = await api(`reports/saved/${templateId}/delete`, {});
  assert.deepEqual(templateRemoved, { status: 200, body: { deleted: true } });
  templateId = null;
  console.log("Scheduled report authorization, recipient and format validation, batched CSV and HTML delivery, bounded retries, private downloadable history, retention cleanup, privacy erasure, and editor denial passed");
} finally {
  if (editorContext) await editorContext.close();
  if (originalSettings) {
    try { await api("settings", originalSettings); } catch {}
  }
  if (scheduleId) {
    try { await api(`reports/schedules/${scheduleId}/delete`, {}); } catch {}
  }
  for (const id of historyIds) {
    try { await api(`reports/history/${id}/delete`, {}); } catch {}
  }
  if (templateId) {
    try { await api(`reports/saved/${templateId}/delete`, {}); } catch {}
  }
  if (fixture) {
    try {
      const cleanup = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "scheduled_report_cleanup", item_key: fixture.item_key } });
      assert.equal(cleanup.status(), 200);
      assert.equal((await cleanup.json()).deleted, 1001);
    } catch {}
  }
  await browser.close();
}
