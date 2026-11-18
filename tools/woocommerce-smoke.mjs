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

  await page.goto(base + "/wp-admin/plugins.php");
  const wooRow = page.locator('tr[data-plugin="woocommerce/woocommerce.php"]');
  await wooRow.waitFor();
  const activateWoo = wooRow.locator(".activate a");
  if (await activateWoo.count()) {
    await activateWoo.click();
    await page.waitForLoadState("domcontentloaded");
  }
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal");
  await page.getByRole("heading", { name: "Change Vigil", exact: true }).waitFor();
  await fixture("reset");

  const defaults = await api("settings");
  assert.equal(defaults.status, 200);
  assert.equal(defaults.data.settings.woocommerce, false);
  const disabled = await fixture("woocommerce_disabled");
  assert.equal(disabled.supported, true);
  assert.equal(disabled.capturedWhileDisabled, false, "WooCommerce content must not leak into generic content events while disabled");

  const enabledSettings = { ...defaults.data.settings, woocommerce: true };
  assert.equal((await api("settings", { data: enabledSettings })).status, 200);
  await page.reload();
  const probe = await fixture("woocommerce");
  assert.equal(probe.supported, true);
  for (const action of ["product_created", "product_edited", "product_status_changed", "product_trashed", "product_restored", "product_deleted"]) {
    assert.ok(probe.productActions.includes(action), `Missing product event ${action}`);
  }
  for (const action of ["coupon_created", "coupon_edited", "coupon_status_changed", "coupon_trashed", "coupon_restored", "coupon_deleted"]) {
    assert.ok(probe.couponActions.includes(action), `Missing coupon event ${action}`);
  }
  for (const action of ["order_trashed", "order_restored", "order_deleted"]) {
    assert.ok(probe.orderActions.includes(action), `Missing order event ${action}`);
  }
  assert.equal(probe.productPriceCaptured, true);
  assert.equal(probe.couponAmountCaptured, true);
  assert.equal(probe.taxSettingCaptured, true);
  assert.equal(probe.countryListCaptured, true);
  assert.equal(probe.notificationAddressRedacted, true);
  assert.equal(probe.privateValuesRedacted, true);

  const productEvents = await api("events", { query: { item: `product:${probe.productId}`, category: "woocommerce" } });
  assert.equal(productEvents.status, 200);
  const editedProduct = productEvents.data.events.find(event => event.action_key === "product_edited");
  assert.ok(editedProduct, "The product edit must be listed through REST");
  const productDetail = await api(`events/${editedProduct.id}`);
  assert.equal(productDetail.status, 200);
  assert.equal(productDetail.data.category, "woocommerce");
  assert.ok(productDetail.data.details.changes["Regular price"]);
  assert.deepEqual(productDetail.data.details.changes["Regular price"], ["15.00", "18.50"]);

  const couponEvents = await api("events", { query: { item: `coupon:${probe.couponId}`, category: "woocommerce" } });
  assert.equal(couponEvents.status, 200);
  const editedCoupon = couponEvents.data.events.find(event => event.action_key === "coupon_edited");
  assert.ok(editedCoupon, "The coupon edit must be listed through REST");
  const couponDetail = await api(`events/${editedCoupon.id}`);
  assert.equal(couponDetail.status, 200);
  assert.ok(couponDetail.data.details.changes["Discount amount"]);
  assert.equal("customer_email" in couponDetail.data.details.changes, false);
  const csv = await api("export", { query: { category: "woocommerce" } });
  assert.equal(csv.status, 200);
  assert.ok(csv.data.includes("product_created"));
  assert.doesNotMatch(csv.data, /PRIVATE-COUPON|private-[a-z0-9]+@example\.invalid|updated-[a-z0-9]+@example\.invalid/i);

  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal&category=woocommerce&q=" + encodeURIComponent(probe.productName));
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  await page.getByRole("button", { name: /Renamed WooCommerce product/ }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.getByText(probe.productName, { exact: true }).first().waitFor();
  await dialog.getByText("Regular price", { exact: true }).waitFor();
  await dialog.getByText("18.50", { exact: true }).waitFor();
  await page.screenshot({ path: ".tmp/woocommerce-details.png", fullPage: true });
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });

  await page.getByRole("navigation", { name: "Change Vigil" }).getByRole("link", { name: "Settings", exact: true }).click();
  const wooCheckbox = page.getByRole("checkbox", { name: /WooCommerce Record products,/ });
  await wooCheckbox.waitFor();
  assert.equal(await wooCheckbox.isChecked(), true);
  await page.getByText(/coupon email restrictions, payment details, and order contents are not recorded/i).waitFor();
  await page.screenshot({ path: ".tmp/woocommerce-settings.png", fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/wp-admin/admin.php?page=change-vigil-site-journal&category=woocommerce");
  await page.getByRole("table", { name: "Activity events" }).waitFor();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(dimensions.document <= dimensions.viewport + 1, `Horizontal overflow at narrow width: ${JSON.stringify(dimensions)}`);
  await page.screenshot({ path: ".tmp/woocommerce-mobile.png", fullPage: true });
  assert.equal((await api("settings", { data: defaults.data.settings })).status, 200);

  console.log("WooCommerce product, coupon, order, setting, privacy, REST, CSV, detail panel, and narrow layout checks passed");
} finally {
  await browser.close();
}
