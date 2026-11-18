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

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();

  const response = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "privacy_roundtrip" } });
  assert.equal(response.status(), 200, await response.text());
  const result = await response.json();
  assert.equal(result.ok, true);
  assert.equal(result.exporterRegistered, true);
  assert.equal(result.eraserRegistered, true);
  assert.equal(result.editorDenied, true);
  assert.ok(result.exportedEvents >= 253);
  assert.ok(result.exportedNotes >= 1);
  assert.ok(result.exportPages >= 2, "Export should paginate a person's activity");
  assert.ok(result.erasePages >= 2, "Erasure should use bounded batches");
  assert.equal(result.eventsAnonymized, 251);
  assert.equal(result.actorSettingsRemoved, true);
  assert.equal(result.savedReportCleaned, true);
  assert.equal(result.scheduledRecipientsRemoved, true);
  assert.ok(result.reportHistoryExported >= 1, "Privacy export should include linked generated report history");
  assert.equal(result.statisticsSnapshotExported, true, "Privacy export should include a saved statistics result linked to the account.");
  assert.ok(result.alertRuleExported >= 1, "Privacy export should include configured alert recipient data");
  assert.equal(result.alertRecipientRemoved, true, "Privacy erasure must remove the person's address from alert rules");
  assert.equal(result.pendingCustomAlertsCleared, true, "Privacy erasure must clear queued event notifications");
  assert.equal(result.retryGenerationAdvanced, true, "Privacy erasure must invalidate scheduled event retries");
  assert.equal(result.staleRetryIgnored, true, "A scheduled retry from before erasure must not requeue personal event data");
  assert.equal(result.alertDeliveryResumed, true, "Alert delivery should resume after complete erasure");
  assert.equal(result.activeJobCancelled, true);
  assert.equal(result.activeHtmlAttachmentRemoved, true, "Privacy erasure must remove an active HTML attachment");
  assert.equal(result.archivedReportCopyRemoved, true, "Privacy erasure must remove private archived report copies");
  assert.equal(result.eventNotesRemoved, true);
  assert.equal(result.emailReferencesRedacted, true);
  assert.equal(result.pendingJobCleared, true);
  console.log("Native privacy export, bounded erasure, anonymized events, notes, recipient cleanup, stale alert retry rejection, report cleanup, and editor denial passed");
} finally {
  await browser.close();
}
