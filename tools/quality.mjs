import assert from "node:assert/strict";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, password, output = "test-results"] = process.argv.slice(2);
const { version } = JSON.parse(await readFile(new URL("../package.json", import.meta.url), "utf8"));
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const launchOptions = { headless: true };
if (process.env.PLAYWRIGHT_CHANNEL !== "chromium") launchOptions.channel = process.env.PLAYWRIGHT_CHANNEL ?? "chrome";
const browser = await chromium.launch(launchOptions);
const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
const page = await context.newPage();
page.setDefaultTimeout(12000);
const errors = [];
const dialogs = [];
const violations = [];
page.on("pageerror", error => errors.push(error.message));
page.on("dialog", async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });

async function signIn(tab, user, secret) {
  await tab.goto(base + "/wp-login.php");
  await tab.locator("#user_login").fill(user);
  await tab.locator("#user_pass").fill(secret);
  await tab.locator("#wp-submit").click();
  await tab.waitForLoadState("domcontentloaded");
}

async function fixture(operation) {
  const response = await context.request.post(base + "/alfa-fixture.php", { form: { operation } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

async function audit(label) {
  const result = await page.evaluate(async () => {
    const report = await axe.run(document.getElementById("alfa-root"), {
      runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "best-practice"] },
    });
    return report.violations.map(issue => ({
      id: issue.id,
      impact: issue.impact,
      help: issue.help,
      nodes: issue.nodes.map(node => ({
        target: node.target,
        failure: node.failureSummary,
        contrast: node.any?.find(check => check.id === "color-contrast")?.data,
      })),
    }));
  });
  violations.push({ label, result });
  assert.deepEqual(result, [], `${label} accessibility findings: ${JSON.stringify(result)}`);
}

function endpoint(apiBase, path) {
  const url = new URL(apiBase);
  if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
  else url.pathname += path;
  url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
  return url;
}

function assertPrivateNoStore(headers, label) {
  const cacheControl = headers["cache-control"] ?? "";
  assert.match(cacheControl, /\bprivate\b/i, `${label} response must be private`);
  assert.match(cacheControl, /\bno-store\b/i, `${label} response must not be stored`);
  assert.doesNotMatch(cacheControl, /\bpublic\b/i, `${label} response must not be public`);
}

try {
  await mkdir(output, { recursive: true });
  await context.addInitScript({ path: "node_modules/axe-core/axe.min.js" });
  await signIn(page, "pilotadmin", password);
  const adminScreen = base + "/wp-admin/admin.php?page=activity-log-for-admins";
  await page.goto(base + "/wp-admin/plugins.php");
  const pluginRow = page.locator('tr[data-plugin="activity-log-for-admins/activity-log-for-admins.php"]');
  const activate = pluginRow.locator(".activate a");
  if (await activate.count()) await activate.click();
  await page.goto(adminScreen);
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();

  await fixture("reset");
  const seeded = await fixture("events");
  await fixture("seed");
  await fixture("xss");
  const apiBase = await page.evaluate(() => window.alfaSettings.base);
  const nonce = await page.evaluate(() => window.alfaSettings.nonce);
  const anonymous = await browser.newContext();
  const anonymousEvents = await anonymous.request.get(endpoint(apiBase, "events").href);
  assert.equal(anonymousEvents.status(), 401, "Anonymous users must not read activity.");
  const anonymousExport = await anonymous.request.get(endpoint(apiBase, "export").href);
  assert.equal(anonymousExport.status(), 401, "Anonymous users must not export activity.");
  await anonymous.close();

  const adminWithoutNonce = await context.request.get(endpoint(apiBase, "events").href);
  assert.equal(adminWithoutNonce.status(), 401, "Cookie requests without a REST nonce must be rejected.");
  const adminSettingsWithoutNonce = await context.request.post(endpoint(apiBase, "settings").href, {
    data: { retention: 30, erase: false },
  });
  assert.equal(adminSettingsWithoutNonce.status(), 401, "Settings changes require a REST nonce.");

  const activityResponse = await context.request.get(endpoint(apiBase, "events").href, { headers: { "X-WP-Nonce": nonce } });
  assert.equal(activityResponse.status(), 200);
  assertPrivateNoStore(activityResponse.headers(), "Activity list");
  const activityPayload = await activityResponse.json();
  assert.ok(activityPayload.events.length > 0, "The Activity list must return the seeded events.");
  const eventDetailsResponse = await context.request.get(endpoint(apiBase, `events/${activityPayload.events[0].id}`).href, { headers: { "X-WP-Nonce": nonce } });
  assert.equal(eventDetailsResponse.status(), 200);
  assertPrivateNoStore(eventDetailsResponse.headers(), "Event details");
  const settingsResponse = await context.request.get(endpoint(apiBase, "settings").href, { headers: { "X-WP-Nonce": nonce } });
  assert.equal(settingsResponse.status(), 200);
  assertPrivateNoStore(settingsResponse.headers(), "Settings");

  const exportUrl = endpoint(apiBase, "export");
  exportUrl.searchParams.set("_wpnonce", nonce);
  const exportResponse = await context.request.get(exportUrl.href);
  assert.equal(exportResponse.status(), 200);
  const headers = exportResponse.headers();
  assert.match(headers["content-type"], /text\/csv; charset=UTF-8/i);
  assert.match(headers["content-disposition"], /attachment; filename="activity-log-/i);
  assert.equal(headers["x-content-type-options"], "nosniff");
  assertPrivateNoStore(headers, "CSV export");
  const exportedCsv = (await exportResponse.body()).toString("utf8");
  assert.ok(exportedCsv.includes("Event ID"));
  assert.ok(exportedCsv.includes("'=1+1"), "CSV cells that begin with formulas must be neutralized.");

  const injection = endpoint(apiBase, "events");
  injection.searchParams.set("actor", "0' OR 1=1 --");
  injection.searchParams.set("q", "%') OR 1=1 --");
  assert.equal((await context.request.get(injection.href, { headers: { "X-WP-Nonce": nonce } })).status(), 200);
  const invalidDate = endpoint(apiBase, "events");
  invalidDate.searchParams.set("from", "2026-02-30");
  assert.equal((await context.request.get(invalidDate.href, { headers: { "X-WP-Nonce": nonce } })).status(), 400);

  const editor = await browser.newContext();
  const editorPage = await editor.newPage();
  await signIn(editorPage, "piloteditor", seeded.editorPassword);
  const editorNonceResponse = await editor.request.post(base + "/alfa-fixture.php", { form: { operation: "nonce" } });
  assert.equal(editorNonceResponse.status(), 200);
  const editorNonce = (await editorNonceResponse.json()).nonce;
  const editorRead = await editor.request.get(endpoint(apiBase, "events").href, { headers: { "X-WP-Nonce": editorNonce } });
  const editorExport = await editor.request.get(endpoint(apiBase, "export").href, { headers: { "X-WP-Nonce": editorNonce } });
  const editorSettings = await editor.request.post(endpoint(apiBase, "settings").href, {
    headers: { "X-WP-Nonce": editorNonce },
    data: { retention: 30, erase: false },
  });
  assert.deepEqual([editorRead.status(), editorExport.status(), editorSettings.status()], [403, 403, 403]);
  await editor.close();

  for (const width of [320, 390, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 950 });
    await page.goto(adminScreen);
    await page.getByRole("table", { name: "Activity events" }).waitFor();
    await page.waitForFunction(() => document.querySelector(".alfa-results p")?.textContent !== "Activity events");
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Activity view overflows at ${width}px.`);
    await audit(`Activity at ${width}px`);
    if (width === 320 || width === 1440) {
      await page.getByRole("button", { name: /Filters/ }).click();
      await audit(`Filters at ${width}px`);
      await page.getByRole("button", { name: /Filters/ }).click();
    }
  }

  await page.setViewportSize({ width: 320, height: 844 });
  await page.goto(adminScreen);
  await page.getByRole("button", { name: /XSS test probe/ }).waitFor();
  assert.equal(await page.locator("#alfa-root img, #alfa-root script, #alfa-root svg[onload]").count(), 0);
  const xssEvent = page.getByRole("button", { name: /XSS test probe/ });
  await xssEvent.click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("<script>alert(1)</script>", { exact: true }).waitFor();
  await page.waitForTimeout(250);
  assert.equal(await page.locator("#alfa-root script, #alfa-root img, #alfa-root svg[onload]").count(), 0);
  assert.deepEqual(dialogs, [], "Stored text must not execute as markup.");
  await audit("Event details at 320px");
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), "Event details overflow at 320px.");
  await page.screenshot({ path: output + "/activity-details-320.png", animations: "disabled" });
  await page.keyboard.press("Escape");

  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 950 });
    await page.goto(adminScreen + "&view=settings");
    await page.getByText(`Activity Log for Admins · Version ${version}`).waitFor();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Settings view overflows at ${width}px.`);
    await audit(`Settings at ${width}px`);
  }

  assert.deepEqual(errors, [], `Browser errors: ${JSON.stringify(errors)}`);
  const report = {
    version,
    security: {
      anonymousRead: anonymousEvents.status(),
      anonymousExport: anonymousExport.status(),
      activityCacheControl: activityResponse.headers()["cache-control"],
      eventDetailsCacheControl: eventDetailsResponse.headers()["cache-control"],
      settingsCacheControl: settingsResponse.headers()["cache-control"],
      exportCacheControl: headers["cache-control"],
      adminReadWithoutNonce: adminWithoutNonce.status(),
      adminSettingsWithoutNonce: adminSettingsWithoutNonce.status(),
      editorRead: editorRead.status(),
      editorExport: editorExport.status(),
      editorSettings: editorSettings.status(),
      adminExport: exportResponse.status(),
      formulaProtection: exportedCsv.includes("'=1+1"),
      injectionStatus: 200,
      invalidDateStatus: 400,
      storedXssRenderedAsText: true,
    },
    accessibility: violations,
    responsiveWidths: [320, 390, 768, 1024, 1440],
    browserErrors: errors,
  };
  await writeFile(output + "/security-ui-report.json", JSON.stringify(report, null, 2));
  console.log(JSON.stringify({
    anonymous: report.security.anonymousRead,
    editor: [report.security.editorRead, report.security.editorExport, report.security.editorSettings],
    axeScans: violations.length,
    responsiveWidths: report.responsiveWidths,
    browserErrors: errors.length,
    report: output + "/security-ui-report.json",
  }, null, 2));
} finally {
  await browser.close();
}
