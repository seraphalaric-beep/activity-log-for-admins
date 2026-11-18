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
const allSeoPlugins = [
  { provider: "yoast", plugin: "wordpress-seo/wp-seo.php", title: "Yoast SEO", option: "Yoast SEO recording" },
  { provider: "rank_math", plugin: "seo-by-rank-math/rank-math.php", title: "Rank Math", option: "Rank Math recording" },
];
const seoPlugins = process.env.SEO_PROVIDER ? allSeoPlugins.filter(plugin => plugin.provider === process.env.SEO_PROVIDER) : allSeoPlugins;
if (!seoPlugins.length) throw new Error("SEO_PROVIDER must be yoast or rank_math.");
let originalSettings = null;

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(options.query ?? {})) url.searchParams.set(key, value);
    url.searchParams.set("_cvsj_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: options.data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: options.data && JSON.stringify(options.data),
    });
    const contentType = response.headers.get("content-type") ?? "";
    return { status: response.status, data: contentType.includes("application/json") ? await response.json() : await response.text() };
  }, { path, options });
}

async function fixture(operation, provider) {
  const response = await page.request.post(base + "/cvsj-fixture.php", { form: { operation, provider } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

async function pluginActive(plugin) {
  const row = page.locator(`tr[data-plugin="${plugin}"]`);
  await row.waitFor();
  return 0 < await row.locator(".deactivate a").count();
}

async function setPluginActive(plugin, active) {
  await page.goto(base + "/wp-admin/plugins.php");
  const row = page.locator(`tr[data-plugin="${plugin}"]`);
  await row.waitFor();
  const currentlyActive = 0 < await row.locator(".deactivate a").count();
  if (currentlyActive === active) return;
  const link = row.locator(currentlyActive ? ".deactivate a" : ".activate a");
  if (await link.count()) {
    await link.click();
    await page.waitForLoadState("domcontentloaded");
  }
  assert.equal(await pluginActive(plugin), active, `Plugin active state did not update for ${plugin}`);
}

async function checkProvider(plugin) {
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
  await fixture("reset");
  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  assert.equal(defaults.data.settings[plugin.provider], false, `${plugin.title} recording must default off`);

  const disabledState = await fixture("seo_hook_state");
  assert.equal(disabledState.optionHookRegistered, false, "SEO settings hooks must stay absent when both integrations are disabled");
  const disabled = await fixture("seo_disabled", plugin.provider);
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, `${plugin.title} must not capture while disabled`);

  const enabled = { ...defaults.data.settings, [plugin.provider]: true };
  assert.equal((await api("settings", { data: enabled })).status, 200);
  await page.reload();
  const enabledState = await fixture("seo_hook_state");
  assert.equal(enabledState.optionHookRegistered, true, `${plugin.title} setting changes must be captured only after opt in`);

  const probe = await fixture("seo", plugin.provider);
  assert.equal(probe.supported, true);
  assert.equal(probe.metaEventCount, 1, `${plugin.title} changes to two SEO fields should be grouped into one event`);
  assert.equal(probe.metaEventsAfterUnchangedSave, 1, "An identical SEO metadata save must not add an event");
  assert.equal(probe.settingsEventCount, 1, `${plugin.title} option changes should be grouped into one settings event`);
  assert.equal(probe.privacyRedacted, true, `${plugin.title} values, addresses, and emails must not be stored`);
  assert.ok(probe.metaLabels.includes("SEO title"));
  assert.ok(probe.metaLabels.includes("Meta description"));

  const events = await api("events", { query: { item: `page:${probe.postId}`, category: plugin.provider } });
  assert.equal(events.status, 200);
  const metaEvent = events.data.events.find(row => row.action_key === `${plugin.provider}_seo_meta_changed`);
  assert.ok(metaEvent, `${plugin.title} metadata change must be listed in REST`);
  const detail = await api(`events/${metaEvent.id}`);
  assert.equal(detail.status, 200);
  assert.equal(detail.data.category, plugin.provider);
  assert.deepEqual(detail.data.details.changes["SEO title"], ["Not recorded", "Changed"]);
  assert.deepEqual(detail.data.details.changes["Meta description"], ["Not recorded", "Changed"]);

  const csv = await api("export", { query: { category: plugin.provider } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes(`${plugin.provider}_seo_meta_changed`));
  assert.ok(csv.data.includes(`${plugin.provider}_seo_settings_changed`));
  assert.doesNotMatch(csv.data, /PRIVATE_SEO_|private@example\.invalid|https:\/\/private\.invalid/);

  await page.goto(base + `/wp-admin/admin.php?page=change-vigil-site-journal&category=${plugin.provider}&q=${encodeURIComponent(probe.postName)}`);
  const activityTable = page.getByRole("table", { name: "Activity events" });
  try {
    await activityTable.waitFor();
  } catch (error) {
    console.log(`Activity view diagnostics for ${plugin.provider}`, await page.title(), await page.url(), (await page.locator("body").innerText()).slice(0, 2200));
    await page.screenshot({ path: `.tmp/seo-${plugin.provider}-failed.png`, fullPage: true });
    throw error;
  }
  await page.getByRole("button", { name: new RegExp(`${plugin.title} fields changed`) }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText("SEO title", { exact: true }).waitFor();
  await dialog.getByText("Meta description", { exact: true }).waitFor();
  await dialog.getByText("Not recorded", { exact: true }).first().waitFor();
  await dialog.getByText("Changed", { exact: true }).first().waitFor();
  await page.screenshot({ path: `.tmp/seo-${plugin.provider}-details.png`, fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Change Vigil" }).getByRole("link", { name: "Settings", exact: true }).click();
  const integration = page.getByRole("checkbox", { name: new RegExp(plugin.title) });
  await integration.waitFor();
  assert.equal(await integration.isChecked(), true);
  await page.getByText(/SEO text, keyphrases, canonical addresses, image addresses, and setting values are never stored/i).first().waitFor();
  await page.screenshot({ path: `.tmp/seo-${plugin.provider}-settings.png`, fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + `/wp-admin/admin.php?page=change-vigil-site-journal&category=${plugin.provider}`);
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: `.tmp/seo-${plugin.provider}-mobile.png`, fullPage: true });
  await page.setViewportSize({ width: 1440, height: 950 });
  console.log(`${plugin.title} opt in, metadata grouping, unchanged save, settings, REST, CSV, privacy, details, and narrow layout checks passed`);
}

try {
  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill("pilotadmin");
  await page.locator("#user_pass").fill(password);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("domcontentloaded");
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
  const original = await api("settings");
  assert.equal(original.status, 200);
  originalSettings = original.data.settings;

  await page.goto(base + "/wp-admin/plugins.php");
  for (const plugin of allSeoPlugins) await pluginActive(plugin.plugin);
  for (const plugin of allSeoPlugins) await setPluginActive(plugin.plugin, false);

  for (const plugin of seoPlugins) {
    await setPluginActive(plugin.plugin, true);
    await checkProvider(plugin);
    await setPluginActive(plugin.plugin, false);
  }
} finally {
  if (originalSettings) {
    await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
    await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
    await api("settings", { data: originalSettings });
  }
  await page.goto(base + "/wp-admin/plugins.php");
  for (const plugin of allSeoPlugins) await setPluginActive(plugin.plugin, false);
  await browser.close();
}
