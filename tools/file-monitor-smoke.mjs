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

async function api(path, data, query = {}) {
  return page.evaluate(async ({ path, data, query }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
    url.searchParams.set("_cvsj_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    const contentType = response.headers.get("content-type") ?? "";
    return { status: response.status, data: contentType.includes("application/json") ? await response.json() : await response.text() };
  }, { path, data, query });
}

async function fixture(operation) {
  const response = await page.request.post(base + "/cvsj-fixture.php", { form: { operation } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  const screen = base + "/wp-admin/admin.php?page=change-vigil-site-journal";
  await page.goto(screen);
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();

  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  assert.equal(defaults.data.settings.capture_files, false);
  assert.equal(defaults.data.settings.monitor_uploads, false);
  assert.equal(defaults.data.settings.file_max_size_mb, 10);
  assert.equal(defaults.data.settings.file_scan_interval, "daily");
  assert.deepEqual(defaults.data.settings.file_excluded_paths, []);
  const exportedSettings = await api("settings/export");
  const legacyExport = structuredClone(exportedSettings.data);
  for (const key of ["capture_files", "monitor_uploads", "file_max_size_mb", "file_scan_interval", "file_excluded_paths"]) delete legacyExport.settings[key];
  const legacyImport = await api("settings/import", legacyExport);
  assert.equal(legacyImport.status, 200);
  const unsafeExport = structuredClone(exportedSettings.data);
  unsafeExport.settings.file_excluded_paths = ["../private"];
  assert.equal((await api("settings/import", unsafeExport)).status, 400);

  await page.getByRole("navigation", { name: "Change Vigil" }).getByRole("link", { name: "Settings", exact: true }).click();
  await page.getByText(/Version 1\.1\.0/).waitFor();
  const fileToggle = page.getByRole("checkbox", { name: /File changes/ });
  const uploadsToggle = page.getByRole("checkbox", { name: /Include uploads/ });
  assert.equal(await fileToggle.isChecked(), false);
  assert.equal(await uploadsToggle.isChecked(), false);
  const exclusions = page.getByLabel("Additional excluded paths");
  assert.equal(await exclusions.isDisabled(), true);
  await page.screenshot({ path: ".tmp/file-monitor-settings.png", fullPage: true });
  await fileToggle.check();
  await page.getByRole("button", { name: "Save settings" }).click();
  await page.getByText("Settings saved.", { exact: true }).waitFor();
  assert.equal((await api("settings")).data.settings.capture_files, true);
  assert.equal(await exclusions.isDisabled(), false);
  await fileToggle.uncheck();
  await page.getByRole("button", { name: "Save settings" }).click();
  await page.getByText("Settings saved.", { exact: true }).waitFor();
  assert.equal((await api("settings")).data.settings.capture_files, false);

  const probe = await fixture("file_monitor");
  assert.equal(probe.created, true);
  assert.equal(probe.modified, true);
  assert.equal(probe.deleted, true);
  assert.equal(probe.boundedBatchContinuation, true);
  assert.equal(probe.contentRedacted, true);
  assert.equal(probe.absolutePathHidden, true);
  assert.equal(probe.relativePathRecorded, true);
  assert.equal(probe.baselineClearedWhenDisabled, true);
  if (probe.permissionsSupported) assert.equal(probe.permissionsChanged, true);

  const eventList = await api("events", null, { category: "files", q: probe.relativePath });
  assert.equal(eventList.status, 200);
  assert.ok(eventList.data.events.some(event => event.action_key === "file_created"));
  const fileDetail = await api(`events/${eventList.data.events[0].id}`);
  assert.equal(fileDetail.status, 200);
  assert.equal(fileDetail.data.category, "files");
  assert.equal(fileDetail.data.item_name, probe.relativePath);
  const fileExport = await api("export", null, { category: "files" });
  assert.equal(fileExport.status, 200);
  assert.ok(fileExport.data.includes(probe.relativePath));
  assert.ok(!fileExport.data.includes("PRIVATE_FILE_CONTENT"));

  await page.goto(screen + "&category=files");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  await page.getByRole("button", { name: /Created file/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText(probe.relativePath, { exact: true }).first().waitFor();
  await page.getByText("Loading event details…").waitFor({ state: "hidden" });
  await page.screenshot({ path: ".tmp/file-monitor-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(screen + "&category=files");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  await page.screenshot({ path: ".tmp/file-monitor-mobile.png", fullPage: true });
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  console.log("File monitoring defaults, settings, scan events, privacy, CSV, details, and narrow layout passed");
} finally {
  await browser.close();
}
