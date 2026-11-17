import { createServer } from "node:http";
import { readFile, mkdir } from "node:fs/promises";
import { resolve, join } from "node:path";
import { chromium } from "playwright";

const root = resolve(import.meta.dirname, "..");
const { version } = JSON.parse(await readFile(join(root, "package.json"), "utf8"));
const output = process.env.ALFA_PREVIEW_OUTPUT ?? join(root, "assets");
const serveOnly = process.argv.includes("--serve");
const pluginBuild = join(root, ".tmp", "wp-assets-preview");
const adminCss = await readFile(join(pluginBuild, "admin.css"));
const adminJs = await readFile(join(pluginBuild, "admin.js"));
const publicDir = join(root, "ui", "public");
const dashboardCss = await readFile(join(publicDir, "dashboard.css"));
const themeCss = await readFile(join(publicDir, "theme.css"));
const manropeLatin = await readFile(join(publicDir, "fonts", "manrope-latin.woff2"));
const manropeLatinExtended = await readFile(join(publicDir, "fonts", "manrope-latin-ext.woff2"));

const events = [
  {
    id: 42,
    time_iso: "2026-11-16T10:35:00+00:00",
    time_short: "16 Nov 2026, 10:35",
    time_exact: "16 Nov 2026, 10:35:00 GMT",
    actor_kind: "user",
    actor_id: 2,
    actor_name: "morgan.admin",
    actor_role: "administrator",
    source: "admin",
    item_kind: "page",
    item_key: "84",
    item_name: "Housing Guide",
    action_key: "content_status",
    category: "content",
    severity: "notice",
    summary: "Changed “Housing Guide” from Published to Draft",
  },
  {
    id: 41,
    time_iso: "2026-11-16T10:28:00+00:00",
    time_short: "16 Nov 2026, 10:28",
    time_exact: "16 Nov 2026, 10:28:00 GMT",
    actor_kind: "user",
    actor_id: 2,
    actor_name: "morgan.admin",
    actor_role: "administrator",
    source: "admin",
    item_kind: "page",
    item_key: "84",
    item_name: "Housing Guide",
    action_key: "content_edited",
    category: "content",
    severity: "info",
    summary: "Edited “Housing Guide”",
  },
  {
    id: 40,
    time_iso: "2026-11-16T09:52:00+00:00",
    time_short: "16 Nov 2026, 09:52",
    time_exact: "16 Nov 2026, 09:52:00 GMT",
    actor_kind: "user",
    actor_id: 5,
    actor_name: "alex.editor",
    actor_role: "editor",
    source: "admin",
    item_kind: "page",
    item_key: "84",
    item_name: "Housing Guide",
    action_key: "content_created",
    category: "content",
    severity: "info",
    summary: "Created “Housing Guide”",
  },
];

const detail = {
  ...events[0],
  details: { changes: { Status: ["publish", "draft"] } },
  notes: [],
};

const previewSettings = {
  retention: 90,
  erase: false,
  woocommerce: false,
  redirection: false,
  email_alerts: false,
  email_alert_recipients: [],
  email_alert_include_events: true,
  email_alert_event_limit: 10,
  email_alert_categories: ["content", "accounts", "comments", "taxonomies", "appearance", "components", "settings", "woocommerce", "redirection", "acf", "bbpress", "wpforms", "yoast", "rank_math", "pmpro", "termly", "mainwp", "infinitewp", "database", "files"],
  capture_content: true,
  capture_accounts: true,
  capture_comments: true,
  capture_taxonomies: true,
  capture_appearance: true,
  capture_components: true,
  capture_settings: true,
  capture_database: true,
  capture_files: false,
  capture_network: true,
  monitor_uploads: false,
  file_max_size_mb: 10,
  file_scan_interval: "daily",
  file_excluded_paths: [],
  disabled_actions: [],
  excluded_users: [],
  excluded_roles: [],
  excluded_item_kinds: [],
  excluded_items: [],
  excluded_post_statuses: [],
  viewer_roles: [],
  manager_roles: [],
  viewer_users: [],
  manager_users: [],
  exclude_system_activity: false,
};

