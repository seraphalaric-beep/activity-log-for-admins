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
let originalSettings = null;
let restoreError = null;
let alertFixtureStarted = false;
const alertMarker = "ALERT_FIXTURE_" + randomBytes(4).toString("hex").toUpperCase();

async function api(path, data) {
  return page.evaluate(async ({ path, data }) => {
    const config = window.cvsjSettings;
    const url = new URL(config.base);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    url.searchParams.set("_cvsj_request", Date.now() + "-" + Math.random().toString(36).slice(2));
    const response = await fetch(url, {
      method: data ? "POST" : "GET",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
      body: data && JSON.stringify(data),
    });
    return { status: response.status, data: await response.json().catch(() => null) };
  }, { path, data });
}

async function fixture(operation, data = {}) {
  const response = await page.request.post(base + "/cvsj-fixture.php", { form: { operation, ...data } });
  assert.equal(response.status(), 200, await response.text());
  return response.json();
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
  assert.equal((await fixture("custom_alert_begin")).ready, true);
  alertFixtureStarted = true;
  const ruleList = await api("alerts/rules");
  assert.equal(ruleList.status, 200);
  assert.ok(ruleList.data.fields.length >= 8);
  assert.ok(ruleList.data.presets.some((preset) => preset.id === "failed_sign_in"));
  const sessionPolicyPreset = ruleList.data.presets.find((preset) => preset.id === "session_policy_action");
  assert.equal(sessionPolicyPreset.conditions[0].value, "session_policy_ended", "The recommended security alert covers concurrent and idle session actions.");
  const channelSid = "AC" + "a".repeat(32).toUpperCase();
  const channelToken = "PILOT_TWILIO_SECRET_TOKEN_2026";
  const channelSetup = await api("alerts/channels", {
    slack_webhook: "https://hooks.slack.com/services/T00000000/B00000000/SAFEWEBHOOKTOKEN",
    twilio_account_sid: channelSid,
    twilio_auth_token: channelToken,
    sms_from: "+353123456789",
    sms_recipients: ["+353987654321", "+353123123123", "+442071838750"],
  });
  assert.equal(channelSetup.status, 200);
  assert.equal(channelSetup.data.slack_configured, true);
  assert.equal(channelSetup.data.sms_configured, true);
  assert.equal(channelSetup.data.sms_recipient_count, 3);
  assert.equal(channelSetup.data.sms_sender_masked.slice(-4), "6789");
  assert.equal(channelSetup.data.needs_reentry, false);
  const channelRead = await api("alerts/channels");
  assert.equal(channelRead.status, 200);
  assert.doesNotMatch(JSON.stringify(channelRead.data), /SAFEWEBHOOKTOKEN|PILOT_TWILIO_SECRET_TOKEN|353987654321/);
  const invalidWebhook = await api("alerts/channels", { slack_webhook: "https://127.0.0.1/services/internal/secret" });
  assert.equal(invalidWebhook.status, 400, "Slack destinations outside the official webhook hosts must be rejected");
  const channelProbe = await fixture("custom_alert_channel_probe", { sid: channelSid, token: channelToken });
  assert.equal(channelProbe.slackAccepted, true);
  assert.equal(channelProbe.slackEndpoint, true);
  assert.equal(channelProbe.slackPayload, true);
  assert.equal(channelProbe.transportPolicy, true);
  assert.equal(channelProbe.smsAccepted, true);
  assert.equal(channelProbe.twilioEndpoint, true);
  assert.equal(channelProbe.twilioAuth, true);
  assert.equal(channelProbe.smsRecipient, true);
  assert.equal(channelProbe.secretStoredEncrypted, true);
  assert.equal(channelProbe.responseContainsNoSecrets, true);
  const allRule = {
    name: "Focused all conditions " + alertMarker,
    enabled: true,
    logic: "all",
    conditions: [
      { field: "action_key", operator: "equals", value: "setting_changed" },
      { field: "item_name", operator: "contains", value: alertMarker },
    ],
    channels: ["email"],
    recipients: ["activity-alert-review@example.org"],
  };
  const invalidRule = await api("alerts/rules", { ...allRule, recipients: ["not an email"] });
  assert.equal(invalidRule.status, 400, "Invalid custom alert recipients must be rejected");
  const created = await api("alerts/rules", allRule);
  assert.equal(created.status, 200);
  const allRuleId = created.data.id;
  assert.ok(allRuleId);
  const mismatch = await fixture("custom_alert_event", {
    marker: alertMarker,
    variant: "wrong_action",
    action_key: "setting_changed",
  });
  assert.equal(mismatch.queued, false, "Every condition in an all rule must match");
  const match = await fixture("custom_alert_event", {
    marker: alertMarker,
    variant: "match",
    action_key: "setting_changed",
  });
  assert.equal(match.queued, true, "A matching all rule should queue a notification");
  const delivery = await fixture("custom_alert_deliver", { fail_attempts: "2" });
  assert.equal(delivery.attempts, 3, "A failed message should retry twice before succeeding");
  assert.equal(delivery.retryDelays.length, 2);
  assert.ok(delivery.retryDelays[0] >= 290 && delivery.retryDelays[0] <= 300);
  assert.ok(delivery.retryDelays[1] >= 890 && delivery.retryDelays[1] <= 900);
  assert.equal(delivery.queueRemaining, 0);
  assert.equal(delivery.messages[2].to[0], "activity-alert-review@example.org");
  assert.match(delivery.messages[2].body, /Focused all conditions/);
  assert.match(delivery.messages[2].body, new RegExp(alertMarker));
  assert.doesNotMatch(delivery.messages[2].body, /password|token|PRIVATE_/i);
  assert.ok(delivery.messages.every((message) => !/[\r\n]/.test(message.subject)));

  const toggledOff = await api("alerts/rules/" + allRuleId + "/toggle", { enabled: false });
  assert.equal(toggledOff.status, 200);
  const disabledEvent = await fixture("custom_alert_event", {
    marker: alertMarker,
    variant: "match",
    action_key: "setting_changed",
  });
  assert.equal(disabledEvent.queued, false, "Disabled rules must not queue alerts");
  assert.equal((await api("alerts/rules/" + allRuleId + "/toggle", { enabled: true })).status, 200);

  const anyRule = await api("alerts/rules", {
    name: "Focused any condition " + alertMarker,
    enabled: true,
    logic: "any",
    conditions: [
      { field: "action_key", operator: "equals", value: "plugin_installed" },
      { field: "severity", operator: "equals", value: "critical" },
    ],
    channels: ["email"],
    recipients: ["activity-alert-review@example.org"],
  });
  assert.equal(anyRule.status, 200);
  const anyMatch = await fixture("custom_alert_event", {
    marker: alertMarker,
    variant: "plugin",
    action_key: "plugin_installed",
  });
  assert.equal(anyMatch.queued, true, "Any matching condition should queue an alert");
  const anyDelivery = await fixture("custom_alert_deliver", { fail_attempts: "0" });
  assert.equal(anyDelivery.attempts, 1);
  assert.equal(anyDelivery.queueRemaining, 0);
  assert.equal((await api("alerts/rules/" + allRuleId + "/delete", {})).status, 200);
  const criticalMatch = await fixture("custom_alert_event", {
    marker: alertMarker,
    variant: "critical",
    action_key: "setting_changed",
  });
  assert.equal(criticalMatch.queued, true, "An any rule should match when only the severity condition matches");
  const criticalDelivery = await fixture("custom_alert_deliver", { fail_attempts: "0" });
  assert.equal(criticalDelivery.attempts, 1);
  assert.equal(criticalDelivery.queueRemaining, 0);

  const channelMarker = "ALERT_FIXTURE_" + randomBytes(4).toString("hex").toUpperCase();
  const channelRule = await api("alerts/rules", {
    name: "Focused multi channel " + channelMarker,
    enabled: true,
    logic: "all",
    conditions: [
      { field: "action_key", operator: "equals", value: "setting_changed" },
      { field: "item_name", operator: "contains", value: channelMarker },
    ],
    channels: ["email", "slack", "sms"],
    recipients: ["activity-alert-review@example.org"],
  });
  assert.equal(channelRule.status, 200);
  const channelEvent = await fixture("custom_alert_event", {
    marker: channelMarker,
    variant: "match",
    action_key: "setting_changed",
  });
  assert.equal(channelEvent.queued, true);
  const channelDelivery = await fixture("custom_alert_channel_delivery", { sid: channelSid, token: channelToken });
  assert.equal(channelDelivery.mailAttempts, 1, "Successful email delivery must not repeat after another channel fails");
  assert.equal(channelDelivery.slackAttempts, 2, "A failed Slack request should retry once and then succeed");
  assert.equal(channelDelivery.smsAttempts, 3, "SMS delivery should process the recipient list in bounded batches");
  assert.equal(channelDelivery.queueRemaining, 0);
  assert.equal(channelDelivery.slackPayloadSafe, true);
  assert.equal(channelDelivery.twilioRequestSafe, true);
  assert.equal((await api("alerts/rules/" + channelRule.data.id + "/delete", {})).status, 200);
  console.log("Custom alert rules, encrypted provider settings, destination checks, access controls, retries, and partial delivery passed");

  const settingsExport = await api("settings/export");
  assert.equal(settingsExport.status, 200);
  assert.doesNotMatch(JSON.stringify(settingsExport.data), /activity-alert-review@example\.org/, "Alert recipients must stay out of settings downloads");
  const permissions = await fixture("custom_alert_permissions");
  assert.equal(permissions.read, 403, "Editors must not read alert rules");
  assert.equal(permissions.write, 403, "Editors must not create alert rules");
  const channelPermissions = await fixture("custom_alert_permissions");
  assert.equal(channelPermissions.read, 403, "Editors must not read alert rules");
  assert.equal(channelPermissions.write, 403, "Editors must not create alert rules");
  assert.equal(channelPermissions.channelRead, 403, "Editors must not read external channel status");
  assert.equal(channelPermissions.channelWrite, 403, "Editors must not update external channel credentials");
  assert.equal(channelPermissions.channelTest, 403, "Editors must not trigger external test messages");
  const recipients = ["activity-alert-review@example.org", "second-review@example.org"];
  const selectedSettings = {
    ...originalSettings,
    email_alerts: true,
    email_alert_recipients: recipients,
    email_alert_include_events: false,
    email_alert_event_limit: 2,
    email_alert_categories: ["settings"],
  };
  assert.equal((await api("settings", selectedSettings)).status, 200);

  const exported = await api("settings/export");
  assert.equal(exported.status, 200);
  assert.equal("email_alert_recipients" in exported.data.settings, false, "Recipient addresses must stay out of settings downloads");
  assert.deepEqual(exported.data.settings.email_alert_categories, ["settings"]);

  const unselected = await fixture("warning_content");
  assert.equal(unselected.scheduled, false, "Warnings outside selected categories must not schedule a digest");
  const batch = await fixture("warning_batch");
  assert.equal(batch.scheduled, true, "A selected warning should schedule a digest");
  const privacyDelivery = await fixture("alert_delivery");
  assert.equal(privacyDelivery.sent, true);
  assert.deepEqual(privacyDelivery.recipients, recipients);
  assert.equal(privacyDelivery.containsKnownSummary, false, "Event summaries should be omitted when disabled");
  assert.equal(privacyDelivery.summaryOnly, true, "Private event details must never appear in email");
  assert.match(privacyDelivery.body, /summaries were omitted/i);

  const includedSettings = { ...selectedSettings, email_alert_include_events: true };
  assert.equal((await api("settings", includedSettings)).status, 200);
  assert.equal((await fixture("warning_batch")).scheduled, true);
  const limitedDelivery = await fixture("alert_delivery");
  assert.equal(limitedDelivery.sent, true);
  assert.match(limitedDelivery.body, /Warning digest batch 3/);
  assert.match(limitedDelivery.body, /Warning digest batch 2/);
  assert.doesNotMatch(limitedDelivery.body, /Warning digest batch 1/);
  assert.equal(limitedDelivery.summaryOnly, true);

  const invalidEmail = await api("settings", { ...includedSettings, email_alert_recipients: ["not an email"] });
  assert.equal(invalidEmail.status, 400, "Invalid recipient addresses must be rejected by the REST schema");
  const invalidCategory = await api("settings", { ...includedSettings, email_alert_categories: ["unknown"] });
  assert.equal(invalidCategory.status, 400, "Unknown warning categories must be rejected by the REST schema");
  console.log("Warning digest recipient validation, category filtering, private settings export, content choice, and event limit passed");
} finally {
  if (alertFixtureStarted) {
    try {
      const cleanup = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "custom_alert_finish", marker: alertMarker } });
      assert.equal(cleanup.status(), 200, await cleanup.text());
      assert.equal((await cleanup.json()).restored, true);
    } catch (error) {
      restoreError = error;
    }
  }
  if (originalSettings) {
    try {
      const restored = await api("settings", originalSettings);
      assert.equal(restored.status, 200, "The original settings should be restored after the focused check");
      const current = await api("settings");
      for (const key of ["email_alerts", "email_alert_recipients", "email_alert_include_events", "email_alert_event_limit", "email_alert_categories"]) {
        assert.deepEqual(current.data.settings[key], originalSettings[key], "The original " + key + " setting should be restored");
      }
      const cleanup = await page.request.post(base + "/cvsj-fixture.php", { form: { operation: "alert_cleanup" } });
      assert.equal((await cleanup.json()).scheduled, false, "The focused check should leave no warning digest scheduled");
    } catch (error) {
      restoreError = error;
    }
  }
  await browser.close();
  if (restoreError) throw restoreError;
}
