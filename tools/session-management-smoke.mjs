import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const [base = "http://127.0.0.1:8092"] = process.argv.slice(2);
const { version } = JSON.parse(await readFile(new URL("../package.json", import.meta.url), "utf8"));
const password = process.env.PILOT_PASSWORD ?? (process.env.PILOT_PASSWORD_FILE
  ? (await readFile(process.env.PILOT_PASSWORD_FILE, "utf8")).trim()
  : "");
if (!password || new URL(base).hostname !== "127.0.0.1") throw new Error("Use the disposable loopback site and its pilot password.");

const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? "chrome" });
const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
const page = await context.newPage();
page.setDefaultTimeout(15000);
const pageErrors = [];
let fixture = null;

async function signIn(tab, login, secret) {
  await tab.goto(base + "/wp-login.php");
  await tab.locator("#user_login").fill(login);
  await tab.locator("#user_pass").fill(secret);
  await tab.locator("#wp-submit").click();
  await tab.waitForLoadState("domcontentloaded");
}

async function api(path, query = {}, data = undefined) {
  return page.evaluate(async ({ path, query, data }) => {
    const config = window.alfaSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    for (const [key, value] of Object.entries(query)) if (value !== "") url.searchParams.set(key, value);
    url.searchParams.set("_alfa_request", `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    return {
      status: response.status,
      data: await response.json(),
      cacheControl: response.headers.get("cache-control") ?? "",
    };
  }, { path, query, data });
}

async function fixtureCall(operation, fields = {}) {
  const response = await context.request.post(base + "/alfa-fixture.php", { form: { operation, ...fields } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
}

try {
  page.on("pageerror", error => pageErrors.push(error.message));
  await signIn(page, "pilotadmin", password);
  fixture = await fixtureCall("session_seed");
  await fixtureCall("toolbar_seed");
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  const toolbarMenu = page.locator("#wp-admin-bar-alfa-live-activity");
  assert.equal(await toolbarMenu.count(), 1, "Permitted activity viewers see the WordPress toolbar entry.");
  await toolbarMenu.hover();
  const toolbarEvents = page.locator("#wp-admin-bar-alfa-live-activity-feed .alfa-toolbar-event");
  await toolbarEvents.first().waitFor();
  assert.ok((await toolbarEvents.count()) <= 5, "The toolbar request returns no more than five events.");
  assert.equal(await toolbarEvents.first().locator(".alfa-toolbar-event-summary").textContent(), "Toolbar event summary");
  await page.screenshot({ path: ".tmp/network-parity-wp/activity-toolbar-desktop.png", animations: "disabled" });
  await page.setViewportSize({ width: 390, height: 844 });
  assert.equal(await toolbarMenu.isVisible(), true, "The toolbar activity entry stays available on a narrow admin screen.");
  await toolbarMenu.hover();
  const toolbarPanel = page.locator("#wp-admin-bar-alfa-live-activity > .ab-sub-wrapper");
  const toolbarPanelBounds = await toolbarPanel.boundingBox();
  assert.ok(toolbarPanelBounds && toolbarPanelBounds.x >= 0 && toolbarPanelBounds.x + toolbarPanelBounds.width <= 390, `The toolbar feed fits the narrow viewport, ${JSON.stringify(toolbarPanelBounds)}`);
  await page.screenshot({ path: ".tmp/network-parity-wp/activity-toolbar-mobile.png", animations: "disabled" });
  await page.setViewportSize({ width: 1440, height: 950 });
  const toolbarEventUrl = new URL(await toolbarEvents.first().getAttribute("href"));
  assert.match(toolbarEventUrl.searchParams.get("event"), /^\d+$/);
  await toolbarEvents.first().click();
  await page.locator(".alfa-sheet").waitFor();
  await page.getByText(`Event ${toolbarEventUrl.searchParams.get("event")}`, { exact: false }).waitFor();
  await page.goto(base + "/wp-admin/admin.php?page=activity-log-for-admins");
  await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
  const nav = page.getByRole("navigation", { name: "Activity Log", exact: true });
  await nav.getByRole("link", { name: "Sessions", exact: true }).click();
  await page.getByRole("heading", { name: "User sessions", exact: true }).waitFor();
  assert.match(page.url(), /[?&]view=sessions/);
  await page.getByText(`Activity Log for Admins, Version ${version}`, { exact: true }).waitFor();

  const defaultSettings = await api("sessions/settings");
  assert.equal(defaultSettings.status, 200);
  assert.deepEqual(defaultSettings.data.settings, {
    track_activity: false,
    show_browser: false,
    show_ip: false,
    limit_enabled: false,
    max_sessions: 5,
    idle_enabled: false,
    idle_minutes: 60,
    excluded_roles: [],
  });
  assert.match(defaultSettings.cacheControl, /private/i);
  assert.match(defaultSettings.cacheControl, /no-store/i);
  const unsafeIdleSettings = await api("sessions/settings", {}, { ...defaultSettings.data.settings, idle_enabled: true, track_activity: false });
  assert.equal(unsafeIdleSettings.status, 400, "Idle expiry cannot run without explicit activity tracking.");

  const initial = await api("sessions", { search: fixture.editorLogin });
  assert.equal(initial.status, 200, JSON.stringify(initial.data));
  assert.equal(initial.data.users.length, 1);
  assert.equal(initial.data.users[0].sessions.length, 2);
  for (const session of initial.data.users[0].sessions) {
    assert.match(session.reference, /^[a-f0-9]{64}$/);
    assert.equal(session.browser, "");
    assert.equal(session.ip, "");
    assert.equal(session.last_activity, "");
  }

  await page.getByRole("checkbox", { name: "Track last activity on this site" }).check();
  await page.getByRole("checkbox", { name: "Show browser and device type" }).check();
  await page.getByRole("checkbox", { name: "Show IP addresses" }).check();
  await page.getByRole("button", { name: "Save privacy settings" }).click();
  await page.getByText("Privacy settings saved.", { exact: true }).waitFor();

  const expanded = await api("sessions", { search: fixture.editorLogin });
  assert.equal(expanded.status, 200);
  const editor = expanded.data.users[0];
  assert.equal(editor.sessions.length, 2);
  assert.ok(editor.sessions.every(session => session.browser && session.ip === "127.0.0.1"));
  const currentSession = (await api("sessions")).data.users.flatMap(user => user.sessions).find(session => session.current);
  assert.ok(currentSession?.last_activity, "Opt in activity tracking records the current session request time.");
  assert.ok(!JSON.stringify(expanded.data).includes("session_tokens"));
  assert.ok(!JSON.stringify(expanded.data).includes(fixture.editorPassword));

  const accountRows = () => page.locator(".alfa-session-table-wrap tbody tr").filter({ hasText: fixture.editorLogin });
  await page.getByLabel("Search accounts", { exact: true }).fill(fixture.editorLogin);
  await page.getByRole("button", { name: "Search accounts" }).click();
  await page.waitForFunction(login => [...document.querySelectorAll(".alfa-session-table-wrap tbody tr")].filter(row => row.textContent.includes(login)).length === 2, fixture.editorLogin);
  assert.equal(await accountRows().count(), 2);
  await page.setViewportSize({ width: 390, height: 844 });
  const mobileLayout = await page.evaluate(() => ({
    viewport: innerWidth,
    document: document.documentElement.scrollWidth,
    offenders: [...document.querySelectorAll("#alfa-root *")].map(element => {
      const rect = element.getBoundingClientRect();
      return { tag: element.tagName, className: typeof element.className === "string" ? element.className : "", width: Math.round(rect.width), right: Math.round(rect.right), scrollWidth: element.scrollWidth, clientWidth: element.clientWidth };
    }).filter(element => element.right > innerWidth + 1 || element.width > innerWidth + 1).slice(0, 12),
  }));
  await page.screenshot({ path: ".tmp/network-parity-wp/session-management-mobile.png", animations: "disabled" });
  assert.ok(mobileLayout.document <= mobileLayout.viewport, `The session screen fits a narrow viewport: ${JSON.stringify(mobileLayout)}`);
  await page.setViewportSize({ width: 1440, height: 950 });

  page.once("dialog", dialog => dialog.accept());
  await accountRows().first().getByRole("button", { name: "End session", exact: true }).click();
  await page.waitForFunction(login => [...document.querySelectorAll(".alfa-session-table-wrap tbody tr")].filter(row => row.textContent.includes(login)).length === 1, fixture.editorLogin);
  assert.equal((await api("sessions", { search: fixture.editorLogin })).data.users[0].sessions.length, 1);

  page.once("dialog", dialog => dialog.accept());
  await accountRows().first().getByRole("button", { name: "End all for account", exact: true }).click();
  await page.getByText("No active sessions", { exact: true }).waitFor();
  assert.equal((await api("sessions", { search: fixture.editorLogin })).data.users[0].sessions.length, 0);

  const singleAudit = await api("events", { item: `user:${fixture.editorId}`, action: "session_ended" });
  const allAudit = await api("events", { item: `user:${fixture.editorId}`, action: "sessions_ended" });
  assert.ok(singleAudit.data.events.some(event => event.action_key === "session_ended"));
  assert.ok(allAudit.data.events.some(event => event.action_key === "sessions_ended"));
  assert.ok(!JSON.stringify(singleAudit.data).includes("127.0.0.1"));

  await page.getByRole("checkbox", { name: "Limit active sessions per account" }).check();
  await page.getByLabel("Maximum sessions per account", { exact: true }).fill("2");
  await page.getByRole("checkbox", { name: "Subscriber", exact: true }).check();
  await page.getByRole("button", { name: "Save privacy settings" }).click();
  await page.getByText("Privacy settings saved.", { exact: true }).waitFor();
  await fixtureCall("session_add", { user_id: fixture.editorId, count: 2 });
  const editorContext = await browser.newContext();
  const editorPage = await editorContext.newPage();
  await signIn(editorPage, fixture.editorLogin, fixture.editorPassword);
  await editorPage.goto(base + "/wp-admin/");
  assert.equal(await editorPage.locator("#wp-admin-bar-alfa-live-activity").count(), 0, "Non viewers do not receive the toolbar entry or its polling script.");
  const editorNonceResponse = await editorContext.request.post(base + "/alfa-fixture.php", { form: { operation: "nonce" } });
  assert.equal(editorNonceResponse.status(), 200);
  const editorNonce = await editorNonceResponse.json();
  const deniedToolbar = await editorContext.request.get(editorNonce.restBase + "toolbar/events", { headers: { "X-WP-Nonce": editorNonce.nonce } });
  assert.equal(deniedToolbar.status(), 403, "The toolbar feed uses the activity viewer permission.");
  const limited = await api("sessions", { search: fixture.editorLogin });
  assert.equal(limited.data.users[0].sessions.length, 2, "A new sign in ends the oldest session above the account limit.");
  const policyEvent = await api("events", { item: `user:${fixture.editorId}`, action: "session_policy_ended" });
  const concurrentEvent = policyEvent.data.events.find(event => event.action_key === "session_policy_ended");
  assert.ok(concurrentEvent);
  assert.equal((await api(`events/${concurrentEvent.id}`)).data.details.policy, "session limit");

  await page.getByRole("checkbox", { name: "End idle sessions automatically" }).check();
  await page.getByLabel("Idle time in minutes", { exact: true }).fill("5");
  await page.getByRole("button", { name: "Save privacy settings" }).click();
  await page.getByText("Privacy settings saved.", { exact: true }).waitFor();
  const idleSettings = await api("sessions/settings");
  assert.equal(idleSettings.data.settings.idle_enabled, true);
  assert.equal(idleSettings.data.settings.idle_minutes, 5);
  assert.equal(idleSettings.data.settings.track_activity, true);
  const markedIdle = await fixtureCall("session_prepare_idle_expiry", { user_id: fixture.editorId, age: 600, policy_age: 0 });
  assert.equal(markedIdle.marked, 2);
  const markedRows = await api("sessions", { search: fixture.editorLogin });
  assert.ok(markedRows.data.users[0].sessions.every(session => session.last_activity), "The manager view sees the stale markers for each test session.");
  const idleProbeResponse = await editorContext.request.post(base + "/alfa-fixture.php", { form: { operation: "session_probe" } });
  assert.equal(idleProbeResponse.status(), 200, await idleProbeResponse.text());
  const idleProbe = await idleProbeResponse.json();
  assert.equal(idleProbe.user_id, fixture.editorId, "Existing sessions receive a grace period when idle expiry is first enabled.");
  const expiredMarkers = await fixtureCall("session_prepare_idle_expiry", { user_id: fixture.editorId, age: 600, policy_age: 600 });
  assert.ok(expiredMarkers.policy_age >= 600, `The policy start remains aged after fixture setup, ${JSON.stringify(expiredMarkers)}`);
  const expiredIdleProbeResponse = await editorContext.request.post(base + "/alfa-fixture.php", { form: { operation: "session_probe" } });
  assert.equal(expiredIdleProbeResponse.status(), 200, await expiredIdleProbeResponse.text());
  const expiredIdleProbe = await expiredIdleProbeResponse.json();
  assert.equal(expiredIdleProbe.user_id, 0, `The idle policy signs out a stale session, ${JSON.stringify(expiredIdleProbe)}`);
  await editorPage.goto(base + "/wp-admin/");
  const afterIdle = await api("sessions", { search: fixture.editorLogin });
  assert.equal(afterIdle.data.users[0].sessions.length, 1, "The request that exceeded the idle limit loses its own session.");
  assert.ok(/wp-login\.php/.test(editorPage.url()) || await editorPage.locator("#loginform").count() > 0, "An expired idle session returns to the login screen.");
  const idleEvent = await api("events", { item: `user:${fixture.editorId}`, action: "session_policy_ended" });
  const idleEventRow = idleEvent.data.events.find(event => event.action_key === "session_policy_ended");
  assert.ok(idleEventRow);
  assert.ok(idleEvent.data.events.length >= 2);
  const idleDetails = await api(`events/${idleEventRow.id}`);
  assert.equal(idleDetails.data.details.policy, "idle expiry");

  await fixtureCall("session_add", { user_id: fixture.siteAdminId, count: 2 });

  const siteAdminContext = await browser.newContext();
  const siteAdminPage = await siteAdminContext.newPage();
  await signIn(siteAdminPage, fixture.siteAdminLogin, fixture.siteAdminPassword);
  assert.equal((await api("sessions", { search: fixture.siteAdminLogin })).data.users[0].sessions.length, 3, "Site administrators are exempt from automatic session limits.");
  const nonceResponse = await siteAdminContext.request.post(base + "/alfa-fixture.php", { form: { operation: "nonce" } });
  assert.equal(nonceResponse.status(), 200);
  const nonceData = await nonceResponse.json();
  const deniedRead = await siteAdminContext.request.get(nonceData.restBase + "sessions", { headers: { "X-WP-Nonce": nonceData.nonce } });
  assert.equal(deniedRead.status(), 403, "A site administrator cannot revoke account wide Multisite sessions.");
  const deniedSettings = await siteAdminContext.request.post(nonceData.restBase + "sessions/settings", {
    headers: { "X-WP-Nonce": nonceData.nonce, "Content-Type": "application/json" },
    data: { track_activity: true, show_browser: true, show_ip: true },
  });
  assert.equal(deniedSettings.status(), 403);
  await siteAdminContext.close();
  await editorContext.close();

  await api("sessions/settings", { }, { track_activity: false, show_browser: false, show_ip: false });
  assert.deepEqual(pageErrors, []);
  console.log("Session inventory, privacy defaults, optional details, activity tracking, individual and account wide revocation, audit events, Multisite access, and narrow layout passed.");
} finally {
  if (fixture) {
    await fixtureCall("session_cleanup", { editor_id: fixture.editorId, site_admin_id: fixture.siteAdminId });
  }
  await browser.close();
}
