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

let bbpressInitiallyActive = false;
let originalSettings = null;
try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");

  await page.goto(base + "/wp-admin/plugins.php");
  const bbpressRow = page.locator('tr[data-plugin="bbpress/bbpress.php"]');
  await bbpressRow.waitFor();
  bbpressInitiallyActive = 0 < await bbpressRow.locator(".deactivate a").count();
  if (!bbpressInitiallyActive) {
    await bbpressRow.locator(".activate a").click();
    await page.waitForLoadState("domcontentloaded");
  }

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  await fixture("reset");
  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  originalSettings = defaults.data.settings;
  assert.equal(defaults.data.settings.bbpress, false, "bbPress recording must be opt in");
  const disabled = await fixture("bbpress_disabled");
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, "bbPress activity must stay out of the log while its integration is off");

  assert.equal((await api("settings", { data: { ...defaults.data.settings, bbpress: true } })).status, 200);
  await page.reload();
  const probe = await fixture("bbpress");
  assert.equal(probe.supported, true);
  for (const action of ["forum_created", "forum_edited", "forum_status_changed", "forum_trashed", "forum_restored", "forum_deleted"]) {
    assert.ok(probe.forumActions.includes(action), `Missing forum event ${action}`);
  }
  for (const action of ["topic_created", "topic_edited", "topic_status_changed", "topic_trashed", "topic_restored", "topic_deleted"]) {
    assert.ok(probe.topicActions.includes(action), `Missing topic event ${action}`);
  }
  assert.ok(probe.topicActions.includes("topic_stickied"), "Missing topic sticky event");
  assert.ok(probe.topicActions.includes("topic_unstickied"), "Missing topic unsticky event");
  for (const action of ["reply_created", "reply_edited", "reply_status_changed", "reply_trashed", "reply_restored", "reply_deleted"]) {
    assert.ok(probe.replyActions.includes(action), `Missing reply event ${action}`);
  }
  assert.equal(probe.unchangedIgnored, true, "Unchanged reply saves must not be recorded");
  assert.equal(probe.anonymousActorCaptured, true, "A guest authored reply must identify the actor as anonymous");
  assert.equal(probe.privateValuesRedacted, true, "Forum text, reply text, guest contact details, and IP addresses must not be recorded");
  assert.ok(probe.forumActions.filter(action => action === "forum_status_changed").length >= 4, "Forum open and close state changes must be distinct from post visibility changes");
  assert.ok(probe.forumActions.filter(action => action === "forum_edited").length >= 3, "Forum type changes must be recorded alongside title edits");
  assert.ok(probe.topicActions.filter(action => action === "topic_status_changed").length >= 4, "Topic close, reopen, and post status changes must be captured");
  assert.ok(probe.replyActions.filter(action => action === "reply_status_changed").length >= 4, "Reply moderation and post status changes must be captured");

  const replyEvents = await api("events", { query: { item: `reply:${probe.replyId}`, category: "bbpress" } });
  assert.equal(replyEvents.status, 200);
  const editedReply = replyEvents.data.events.find(event => event.action_key === "reply_edited");
  assert.ok(editedReply, "The reply edit must be listed through REST");
  const detail = await api(`events/${editedReply.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.category, "bbpress");
  assert.deepEqual(detail.data.details.changes.Content, ["Not recorded", "Changed"]);
  const csv = await api("export", { query: { category: "bbpress" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("reply_edited"));
  assert.doesNotMatch(csv.data, /PRIVATE_BBPRESS_|guest-[a-z0-9]+@example\.invalid|198\.51\.100\.42/);

  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=bbpress&q=" + encodeURIComponent(`bbPress reply #${probe.replyId}`));
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  await page.getByRole("button", { name: /Edited bbPress reply/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("Content", { exact: true }).waitFor();
  await dialog.getByText("Changed", { exact: true }).waitFor();
  await dialog.getByText(/PRIVATE_BBPRESS_/).waitFor({ state: "hidden" });
  await page.screenshot({ path: ".tmp/bbpress-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Activity Log" }).getByRole("link", { name: "Settings", exact: true }).click();
  const integration = page.getByRole("checkbox", { name: /Record forum, topic, and reply changes/ });
  await integration.waitFor();
  assert.equal(await integration.isChecked(), true);
  await page.getByText(/Forum and reply text, guest contact details, and IP addresses are never stored/i).waitFor();
  await page.screenshot({ path: ".tmp/bbpress-settings.png", fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins&category=bbpress");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/bbpress-mobile.png", fullPage: true });
  assert.equal((await api("settings", { data: defaults.data.settings })).status, 200);

  console.log("bbPress opt in, forum, topic, reply, privacy, unchanged save, REST, CSV, detail panel, settings, and narrow layout checks passed");
} finally {
  if (originalSettings) {
    await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
    await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
    await api("settings", { data: originalSettings });
  }
  if (!bbpressInitiallyActive) {
    await page.goto(base + "/wp-admin/plugins.php");
    const restoreRow = page.locator('tr[data-plugin="bbpress/bbpress.php"]');
    await restoreRow.waitFor();
    const deactivate = restoreRow.locator(".deactivate a");
    if (await deactivate.count()) {
      await deactivate.click();
      await page.waitForLoadState("domcontentloaded");
    }
  }
  await browser.close();
}
