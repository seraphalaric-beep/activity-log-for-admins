import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base, passwordArgument] = process.argv.slice(2);
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : passwordArgument);
if (!base || !password || new URL(base).hostname !== "127.0.0.1") throw new Error("Pass a disposable loopback URL and its pilot password.");

const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const page = await browser.newPage();
page.setDefaultTimeout(12000);

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  const response = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "component_lifecycle" } });
  assert.equal(response.status(), 200, await response.text());
  const result = await response.json();
  const restResult = await page.evaluate(async themeSlug => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + "events");
    else url.pathname += "events";
    url.searchParams.set("action", "theme_installed");
    url.searchParams.set("item", `theme:${themeSlug}`);
    url.searchParams.set("_cvsj_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, { headers: { "X-WP-Nonce": config.nonce } });
    return { status: response.status, events: (await response.json()).events ?? [] };
  }, result.themeSlug);
  console.log(JSON.stringify({ ...result, restStatus: restResult.status, restThemeEvents: restResult.events.length }, null, 2));
  for (const action of ["theme_installed", "theme_switched", "theme_updated", "theme_deleted"]) {
    assert.ok(result.themeEventActions.includes(action), `Expected ${action} for the fixture theme`);
  }
  assert.equal(restResult.status, 200);
  assert.ok(restResult.events.length, "The REST event filter must return theme installation events");
  console.log("Theme install, switch, update, and delete events passed.");
} finally {
  await browser.close();
}