const html = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Activity Log for Admins preview</title>
  <style>
    html, body { min-height: 100%; margin: 0; }
    body { background: #f0f0f1; color: #1d2327; font: 14px/1.5 system-ui, sans-serif; }
    .widget-preview { box-sizing: border-box; max-width: 572px; margin: 32px; padding: 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
    .widget-preview > h1 { margin: 0; padding: 12px 16px; border-bottom: 1px solid #c3c4c7; color: #1d2327; font-size: 14px; line-height: 1.4; font-weight: 600; }
    .widget-preview > .alfa-dashboard-widget { padding: 16px; }
    .widget-preview .button-primary { background: #2271b1; border: 1px solid #2271b1; color: #fff; }
    .widget-preview .alfa-dashboard-header { align-items: flex-start; }
    @media (max-width: 600px) { .widget-preview { margin: 16px; } }
  </style>
  <link rel="stylesheet" href="/theme.css">
  <link rel="stylesheet" href="/dashboard.css">
</head>
<body>
  <section id="alfa_activity_summary" class="widget-preview" aria-label="Activity summary dashboard widget">
    <h1 class="hndle">Activity summary</h1>
    <div class="alfa-dashboard-widget">
      <div class="alfa-dashboard-header"><div><h3 class="alfa-dashboard-overview">At a glance</h3><p class="alfa-dashboard-intro">Changes recorded across this site.</p></div><a class="button button-primary alfa-dashboard-action" href="/?page=activity-log-for-admins">View activity</a></div>
      <dl class="alfa-dashboard-metrics">
        <div class="alfa-dashboard-metric"><dt>Last 24 hours</dt><dd>8</dd></div>
        <div class="alfa-dashboard-metric"><dt>Last 7 days</dt><dd>23</dd></div>
        <div class="alfa-dashboard-metric is-warning"><dt>Warnings in 7 days</dt><dd>2</dd></div>
      </dl>
      <div class="alfa-dashboard-recent"><h3>Recent changes</h3><ul>
        <li><a class="alfa-dashboard-event" href="#activity"><span class="alfa-dashboard-event-summary">Changed “Housing Guide” from Published to Draft</span><span class="alfa-dashboard-event-meta"><span>Housing Guide</span> <span aria-hidden="true">·</span> <span>morgan.admin</span> <span aria-hidden="true">·</span> <time datetime="2026-11-16T10:35:00+00:00">16 Nov 2026, 10:35</time></span></a></li>
        <li><a class="alfa-dashboard-event" href="#activity"><span class="alfa-dashboard-event-summary">Updated the Permalink structure</span><span class="alfa-dashboard-event-meta"><span>Permalink structure</span> <span aria-hidden="true">·</span> <span>siteadmin</span> <span aria-hidden="true">·</span> <time datetime="2026-11-16T09:18:00+00:00">16 Nov 2026, 09:18</time></span></a></li>
        <li><a class="alfa-dashboard-event" href="#activity"><span class="alfa-dashboard-event-summary">Changed the role for jordan.editor</span><span class="alfa-dashboard-event-meta"><span>jordan.editor</span> <span aria-hidden="true">·</span> <span>siteadmin</span> <span aria-hidden="true">·</span> <time datetime="2026-11-16T08:42:00+00:00">16 Nov 2026, 08:42</time></span></a></li>
      </ul></div>
    </div>
  </section>
</body>
</html>`;

const actions = [
  "content_created", "content_edited", "content_status", "content_trashed", "content_restored",
  "user_created", "user_deleted", "user_removed_from_site", "role_changed", "signed_in", "signed_out", "sign_in_failed",
  "user_profile_changed", "password_changed", "term_created", "term_edited", "term_deleted", "terms_changed",
  "comment_created", "comment_edited", "comment_status", "comment_deleted", "menu_created", "menu_updated",
  "menu_deleted", "menu_item_added", "menu_item_updated", "menu_item_deleted", "widget_changed",
  "order_created", "order_status_changed", "order_refunded", "redirect_updated", "redirect_deleted",
  "redirect_enabled", "redirect_disabled", "plugin_settings_changed",
  "plugin_installed", "plugin_activated", "plugin_deactivated", "plugin_updated", "plugin_deleted",
  "theme_installed", "theme_switched", "theme_updated", "theme_deleted", "setting_changed",
];

const server = createServer((request, response) => {
  const pathname = new URL(request.url, "http://127.0.0.1").pathname;
  const files = {
    "/": ["text/html; charset=utf-8", `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activity Log</title><style>html,body{margin:0}body{background:#f4f5f7}</style><link rel="stylesheet" href="/theme.css"><link rel="stylesheet" href="/admin.css"></head><body><div id="alfa-root"></div><script>
      window.alfaSettings={base:location.origin+'/wp-json/activity-log-for-admins/v1/',nonce:'preview',actions:${JSON.stringify(actions)},severities:['info','notice','warning'],version:'${version}',canManage:true,sessionsAllowed:true,roles:{administrator:'Administrator',editor:'Editor',author:'Author',contributor:'Contributor',subscriber:'Subscriber'},timezone:'UTC'};
      const activityEvents=${JSON.stringify(events)};
      const activityDetail=${JSON.stringify(detail)};
      window.fetch=async input=>{const raw=typeof input==='string'?input:(input instanceof URL?input.href:input.url);const url=new URL(raw,location.href);const path=url.pathname.replace(/\\/+$/,'');let value;if(path.endsWith('/events/42'))value=activityDetail;else if(path.endsWith('/events'))value={events:activityEvents,next:null};else if(path.endsWith('/settings'))value={settings:${JSON.stringify(previewSettings)},health:{available:true,write_error:null,cleanup_error:null,next_cleanup:'16 Nov 2026, 11:30',oldest:'16 Nov 2026, 08:42',space:'1.24 MB',last_cleanup:'16 Nov 2026, 10:30'}};else if(path.endsWith('/alerts/channels'))value={channels:{slack_configured:false,sms_configured:false,sms_recipient_count:0}};else if(path.endsWith('/alerts/rules'))value={rules:[],presets:[],fields:[]};else if(path.endsWith('/users'))value={matches:[],selected:[]};else value={};return new Response(JSON.stringify(value),{status:200,headers:{'Content-Type':'application/json'}})};
    </script><script src="/admin.js"></script></body></html>`],
    "/admin.css": ["text/css; charset=utf-8", adminCss],
    "/admin.js": ["text/javascript; charset=utf-8", adminJs],
    "/dashboard.css": ["text/css; charset=utf-8", dashboardCss],
    "/theme.css": ["text/css; charset=utf-8", themeCss],
    "/fonts/manrope-latin.woff2": ["font/woff2", manropeLatin],
    "/fonts/manrope-latin-ext.woff2": ["font/woff2", manropeLatinExtended],
    "/dashboard": ["text/html; charset=utf-8", html],
    "/favicon.ico": ["image/x-icon", Buffer.alloc(0)],
  };
  const asset = files[pathname];
  if (!asset) {
    response.writeHead(404);
    response.end("Not found");
    return;
  }
  response.writeHead(200, { "Content-Type": asset[0] });
  response.end(asset[1]);
});

await mkdir(output, { recursive: true });
await new Promise(resolveListen => server.listen(0, "127.0.0.1", resolveListen));
const address = server.address();
const base = `http://127.0.0.1:${address.port}`;
if (serveOnly) {
  console.log(base);
} else {
  await capture();
}

async function capture() {
  const browser = await chromium.launch({ channel: "chrome", headless: true });

  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    page.setDefaultTimeout(6000);
    page.on("console", message => { if (message.type() === "error") console.error(`Browser console: ${message.text()}`); });
    page.on("pageerror", error => console.error(`Browser error: ${error.stack}`));
    page.on("requestfailed", request => console.error(`Request failed: ${request.url()}, ${request.failure()?.errorText ?? "unknown"}`));
    await page.goto(`${base}/?page=activity-log-for-admins&q=Housing+Guide`);
    await page.getByRole("heading", { name: "Activity Log", exact: true }).waitFor();
    await page.getByRole("button", { name: /Changed.*Housing Guide.*Published to Draft/ }).waitFor();
    await page.evaluate(() => document.fonts.ready.then(() => undefined));
    await page.locator(".alfa-app").screenshot({ path: join(output, "screenshot-1.png") });

    await page.getByRole("button", { name: /Changed.*Housing Guide.*Published to Draft/ }).click();
    await page.getByRole("dialog").getByText("Published", { exact: true }).waitFor();
    await page.screenshot({ path: join(output, "screenshot-2.png"), animations: "disabled" });

    const dashboard = await browser.newPage({ viewport: { width: 1180, height: 800 }, deviceScaleFactor: 1 });
    await dashboard.goto(`${base}/dashboard`);
    await dashboard.locator(".alfa-dashboard-metrics").waitFor();
    await dashboard.evaluate(() => document.fonts.ready.then(() => undefined));
    await dashboard.locator(".widget-preview").screenshot({ path: join(output, "screenshot-3.png") });
    await dashboard.close();

    const settings = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    settings.setDefaultTimeout(6000);
    await settings.goto(`${base}/?page=activity-log-for-admins&view=settings`);
    await settings.getByRole("heading", { name: "Log health", exact: true }).waitFor();
    await settings.getByText(`Activity Log for Admins · Version ${version}`).waitFor();
    await settings.evaluate(() => document.fonts.ready.then(() => undefined));
    await settings.locator(".alfa-app").screenshot({ path: join(output, "screenshot-4.png") });
    await settings.close();
    await page.close();
    for (const name of ["screenshot-1.png", "screenshot-2.png", "screenshot-3.png", "screenshot-4.png"]) {
      const { size } = await (await import("node:fs/promises")).stat(join(output, name));
      console.log(`${name}, ${size} bytes`);
    }
  } finally {
    await browser.close();
    await new Promise(resolveClose => server.close(resolveClose));
  }
}
