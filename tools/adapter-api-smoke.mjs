import assert from "node:assert/strict";
import { randomBytes } from "node:crypto";
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
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();

  const marker = "API_FIXTURE_" + randomBytes(4).toString("hex").toUpperCase();
  const response = await page.request.post(base + "/alfa-fixture.php", { form: { operation: "developer_api", marker } });
  assert.equal(response.status(), 200, await response.text());
  const result = await response.json();
  for (const key of ["registryAvailable", "eventSaved", "actorAttributed", "sourceCaptured", "sensitiveDetailsDropped", "personalValuesRedacted", "unregisteredTypeRejected", "unknownFieldRejected", "disabledActionSuppressed", "disabledCategorySuppressed"]) {
    assert.equal(result[key], true, `Developer API check failed: ${key}`);
  }
  console.log("External event registration, safe event capture, attribution, redaction, filters, and opt out controls passed");
} finally {
  await browser.close();
}
