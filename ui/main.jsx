import { cloneElement, useEffect, useId, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
  Activity, ArrowDown, ArrowDownToLine, ArrowLeft, ArrowRight, ChevronRight, CircleAlert, File as FileIcon, Globe,
  CircleHelp, Clock3, Database, FileText, Laptop, ListFilter, LockKeyhole, LogOut, MapPin, Plug, RefreshCw, Search, Settings2,
  MessageSquare, Menu, Package, PanelsTopLeft, ShieldAlert, SwatchBook, Tag, UserRound, UsersRound, X,
} from "lucide-react";
import { Badge } from "./components/badge";
import { Button } from "./components/button";
import { Input } from "./components/input";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "./components/sheet";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/table";
import { apiUrl, config, readView, request, viewUrl } from "./api.js";
import "./style.css";

const actions = {
  content_created: "Content created", content_edited: "Content edited", content_status: "Status changed",
  content_trashed: "Moved to Trash", content_restored: "Restored from Trash",
  user_created: "Account created", user_deleted: "Account deleted", role_changed: "Role changed",
  user_profile_changed: "Profile changed", user_removed_from_site: "Account removed from site", password_changed: "Password changed",
  site_created: "Network site created", site_deleted: "Network site deleted", site_status_changed: "Network site status changed",
  network_setting_changed: "Network setting changed", network_admin_email_change_requested: "Network admin email change requested", network_admin_email_changed: "Network admin email changed",
  admin_email_change_requested: "Admin email change requested", admin_email_changed: "Admin email changed",
  database_table_created: "Database table created", database_table_deleted: "Database table deleted",
  file_created: "File created", file_modified: "File changed", file_deleted: "File deleted", file_permissions_changed: "File permissions changed",
  file_scan_started: "File scan started", file_scan_completed: "File scan completed", file_scan_failed: "File scan issue", file_scan_size_skipped: "Large file checksum skipped",
  signed_in: "Signed in", signed_out: "Signed out", sign_in_failed: "Failed sign in",
  session_ended: "Session ended", sessions_ended: "Sessions ended", session_policy_ended: "Session policy acted", session_policy_changed: "Session settings changed",
  term_created: "Term created", term_edited: "Term edited", term_deleted: "Term deleted", terms_changed: "Terms changed",
  comment_created: "Comment added", comment_edited: "Comment edited", comment_status: "Comment status changed", comment_deleted: "Comment deleted",
  menu_created: "Menu created", menu_updated: "Menu changed", menu_deleted: "Menu deleted", widget_changed: "Widget changed",
  order_created: "Order created", order_status_changed: "Order status changed", order_refunded: "Order refunded",
  order_trashed: "Order moved to Trash", order_restored: "Order restored", order_deleted: "Order deleted",
  product_created: "Product created", product_edited: "Product changed", product_status_changed: "Product status changed",
  product_trashed: "Product moved to Trash", product_restored: "Product restored", product_deleted: "Product deleted",
  coupon_created: "Coupon created", coupon_edited: "Coupon changed", coupon_status_changed: "Coupon status changed",
  coupon_trashed: "Coupon moved to Trash", coupon_restored: "Coupon restored", coupon_deleted: "Coupon deleted",
  woocommerce_setting_changed: "WooCommerce setting changed",
  acf_fields_changed: "Custom fields changed",
  forum_created: "Forum created", forum_edited: "Forum changed", forum_status_changed: "Forum status changed",
  forum_trashed: "Forum moved to Trash", forum_restored: "Forum restored", forum_deleted: "Forum deleted",
  topic_created: "Topic created", topic_edited: "Topic changed", topic_status_changed: "Topic status changed", topic_stickied: "Topic made sticky", topic_unstickied: "Topic unstuck",
  topic_trashed: "Topic moved to Trash", topic_restored: "Topic restored", topic_deleted: "Topic deleted",
  reply_created: "Reply added", reply_edited: "Reply changed", reply_status_changed: "Reply status changed",
  reply_trashed: "Reply moved to Trash", reply_restored: "Reply restored", reply_deleted: "Reply deleted",
  wpforms_form_created: "Form created", wpforms_form_edited: "Form changed", wpforms_form_status_changed: "Form status changed",
  wpforms_form_trashed: "Form moved to Trash", wpforms_form_restored: "Form restored", wpforms_form_deleted: "Form deleted", wpforms_entry_submitted: "Form submission received",
  yoast_seo_meta_changed: "Yoast SEO fields changed", yoast_seo_settings_changed: "Yoast SEO settings changed",
  rank_math_seo_meta_changed: "Rank Math fields changed", rank_math_seo_settings_changed: "Rank Math settings changed",
  membership_changed: "Membership access changed", membership_level_created: "Membership level created", membership_level_updated: "Membership level changed", membership_level_deleted: "Membership level deleted",
  termly_connected: "Termly connected", termly_disconnected: "Termly disconnected", termly_banner_changed: "Consent banner changed", termly_autoblocker_changed: "Automatic blocker changed", termly_scan_schedule_changed: "Automatic scan settings changed", termly_robot_allowlist_changed: "Scanner allow list changed",
  mainwp_site_added: "Child site added", mainwp_site_updated: "Child site changed", mainwp_site_deleted: "Child site removed", mainwp_site_suspended: "Child site suspended", mainwp_site_resumed: "Child site resumed", mainwp_site_sync_succeeded: "Child site synchronized", mainwp_site_sync_failed: "Child site synchronization failed", mainwp_client_created: "Client created", mainwp_client_updated: "Client changed", mainwp_client_deleted: "Client deleted", mainwp_client_suspended: "Client suspended", mainwp_client_resumed: "Client resumed",
  infinitewp_backup_completed: "InfiniteWP backup completed",
  redirect_updated: "Redirect changed", redirect_deleted: "Redirect deleted", redirect_enabled: "Redirect enabled", redirect_disabled: "Redirect disabled",
  plugin_installed: "Plugin installed", plugin_activated: "Plugin activated", plugin_deactivated: "Plugin deactivated",
  plugin_updated: "Plugin updated", plugin_deleted: "Plugin deleted", theme_installed: "Theme installed",
  theme_switched: "Theme switched", theme_updated: "Theme updated", theme_deleted: "Theme deleted",
  setting_changed: "Setting changed", plugin_settings_changed: "Change Vigil settings changed", recording_rules_changed: "Recording rules changed",
};
const eventGroupLabels = {
  content: "Posts and pages", accounts: "Accounts and sign ins", comments: "Comments",
  taxonomies: "Categories and tags", appearance: "Menus and widgets", components: "Plugins and themes",
  settings: "Site settings", woocommerce: "WooCommerce", redirection: "Redirection", acf: "Advanced Custom Fields", bbpress: "bbPress", wpforms: "WPForms", yoast: "Yoast SEO", rank_math: "Rank Math", pmpro: "Paid Memberships Pro", termly: "Termly", mainwp: "MainWP", infinitewp: "InfiniteWP", network: "Network sites", database: "Database", files: "File changes",
};
const eventGroupSettings = {
  content: "capture_content", accounts: "capture_accounts", comments: "capture_comments",
  taxonomies: "capture_taxonomies", appearance: "capture_appearance", components: "capture_components",
  settings: "capture_settings", woocommerce: "woocommerce", redirection: "redirection", acf: "acf", bbpress: "bbpress", wpforms: "wpforms", yoast: "yoast", rank_math: "rank_math", pmpro: "pmpro", termly: "termly", mainwp: "mainwp", infinitewp: "infinitewp", network: "capture_network", database: "capture_database", files: "capture_files",
};
const severities = { info: "Info", notice: "Notice", warning: "Warning" };
const actorKinds = { anonymous: "Unauthenticated", system: "WordPress background task", cli: "WP CLI command", unknown: "Unknown" };
const sourceLabels = { admin: "WordPress admin", rest: "REST API", cron: "WordPress cron", cli: "WP CLI", xmlrpc: "XML RPC", frontend: "Front end", infinitewp: "InfiniteWP", unknown: "Unknown" };
const reportFields = [
  ["event_id", "Event ID"], ["time", "Time"], ["actor", "Actor"], ["actor_role", "Actor role"], ["source", "Request source"],
  ["item", "Affected item"], ["action", "Event type"], ["severity", "Severity"], ["summary", "Summary"], ["details", "Change details"],
];
const allReportColumns = reportFields.map(([key]) => key);
const kindLabels = {
  post: "Post", page: "Page", user: "Account", plugin: "Plugin", theme: "Theme", setting: "Setting", login: "Sign in",
  term: "Term", comment: "Comment", menu: "Navigation menu", widget: "Widget", order: "Order", product: "WooCommerce product", coupon: "WooCommerce coupon", forum: "bbPress forum", topic: "bbPress topic", reply: "bbPress reply", wpforms_form: "WPForms form", pmpro_level: "Membership level", mainwp_site: "MainWP child site", mainwp_client: "MainWP client", backup: "Backup", redirect: "Redirect", table: "Database table", file: "File", ...(config.itemLabels ?? {}),
};
const icons = {
  post: FileText, page: FileText, user: UserRound, plugin: Plug, theme: SwatchBook, setting: Settings2, login: LockKeyhole,
  term: Tag, comment: MessageSquare, menu: Menu, widget: PanelsTopLeft, order: FileText, product: Package, coupon: Tag, forum: MessageSquare, topic: MessageSquare, reply: MessageSquare, mainwp_site: Globe, mainwp_client: UsersRound, backup: Database, redirect: ArrowRight, table: Database, file: FileIcon,
};
const statusNames = { publish: "Published", draft: "Draft", pending: "Pending review", private: "Private", future: "Scheduled", trash: "Trash" };

function actorName(row) {
  return row.actor_name || actorKinds[row.actor_kind] || "Unknown";
}

function actorFilter(row) {
  if (row.actor_kind === "user" && Number(row.actor_id) > 0) return ["actor_id", row.actor_id];
  if (row.actor_kind === "user" && row.actor_name) return ["actor", row.actor_name];
  return ["actor_kind", row.actor_kind];
}

function eventLabel(action) {
  return actions[action] ?? config.actionLabels?.[action] ?? action.replaceAll("_", " ").replace(/\b\w/g, character => character.toUpperCase());
}

function useResource(path, query, refresh = 0) {
  const [state, setState] = useState({ data: null, error: "" });
  useEffect(() => {
    if (!path) return;
    const controller = new AbortController();
    request(path, { query, signal: controller.signal })
      .then(data => setState({ data, error: "", path, query, refresh }))
      .catch(error => {
        if (!controller.signal.aborted) setState({ data: null, error: error.message, path, query, refresh });
      });
    return () => controller.abort();
  }, [path, query, refresh]);
  const current = state.path === path && state.query === query && state.refresh === refresh;
  return current ? state : { data: null, error: "" };
}

function Alert({ children }) {
  return <div role="alert" className="cvsj-alert"><CircleAlert aria-hidden="true" size={18} /><span>{children}</span></div>;
}

function Severity({ value }) {
  return <Badge variant="outline" className={`cvsj-severity cvsj-${value}`}>
    {value === "warning" && <ShieldAlert aria-hidden="true" size={13} />}
    {severities[value] ?? value}
  </Badge>;
}

function SeverityLegend() {
  return <div className="cvsj-severity-legend" role="group" aria-label="Severity legend">
    <span className="cvsj-severity-legend-title">Severity legend</span>
    <span className="cvsj-severity-legend-item"><Severity value="info" /><span>Routine activity</span></span>
    <span className="cvsj-severity-legend-item"><Severity value="notice" /><span>Noteworthy change</span></span>
    <span className="cvsj-severity-legend-item"><Severity value="warning" /><span>Needs attention</span></span>
  </div>;
}

function FilterField({ label, children }) {
  const id = useId();
  return <div className="cvsj-field"><label htmlFor={id}>{label}</label>{cloneElement(children, { id })}</div>;
}

function Filters({ filters, apply, exportUrl, reportUrl }) {
  const [draft, setDraft] = useState(filters);
  const [open, setOpen] = useState(false);
  useEffect(() => setDraft(filters), [filters]);
  const count = Object.entries(filters).filter(([key, value]) => key !== "q" && value).length;
  const active = count > 0 || Boolean(filters.q);
  const field = key => ({
    name: key,
    value: "actor" === key && draft.actor_id ? draft.actor_id : draft[key],
    onChange: event => setDraft(current => {
      const next = { ...current, [key]: event.target.value };
      if (key === "actor") next.actor_id = "";
      if (key === "actor_kind" && event.target.value !== "user") {
        next.actor_id = "";
        if (event.target.value) next.actor = "";
      }
      return next;
    }),
  });
  const clear = () => apply({});
  return <form className="cvsj-filters" onSubmit={event => { event.preventDefault(); apply(draft); }}>
    <div className="cvsj-toolbar">
      <div className="cvsj-search">
        <Search size={18} aria-hidden="true" />
        <Input aria-label="Search activity" placeholder="Search by item, actor, or change" maxLength={255} {...field("q")} />
      </div>
      <Button type="submit">Search</Button>
      <Button type="button" variant="outline" aria-expanded={open} aria-controls="cvsj-filter-fields" onClick={() => setOpen(!open)}>
        <ListFilter aria-hidden="true" /> Filters{count > 0 && <span className="cvsj-filter-count">{count}</span>}
      </Button>
      <Button variant="outline" asChild className="cvsj-export">
        <a href={exportUrl}><ArrowDownToLine aria-hidden="true" /> Export CSV</a>
      </Button>
      {!config.networkMode && <Button variant="outline" asChild>
        <a href={reportUrl}><FileText aria-hidden="true" /> Download HTML report</a>
      </Button>}
    </div>
    <div id="cvsj-filter-fields" hidden={!open}>
      <div className="cvsj-filter-grid">
        <FilterField label="Exact event ID"><Input type="number" min="1" step="1" placeholder="For example, 8421" {...field("event_id")} /></FilterField>
        <FilterField label="From"><Input type="date" {...field("from")} /></FilterField>
        <FilterField label="Through"><Input type="date" {...field("to")} /></FilterField>
        <FilterField label="Actor type"><select {...field("actor_kind")}>
          <option value="">All actors</option><option value="user">User account</option>
          <option value="anonymous">Unauthenticated</option><option value="system">WordPress background task</option><option value="cli">WP CLI command</option><option value="unknown">Unknown</option>
        </select></FilterField>
        <FilterField label="Actor"><Input placeholder="Username or user ID" maxLength={255} disabled={Boolean(draft.actor_kind && draft.actor_kind !== "user")} {...field("actor")} /></FilterField>
        <FilterField label="Actor role"><select {...field("actor_role")}><option value="">All roles</option>{Object.entries(config.roles ?? {}).map(([role, label]) => <option key={role} value={role}>{label}</option>)}</select></FilterField>
        <FilterField label="Source"><select {...field("source")}><option value="">All sources</option>{(config.sources ?? []).map(source => <option key={source} value={source}>{sourceLabels[source] ?? source}</option>)}</select></FilterField>
        <FilterField label="Affected item"><Input placeholder="Title or name" maxLength={255} {...field("item")} /></FilterField>
        <FilterField label="Affected item type"><select {...field("item_kind")}><option value="">All item types</option>{(config.itemKinds ?? []).map(kind => <option key={kind} value={kind}>{kindLabels[kind] ?? kind}</option>)}</select></FilterField>
        <FilterField label="Exact item ID"><Input placeholder="Exact item identifier" maxLength={191} {...field("item_id")} /></FilterField>
        {config.networkMode && <FilterField label="Network site ID"><Input type="number" min="1" step="1" placeholder="All sites" {...field("network_site_id")} /></FilterField>}
        {config.mainwpDashboard && <FilterField label="MainWP child site ID"><Input type="number" min="1" step="1" placeholder="Any child site" {...field("site_id")} /></FilterField>}
        <FilterField label="Action"><select {...field("action")}><option value="">All actions</option>{config.actions.map(key => <option key={key} value={key}>{actions[key]}</option>)}</select></FilterField>
        <FilterField label="Event category"><select {...field("category")}><option value="">All categories</option>{Object.keys(config.actionGroups ?? {}).map(category => <option key={category} value={category}>{eventGroupLabels[category] ?? category}</option>)}</select></FilterField>
        <FilterField label="Severity"><select {...field("severity")}><option value="">All severities</option>{config.severities.map(key => <option key={key} value={key}>{severities[key]}</option>)}</select></FilterField>
      </div>
      {config.networkMode && <p className="cvsj-filter-help">Event IDs and item identifiers are local to each site. Add Network site ID to narrow a result to one site.</p>}
      <div className="cvsj-filter-actions"><Button type="submit">Apply filters</Button></div>
    </div>
    {active && <div className="cvsj-applied">
      <span>Filtered activity</span>
      {Object.entries(filters).filter(([, value]) => value).map(([key, value]) =>
        <span className="cvsj-chip" key={key}>{({ from: "From ", to: "Through ", event_id: "Event ID: ", actor: "Actor: ", actor_id: "Actor: ", actor_kind: "Actor type: ", actor_role: "Actor role: ", source: "Source: ", category: "Category: ", item: "Item: ", item_kind: "Item type: ", item_id: "Item ID: ", site_id: "MainWP child site: ", network_site_id: "Network site: " })[key] ?? ""}{key === "actor_id" ? `User ID ${value}` : key === "event_id" ? `#${value}` : key === "actor_kind" ? value === "user" ? "User account" : actorKinds[value] ?? value : key === "actor_role" ? config.roles?.[value] ?? value : key === "source" ? sourceLabels[value] ?? value : key === "category" ? eventGroupLabels[value] ?? value : key === "item_kind" ? kindLabels[value] ?? value : actions[value] ?? severities[value] ?? value}</span>
      )}
      <button className="cvsj-text-button" type="button" onClick={clear}><X size={14} aria-hidden="true" /> Clear all</button>
    </div>}
  </form>;
}

function EventTable({ events, select, selectedIds = [], toggleSelected = () => {}, selectPage = () => {} }) {
  const allSelected = config.canManage && events.length > 0 && events.every(event => selectedIds.includes(event.id));
  return <Table aria-label="Activity events">
    <TableHeader><TableRow>
      {config.canManage && <TableHead className="cvsj-select-col"><input type="checkbox" aria-label="Select all events on this page" checked={allSelected} onChange={event => selectPage(event.target.checked)} /></TableHead>}
      <TableHead>Change</TableHead><TableHead className="cvsj-actor-col">Actor</TableHead>
      <TableHead className="cvsj-time-col" aria-sort="descending">When <ArrowDown size={13} aria-hidden="true" /></TableHead>
      <TableHead className="cvsj-severity-col">Severity</TableHead>
    </TableRow></TableHeader>
    <TableBody>
      {events.map(row => {
        const Icon = icons[row.item_kind] ?? (config.contentTypes?.includes(row.item_kind) ? FileText : Activity);
        return <TableRow key={config.networkMode ? `${row.network_site_id}:${row.id}` : row.id}>
          {config.canManage && <TableCell className="cvsj-select-col"><input type="checkbox" aria-label={`Select event ${row.id}`} checked={selectedIds.includes(row.id)} onChange={() => toggleSelected(row.id)} /></TableCell>}
          <TableCell>
            <div className="cvsj-event">
              <span className={`cvsj-event-icon cvsj-kind-${row.item_kind}`}><Icon size={18} aria-hidden="true" /></span>
              <div className="cvsj-event-copy">
                <button className="cvsj-event-link" type="button" onClick={event => select(row, event.currentTarget)}>
                  <span>{row.summary}</span>
                  <span className="cvsj-event-meta">{kindLabels[row.item_kind]} <span aria-hidden="true">·</span> {actions[row.action_key] ?? eventLabel(row.action_key)}</span>
                  {Number(row.origin_site_id) > 0 && <span className="cvsj-event-meta">MainWP child site #{row.origin_site_id}</span>}
                  {config.networkMode && <span className="cvsj-event-meta">{row.network_site_name} <span aria-hidden="true">·</span> Site #{row.network_site_id}</span>}
                  <ChevronRight size={15} aria-hidden="true" />
                </button>
                <div className="cvsj-mobile-meta">{actorName(row)} <span aria-hidden="true">·</span> <time dateTime={row.time_iso}>{row.time_short}</time> <Severity value={row.severity} /></div>
              </div>
            </div>
          </TableCell>
          <TableCell className="cvsj-actor-col">{actorName(row)}</TableCell>
          <TableCell className="cvsj-time-col"><time dateTime={row.time_iso} title={row.time_exact}>{row.time_short}</time></TableCell>
          <TableCell className="cvsj-severity-col"><Severity value={row.severity} /></TableCell>
        </TableRow>;
      })}
    </TableBody>
  </Table>;
}

function EventDetails({ row, close, related, trigger, fallback }) {
  const [note, setNote] = useState("");
  const [noteState, setNoteState] = useState({ busy: false, error: "", message: "" });
  const [notesRefresh, setNotesRefresh] = useState(0);
  const noteInputId = useId();
  const eventPath = row ? config.networkMode ? `events/${row.network_site_id}/${row.id}` : `events/${row.id}` : null;
  const notePath = row ? config.networkMode ? `events/${row.network_site_id}/${row.id}/notes` : `events/${row.id}/notes` : null;
  const { data, error } = useResource(eventPath, null, notesRefresh);
  const label = data ?? row;
  const [actorFilterKey, actorFilterValue] = label ? actorFilter(label) : ["actor_kind", "unknown"];
  const compare = data?.details?.changes;
  const fileDetailLabels = { size: "File size in bytes", permissions: "Permissions", checksum_type: "Checksum type", baseline: "Baseline scan", count: "Large files", maximum_mb: "Checksum size limit in megabytes", files_scanned: "Files scanned", large_files: "Large files", skipped_locations: "Skipped locations", reason: "Reason" };
  const fileFacts = row?.item_kind === "file" ? Object.entries(data?.details ?? {}).filter(([key, value]) => fileDetailLabels[key] && ["string", "number", "boolean"].includes(typeof value)) : [];
  useEffect(() => {
    setNote("");
    setNoteState({ busy: false, error: "", message: "" });
  }, [row?.network_site_id, row?.id]);
  async function addNote(event) {
    event.preventDefault();
    setNoteState({ busy: true, error: "", message: "" });
    try {
      await request(notePath, { data: { note } });
      setNote("");
      setNoteState({ busy: false, error: "", message: "Note added." });
      setNotesRefresh(value => value + 1);
    } catch (error) {
      setNoteState({ busy: false, error: error.message, message: "" });
    }
  }
  return <Sheet open={Boolean(row)} onOpenChange={next => { if (!next) close(); }}>
    {label && <SheetContent className="cvsj-sheet" portalContainer={document.getElementById("cvsj-root")} onCloseAutoFocus={event => {
      event.preventDefault();
      if (trigger.current?.isConnected) trigger.current.focus();
      else fallback.current?.focus();
    }}>
      <SheetHeader>
        <Severity value={label.severity} />
        <SheetTitle>{label.summary}</SheetTitle>
        <SheetDescription>Event {label.id} · {actions[label.action_key]}</SheetDescription>
      </SheetHeader>
      <div className="cvsj-sheet-body">
        <dl className="cvsj-details">
          <div><dt>Actor</dt><dd><button type="button" className="cvsj-text-button" onClick={() => related(actorFilterKey, actorFilterValue)}>{actorName(label)} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>
          {config.networkMode && <div><dt>Network site</dt><dd><button type="button" className="cvsj-text-button" onClick={() => related("network_site_id", label.network_site_id)}>{label.network_site_name}, Site #{label.network_site_id} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>}
          <div><dt>Actor role</dt><dd>{label.actor_role
            ? <button type="button" className="cvsj-text-button" onClick={() => related("actor_role", label.actor_role)}>{config.roles?.[label.actor_role] ?? label.actor_role} <ArrowRight size={14} aria-hidden="true" /></button>
            : "Unknown"}</dd></div>
          <div><dt>Affected item</dt><dd><button type="button" className="cvsj-text-button" onClick={() => related("item", `${label.item_kind}:${label.item_key}`)}>{label.item_name} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>
          <div><dt>Item identifier</dt><dd><code>{label.item_key}</code></dd></div>
          {Number(label.origin_site_id) > 0 && <div><dt>MainWP child site</dt><dd><button type="button" className="cvsj-text-button" onClick={() => related("site_id", label.origin_site_id)}>Site #{label.origin_site_id} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>}
          <div><dt>Event type</dt><dd><code>{label.action_key}</code></dd></div>
          <div><dt>Category</dt><dd><button type="button" className="cvsj-text-button" onClick={() => related("category", label.category)}>{eventGroupLabels[label.category] ?? label.category} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>
          <div><dt>Source</dt><dd><button type="button" className="cvsj-text-button" onClick={() => related("source", label.source || "unknown")}>{(sourceLabels[label.source] ?? label.source) || "Unknown"} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>
          <div><dt>Time</dt><dd><time dateTime={label.time_iso}>{label.time_exact}</time></dd></div>
          {data?.details?.version && <div><dt>Component version</dt><dd>{data.details.version}</dd></div>}
        </dl>
        {error && <Alert>{error}</Alert>}
        {!data && !error && <p role="status">Loading event details…</p>}
        {compare && <section className="cvsj-changes">
          <h3>What changed</h3>
          {Object.entries(compare).map(([field, values]) => <div className="cvsj-change" key={field}>
            <h4>{field}</h4>
            <dl>{values.map((value, index) => <div key={index}><dt>{index === 0 ? "Previous" : "New"}</dt>
              <dd>{(field === "Status" ? statusNames[value] ?? value : value) || "(Empty)"}</dd></div>)}</dl>
          </div>)}
        </section>}
        {fileFacts.length > 0 && <section className="cvsj-changes cvsj-file-facts">
          <h3>Recorded details</h3>
          <dl>{fileFacts.map(([key, value]) => <div key={key}><dt>{fileDetailLabels[key]}</dt><dd>{typeof value === "boolean" ? value ? "Yes" : "No" : value}</dd></div>)}</dl>
        </section>}
        {data?.details?.content_changed && (data.revision_url
          ? <Button variant="outline" asChild><a href={data.revision_url}>Compare WordPress revisions <ArrowRight aria-hidden="true" /></a></Button>
          : <p>Content changed. The WordPress revisions needed for a comparison are unavailable.</p>)}
        <section className="cvsj-notes" aria-labelledby="cvsj-notes-heading">
          <h3 id="cvsj-notes-heading">Investigation notes</h3>
          {!data && !error && <p role="status">Loading notes…</p>}
          {data && data.notes.length === 0 && <p>No notes yet. Add context for the next administrator investigating this event.</p>}
          {data?.notes?.length > 0 && <ol aria-live="polite">{data.notes.map(entry => <li key={entry.id}>
            <div className="cvsj-note-meta"><strong>{entry.author_name}</strong><time dateTime={entry.created_iso}>{entry.created_label}</time></div>
            <p>{entry.note}</p>
          </li>)}</ol>}
          {config.canManage && <form onSubmit={addNote}>
              <label htmlFor={noteInputId}>Add a note</label>
              <textarea id={noteInputId} maxLength={2000} rows={4} required value={note} onChange={event => setNote(event.target.value)} aria-describedby={`${noteInputId}-help`} />
              <small id={`${noteInputId}-help`}>Notes are visible to activity viewers and remain with this event until it expires.</small>
              {noteState.error && <Alert>{noteState.error}</Alert>}
              {noteState.message && <p role="status">{noteState.message}</p>}
              <Button disabled={noteState.busy || !note.trim()}>{noteState.busy ? "Adding note…" : "Add note"}</Button>
            </form>}
        </section>
      </div>
    </SheetContent>}
  </Sheet>;
}

function Settings() {
  const { data, error } = useResource("settings");
  const [saved, setSaved] = useState(null);
  const [state, setState] = useState({ busy: false, error: "", message: "" });
  const [draft, setDraft] = useState(null);
  const [transfer, setTransfer] = useState({ busy: false, error: "", message: "", file: null, confirmPrune: false, confirmErase: false });
  const [userSearch, setUserSearch] = useState("");
  const [accessSearch, setAccessSearch] = useState("");
  const current = saved ?? data;
  const values = draft ?? current?.settings;
  const health = current?.health;
  const userQuery = useMemo(() => ({ search: userSearch.trim(), include: values?.excluded_users?.join(",") ?? "" }), [userSearch, values?.excluded_users]);
  const { data: userOptions, error: userOptionsError } = useResource(current ? "users" : null, userQuery);
  const accessIds = useMemo(() => [...new Set([...(values?.viewer_users ?? []), ...(values?.manager_users ?? [])])], [values?.viewer_users, values?.manager_users]);
  const accessQuery = useMemo(() => ({ search: accessSearch.trim(), include: accessIds.join(",") }), [accessSearch, accessIds]);
  const { data: accessUserOptions, error: accessUserError } = useResource(current ? "users" : null, accessQuery);
  const accessUsers = useMemo(() => {
    const choices = [...(accessUserOptions?.selected ?? []), ...(accessUserOptions?.matches ?? [])];
    return [...new Map(choices.map(user => [user.id, user])).values()];
  }, [accessUserOptions]);
  async function save(event) {
    event.preventDefault();
    setState({ busy: true, error: "", message: "" });
    try {
      const result = await request("settings", { data: { ...values, retention: Number(values.retention), file_max_size_mb: Number(values.file_max_size_mb) } });
      setSaved(result);
      setDraft(null);
      setState({ busy: false, error: "", message: "Settings saved." });
    } catch (error) {
      setState({ busy: false, error: error.message, message: "" });
    }
  }
  async function exportSettings() {
    setTransfer({ ...transfer, busy: true, error: "", message: "" });
    try {
      const payload = await request("settings/export");
      const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" }));
      const link = document.createElement("a");
      link.href = url;
      link.download = `activitylog-settings-${new Date().toISOString().slice(0, 10).replaceAll("-", "")}.json`;
      link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      setTransfer({ ...transfer, busy: false, error: "", message: "Settings file downloaded." });
    } catch (error) {
      setTransfer({ ...transfer, busy: false, error: error.message, message: "" });
    }
  }
  async function readSettingsFile(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (file.size > 131072) {
      setTransfer({ busy: false, error: "Settings files must be smaller than 128 KB.", message: "", file: null, confirmPrune: false, confirmErase: false });
      return;
    }
    try {
      const payload = JSON.parse(await file.text());
      if (payload?.plugin !== "change-vigil-site-journal" || payload?.format !== "change-vigil-site-journal-settings" || payload?.schema_version !== 1 || !payload.settings) {
        throw new Error("Choose a supported Change Vigil settings export.");
      }
      setTransfer({ busy: false, error: "", message: "", file: { name: file.name, payload }, confirmPrune: false, confirmErase: false });
    } catch (error) {
      setTransfer({ busy: false, error: error instanceof SyntaxError ? "The selected file is not valid JSON." : error.message, message: "", file: null, confirmPrune: false, confirmErase: false });
    }
  }
  async function importSettings() {
    if (!transfer.file) return;
    setTransfer({ ...transfer, busy: true, error: "", message: "" });
    try {
      const result = await request("settings/import", {
        data: {
          ...transfer.file.payload,
          confirm_prune: transfer.confirmPrune,
          confirm_erase: transfer.confirmErase,
        },
      });
      setSaved(result);
      setDraft(null);
      setTransfer({ busy: false, error: "", message: "Settings imported.", file: null, confirmPrune: false, confirmErase: false });
    } catch (error) {
      setTransfer({ ...transfer, busy: false, error: error.message, message: "" });
    }
  }
  function setEventEnabled(action, enabled) {
    const disabled = new Set(values.disabled_actions ?? []);
    if (enabled) disabled.delete(action);
    else disabled.add(action);
    setDraft({ ...values, disabled_actions: [...disabled] });
  }
  function setRoleEnabled(key, role, enabled) {
    const selected = new Set(values[key] ?? []);
    if (enabled) selected.add(role);
    else selected.delete(role);
    setDraft({ ...values, [key]: [...selected] });
  }
  function setExcludedUser(userId, enabled) {
    const selected = new Set(values.excluded_users ?? []);
    if (enabled) selected.add(userId);
    else selected.delete(userId);
    setDraft({ ...values, excluded_users: [...selected] });
  }
  function setExcludedItem(item, enabled) {
    const selected = new Set(values.excluded_items ?? []);
    if (enabled) selected.add(item);
    else selected.delete(item);
    setDraft({ ...values, excluded_items: [...selected] });
  }
  const importedSettings = transfer.file?.payload.settings;
  const requiresPruneConfirmation = Boolean(importedSettings && current?.settings && importedSettings.retention < current.settings.retention);
  const requiresEraseConfirmation = Boolean(importedSettings?.erase && current?.settings && !current.settings.erase);
  return <div className="cvsj-settings">
    {(error || state.error) && <Alert>{error || state.error}</Alert>}
    {!current && !error && <p role="status">Loading settings…</p>}
    {current && <div className="cvsj-panel cvsj-settings-content">
      <section className="cvsj-settings-panel">
        <h2>Log health</h2>
        {!health.available && <Alert>The activity log table is unavailable. Check the database connection.</Alert>}
        {health.write_error && <Alert>Last event recording failure: {health.write_error}. Some activity may be missing.</Alert>}
        {health.schema_error && <Alert>Last event table schema update failure: {health.schema_error}.</Alert>}
        {health.cleanup_error && <Alert>Last cleanup failure: {health.cleanup_error}.</Alert>}
        {health.schedule_error && <Alert>Last cleanup scheduling failure: {health.schedule_error}.</Alert>}
        {health.alert_error && <Alert>Last warning digest delivery failure: {health.alert_error}.</Alert>}
        {health.alert_schedule_error && <Alert>Last warning digest scheduling failure: {health.alert_schedule_error}.</Alert>}
        {health.alert_rule_error && <Alert>Last immediate alert channel or queue failure: {health.alert_rule_error}.</Alert>}
        {health.report_schedule_error && <Alert>Scheduled report checks could not be queued: {health.report_schedule_error}.</Alert>}
        {values.capture_files && health.file_scan_error && <Alert>The last file scan skipped one or more locations. Review directory permissions and scan exclusions. {health.file_scan_error}</Alert>}
        {values.capture_files && health.file_schedule_error && <Alert>File scan scheduling failed. Save these settings to try scheduling it again. {health.file_schedule_error}</Alert>}
        {!health.next_cleanup && <Alert>Automatic cleanup is unscheduled. Save the settings to try scheduling it again.</Alert>}
        <dl className="cvsj-health">
          <div><dt>Oldest event</dt><dd>{health.oldest || (health.available ? "No events recorded" : "Unavailable")}</dd></div>
          <div><dt>Estimated database space</dt><dd>{health.space ?? "Unavailable"}</dd></div>
          <div><dt>Last cleanup</dt><dd>{health.last_cleanup ?? "Has yet to run"}</dd></div>
          <div><dt>Next scheduled cleanup</dt><dd>{health.next_cleanup ?? "Unscheduled"}</dd></div>
          <div><dt>File scan</dt><dd>{values.capture_files ? health.file_scan_running ? `Scanning, ${health.file_scan_phase || "in progress"}` : "Enabled" : "Off"}</dd></div>
          <div><dt>Last file scan</dt><dd>{values.capture_files ? health.file_scan_last ?? "Has yet to run" : "Not enabled"}</dd></div>
          <div><dt>Next file scan</dt><dd>{values.capture_files ? health.next_file_scan ?? "Unscheduled" : "Not enabled"}</dd></div>
        </dl>
      </section>
      <form className="cvsj-settings-panel" onSubmit={save}>
        <h2>Retention</h2>
        <p>Choose a period that fits your site’s audit needs and privacy obligations. The default is 90 days. Keep the period as short as your purpose allows.</p>
        <label className="cvsj-field cvsj-retention"><span>Keep events for (days)</span>
          <Input type="number" required min="1" max="3650" value={values.retention} onChange={event => setDraft({ ...values, retention: event.target.value })} />
        </label>
        <p>Automatic cleanup deletes events older than this limit. Shortening this period starts cleanup immediately in bounded batches. Scheduled cleanup may run later on quiet sites.</p>
        <p>WordPress privacy tools can export activity linked to a person and erase their notes, access settings, report filters, and email recipients. Matching activity stays for site audit context with account identifiers and personal change details removed.</p>
        <label className="cvsj-check"><input type="checkbox" checked={values.erase} onChange={event => setDraft({ ...values, erase: event.target.checked })} />
          <span><strong>Erase all activity when deleting the plugin</strong><small>Deactivating the plugin preserves your history.</small></span>
        </label>
        <fieldset className="cvsj-settings-group">
          <legend>Event recording</legend>
          <p>Turn off categories you do not need. This affects new events only, existing events remain until cleanup.</p>
          {[
            ["capture_content", "Posts and pages", "Content changes, status, trash, and restoration"],
            ["capture_accounts", "Accounts and sign ins", "Account changes, roles, password changes, and sign ins"],
            ["capture_comments", "Comments", "Comment creation, changes, moderation, and deletion"],
            ["capture_taxonomies", "Categories and tags", "Term changes and assignments"],
            ["capture_appearance", "Menus and widgets", "Menu changes, locations, and widget placement"],
            ["capture_components", "Plugins and themes", "Installation, activation, updates, switching, and deletion"],
            ["capture_settings", "Site settings", "Selected site visibility, role, URL, and permalink settings"],
            ...(config.isMultisite ? [["capture_network", "Network sites", "Site creation, deletion, visibility, and network status changes"]] : []),
            ["capture_database", "Database tables", "Table creation and removal by WordPress and plugins"],
            ["capture_files", "File changes", "New, changed, removed, and permission changes found during scans"],
          ].map(([key, title, description]) => <label className="cvsj-check" key={key}>
            <input type="checkbox" checked={values[key]} onChange={event => setDraft({ ...values, [key]: event.target.checked })} />
            <span><strong>{title}</strong><small>{description}</small></span>
          </label>)}
          <details className="cvsj-event-controls">
            <summary>Choose individual event types</summary>
            <p>Turn off a specific event while keeping other events in its category. Category controls above still apply to every event in that category.</p>
            {Object.entries(config.actionGroups ?? {}).filter(([group]) => group !== "network" || config.isMultisite).map(([group, eventTypes]) => {
              const groupEnabled = Boolean(values[eventGroupSettings[group]]);
              return <fieldset className="cvsj-event-group" key={group} disabled={!groupEnabled}>
                <legend>{eventGroupLabels[group] ?? group}</legend>
                {eventTypes.map(action => <label className="cvsj-check" key={action}>
                  <input type="checkbox" checked={!(values.disabled_actions ?? []).includes(action)} onChange={event => setEventEnabled(action, event.target.checked)} />
                  <span><strong>{eventLabel(action)}</strong></span>
                </label>)}
                {!groupEnabled && <small>Turn on this event category above to record these events.</small>}
              </fieldset>;
            })}
          </details>
        </fieldset>
        <fieldset className="cvsj-settings-group">
          <legend>File monitoring</legend>
          <p>Monitoring is off until enabled. The first scan sets a baseline and creates no file change events. File contents and absolute paths are never stored. Relative file names remain in the local baseline while monitoring is enabled so removals can be detected. Disabling monitoring clears that baseline, existing events follow your retention period.</p>
          <label className="cvsj-check"><input type="checkbox" checked={values.monitor_uploads} onChange={event => setDraft({ ...values, monitor_uploads: event.target.checked })} />
            <span><strong>Include uploads</strong><small>Uploads can contain personal or customer files. Review your privacy obligations before including them.</small></span>
          </label>
          <label className="cvsj-field"><span>Maximum file size for content checksums, in megabytes</span>
            <Input type="number" required min="1" max="50" value={values.file_max_size_mb} disabled={!values.capture_files} onChange={event => setDraft({ ...values, file_max_size_mb: event.target.value })} />
          </label>
          <label className="cvsj-field"><span>Scan frequency</span>
            <select value={values.file_scan_interval} disabled={!values.capture_files} onChange={event => setDraft({ ...values, file_scan_interval: event.target.value })}>
              <option value="daily">Daily</option><option value="weekly">Weekly</option>
            </select>
          </label>
          <label className="cvsj-field"><span>Additional excluded paths</span>
            <textarea rows={4} maxLength={6400} disabled={!values.capture_files} value={(values.file_excluded_paths ?? []).join("\n")} onChange={event => setDraft({ ...values, file_excluded_paths: event.target.value.split("\n").map(path => path.trim()).filter(Boolean).slice(0, 25) })} aria-describedby="cvsj-file-exclusions-help" />
          </label>
          <small id="cvsj-file-exclusions-help">Enter one path relative to the WordPress folder per line, up to 25 paths. Paths cannot contain wildcards or parent folder references. Common cache, backup, dependency, and version control folders are skipped automatically.</small>
          <p>Files up to the selected size are checked with a local SHA 256 checksum. Larger files use size, modified time, and permissions only. Scans process bounded batches. Uploaded file monitoring is separately opt in.</p>
        </fieldset>
        <fieldset className="cvsj-settings-group">
          <legend>Optional integrations</legend>
          <p>These integrations are off until you enable one. Order records contain order numbers and status only. Redirect records contain rule identifiers only, no source or destination addresses.</p>
          <label className="cvsj-check"><input type="checkbox" checked={values.woocommerce} onChange={event => setDraft({ ...values, woocommerce: event.target.checked })} />
            <span><strong>WooCommerce</strong><small>Record products, coupons, order creation, status changes, refunds, and removal, plus selected store settings. Product descriptions are not stored. Customer names, emails, addresses, coupon codes, coupon email restrictions, payment details, and order contents are not recorded.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.redirection} onChange={event => setDraft({ ...values, redirection: event.target.checked })} />
            <span><strong>Redirection</strong><small>Record redirect rule changes without storing the URLs.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.bbpress} onChange={event => setDraft({ ...values, bbpress: event.target.checked })} />
            <span><strong>bbPress</strong><small>Record forum, topic, and reply changes. Forum and reply text, guest contact details, and IP addresses are never stored.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.wpforms} onChange={event => setDraft({ ...values, wpforms: event.target.checked })} />
            <span><strong>WPForms</strong><small>Record form changes and successful stored submissions by form. Form definitions, submitted answers, and individual submission identifiers are never stored. Submission events require WPForms entry storage.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.yoast} onChange={event => setDraft({ ...values, yoast: event.target.checked })} />
            <span><strong>Yoast SEO</strong><small>Record changes to selected SEO fields and titles, metadata, and taxonomy settings. SEO text, keyphrases, canonical addresses, image addresses, and setting values are never stored.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.rank_math} onChange={event => setDraft({ ...values, rank_math: event.target.checked })} />
            <span><strong>Rank Math</strong><small>Record changes to selected SEO fields and titles, metadata, and sitemap settings. SEO text, keyphrases, canonical addresses, image addresses, and setting values are never stored.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.pmpro} onChange={event => setDraft({ ...values, pmpro: event.target.checked })} />
            <span><strong>Paid Memberships Pro</strong><small>Record account membership access and level changes using account and level identifiers only. Level names, contact details, subscription details, and payment data are never stored. Membership access can reveal personal information. Enable this only if needed and choose a retention period that fits its purpose.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.termly} onChange={event => setDraft({ ...values, termly: event.target.checked })} />
            <span><strong>Termly</strong><small>Record consent banner, automatic blocker, scan schedule, scanner allow list, and connection state changes. API keys, account details, cookie maps, scan results, and site addresses are never stored.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.mainwp} onChange={event => setDraft({ ...values, mainwp: event.target.checked })} />
            <span><strong>MainWP</strong><small>Record Dashboard changes and transfer child site activity during normal MainWP sync. Enable this on both the Dashboard and each child site. Child event records include the MainWP site ID, but site addresses, connection credentials, and sync payloads are never stored.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.infinitewp} onChange={event => setDraft({ ...values, infinitewp: event.target.checked })} />
            <span><strong>InfiniteWP</strong><small>Label changes made through signed InfiniteWP requests. Record completed backup operations when InfiniteWP's own activity log is enabled. Only the backup scope is stored, never paths, storage details, or file contents.</small></span>
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.acf} onChange={event => setDraft({ ...values, acf: event.target.checked })} />
            <span><strong>Advanced Custom Fields</strong><small>Record changed field labels on posts, products, and supported custom content. Values are never stored. Labels suggesting personal or secret data are shown as Sensitive custom field. Up to 40 labels appear in each event.</small></span>
          </label>
        </fieldset>
        <fieldset className="cvsj-settings-group">
          <legend>Recording exclusions</legend>
          <p>Any matching rule suppresses a new event. Existing events stay in the log until retention cleanup. User exclusions are site specific and are kept when importing settings.</p>
          <label className="cvsj-field"><span>Find users to exclude</span><Input aria-label="Find users to exclude" value={userSearch} maxLength={80} onChange={event => setUserSearch(event.target.value)} placeholder="Search by name or username" /></label>
          {userOptionsError && <Alert>{userOptionsError}</Alert>}
          {userSearch.trim().length < 2 && <small className="cvsj-muted">Enter at least two characters to search accounts.</small>}
          {userSearch.trim().length >= 2 && userOptions && userOptions.matches.length === 0 && <p>No accounts match this search.</p>}
          {userOptions?.matches?.length > 0 && <div className="cvsj-exclusion-grid">{userOptions.matches.map(user => <label className="cvsj-check" key={user.id}>
            <input type="checkbox" checked={(values.excluded_users ?? []).includes(user.id)} onChange={event => setExcludedUser(user.id, event.target.checked)} />
            <span><strong>{user.name}</strong><small>@{user.login}</small></span>
          </label>)}</div>}
          {(values.excluded_users ?? []).length > 0 && <div className="cvsj-selected-exclusions">
            <strong>Excluded accounts</strong>
            <ul>{values.excluded_users.map(userId => {
              const user = userOptions?.selected?.find(entry => entry.id === userId);
              const label = user ? `${user.name} (@${user.login})` : `User ID ${userId}`;
              return <li key={userId}><span>{label}</span><Button type="button" variant="ghost" onClick={() => setExcludedUser(userId, false)}>Remove</Button></li>;
            })}</ul>
          </div>}
          {(values.excluded_items ?? []).length > 0 && <div className="cvsj-selected-exclusions">
            <strong>Excluded items</strong>
            <p>These exact items will not create new events. Existing history is kept.</p>
            <ul>{values.excluded_items.map(item => {
              const [kind, ...keyParts] = item.split(":");
              const key = keyParts.join(":");
              return <li key={item}><span>{config.itemLabels?.[kind] ?? kind}, identifier {key}</span><Button type="button" variant="ghost" onClick={() => setExcludedItem(item, false)}>Remove</Button></li>;
            })}</ul>
          </div>}
          <div className="cvsj-exclusion-grid">{Object.entries(config.roles ?? {}).filter(([role]) => role !== "administrator").map(([role, label]) => <label className="cvsj-check" key={role}>
            <input type="checkbox" checked={(values.excluded_roles ?? []).includes(role)} onChange={event => setRoleEnabled("excluded_roles", role, event.target.checked)} />
            <span><strong>{label}</strong><small>Ignore activity performed by this role.</small></span>
          </label>)}</div>
          <div className="cvsj-exclusion-grid">{Object.entries(config.itemLabels ?? {}).map(([kind, label]) => <label className="cvsj-check" key={kind}>
            <input type="checkbox" checked={(values.excluded_item_kinds ?? []).includes(kind)} onChange={event => setRoleEnabled("excluded_item_kinds", kind, event.target.checked)} />
            <span>{label}</span>
          </label>)}</div>
          <div className="cvsj-exclusion-grid">{(config.postStatuses ?? []).map(status => <label className="cvsj-check" key={status}>
            <input type="checkbox" checked={(values.excluded_post_statuses ?? []).includes(status)} onChange={event => setRoleEnabled("excluded_post_statuses", status, event.target.checked)} />
            <span>{statusNames[status] ?? status}</span>
          </label>)}</div>
          <label className="cvsj-check"><input type="checkbox" checked={values.exclude_system_activity} onChange={event => setDraft({ ...values, exclude_system_activity: event.target.checked })} />
            <span><strong>Ignore WordPress background tasks</strong><small>Do not record events without a signed in user when WordPress cron is running.</small></span>
          </label>
        </fieldset>
        <fieldset className="cvsj-settings-group">
          <legend>Activity log access</legend>
          <p>Administrators always have full access. Viewer roles or users can read and export activity. Manager roles or users can also add investigation notes and change plugin settings.</p>
          {Object.entries(config.roles ?? {}).filter(([role]) => role !== "administrator").map(([role, label]) => <div className="cvsj-role-access" key={role}>
            <strong>{label}</strong>
            <label className="cvsj-check"><input type="checkbox" checked={(values.viewer_roles ?? []).includes(role)} onChange={event => setRoleEnabled("viewer_roles", role, event.target.checked)} /><span>{label} can view and export activity</span></label>
            <label className="cvsj-check"><input type="checkbox" checked={(values.manager_roles ?? []).includes(role)} onChange={event => setRoleEnabled("manager_roles", role, event.target.checked)} /><span>{label} can add notes and manage settings</span></label>
          </div>)}
          <label className="cvsj-field"><span>Find users to grant access</span><Input aria-label="Find users to grant access" value={accessSearch} maxLength={80} onChange={event => setAccessSearch(event.target.value)} placeholder="Search by name or username" /></label>
          {accessUserError && <Alert>{accessUserError}</Alert>}
          {accessSearch.trim().length < 2 && <small className="cvsj-muted">Enter at least two characters to search accounts.</small>}
          {accessSearch.trim().length >= 2 && accessUserOptions && accessUserOptions.matches.length === 0 && <p>No accounts match this search.</p>}
          {accessUsers.length > 0 && <div className="cvsj-access-user-list" aria-label="Users with direct access">{accessUsers.map(user => <div className="cvsj-access-user" key={user.id}>
            <strong>{user.name} <span>@{user.login}</span></strong>
            <label className="cvsj-check"><input type="checkbox" checked={(values.viewer_users ?? []).includes(user.id)} onChange={event => setRoleEnabled("viewer_users", user.id, event.target.checked)} /><span>{user.name} can view and export activity</span></label>
            <label className="cvsj-check"><input type="checkbox" checked={(values.manager_users ?? []).includes(user.id)} onChange={event => setRoleEnabled("manager_users", user.id, event.target.checked)} /><span>{user.name} can add notes and manage settings</span></label>
          </div>)}</div>}
        </fieldset>
        <fieldset className="cvsj-settings-group">
          <legend>Notifications</legend>
          <label className="cvsj-check"><input type="checkbox" checked={values.email_alerts} onChange={event => setDraft({ ...values, email_alerts: event.target.checked })} />
            <span><strong>Email warning digests</strong><small>Send selected warning activity no more often than once every 15 minutes. WordPress must be able to send email.</small></span>
          </label>
          <label className="cvsj-field"><span>Digest recipients</span>
            <Input aria-label="Digest recipients" type="text" maxLength={2540} placeholder="Use the WordPress admin email" value={(values.email_alert_recipients ?? []).join(", ")} disabled={!values.email_alerts} onChange={event => setDraft({ ...values, email_alert_recipients: event.target.value.split(",").map(email => email.trim()).filter(Boolean).slice(0, 10) })} />
          </label>
          <small>Enter up to ten email addresses separated by commas. Leave this empty to use the WordPress admin email. Recipients stay on this site and are not included in settings downloads.</small>
          <label className="cvsj-field"><span>Maximum event summaries per digest</span>
            <Input aria-label="Maximum event summaries per digest" type="number" required min="1" max="20" value={values.email_alert_event_limit} disabled={!values.email_alerts} onChange={event => setDraft({ ...values, email_alert_event_limit: event.target.value })} />
          </label>
          <label className="cvsj-check"><input type="checkbox" checked={values.email_alert_include_events} disabled={!values.email_alerts} onChange={event => setDraft({ ...values, email_alert_include_events: event.target.checked })} />
            <span><strong>Include event summaries</strong><small>Turn this off to send a warning notice without event summaries.</small></span>
          </label>
          <fieldset className="cvsj-event-group" disabled={!values.email_alerts}>
            <legend>Include warning categories</legend>
            <div className="cvsj-exclusion-grid">{Object.keys(config.actionGroups ?? {}).map(category => <label className="cvsj-check" key={category}>
              <input type="checkbox" checked={(values.email_alert_categories ?? []).includes(category)} onChange={event => {
                const selected = new Set(values.email_alert_categories ?? []);
                if (event.target.checked) selected.add(category);
                else selected.delete(category);
                setDraft({ ...values, email_alert_categories: [...selected] });
              }} />
              <span>{eventGroupLabels[category] ?? category}</span>
            </label>)}</div>
          </fieldset>
        </fieldset>
        <fieldset className="cvsj-settings-group cvsj-transfer">
          <legend>Settings transfer</legend>
          <p>Move configuration between sites. Activity records, saved reports, notification recipients, and sensitive credentials are not included. User exclusions and direct user access stay on this site because account IDs differ. Importing a shorter retention period or enabling erase on uninstall requires an extra confirmation.</p>
          <div className="cvsj-transfer-actions">
            <Button type="button" variant="outline" disabled={transfer.busy} onClick={exportSettings}>{transfer.busy ? "Working…" : "Download settings"}</Button>
            <label className="cvsj-file-field"><span>Choose a settings file</span><input type="file" accept="application/json,.json" onChange={readSettingsFile} /></label>
          </div>
          {transfer.file && <div className="cvsj-import-preview" aria-live="polite">
            <strong>{transfer.file.name}</strong>
            <p>Import configuration with a retention period of {importedSettings.retention} days. This replaces the current plugin settings.</p>
            {requiresPruneConfirmation && <label className="cvsj-check"><input type="checkbox" checked={transfer.confirmPrune} onChange={event => setTransfer({ ...transfer, confirmPrune: event.target.checked })} /><span><strong>Confirm shorter retention</strong><small>Older activity may be deleted as cleanup runs.</small></span></label>}
            {requiresEraseConfirmation && <label className="cvsj-check"><input type="checkbox" checked={transfer.confirmErase} onChange={event => setTransfer({ ...transfer, confirmErase: event.target.checked })} /><span><strong>Confirm erase on uninstall</strong><small>Deleting the plugin will remove this site’s stored activity and settings.</small></span></label>}
            <Button type="button" disabled={transfer.busy || (requiresPruneConfirmation && !transfer.confirmPrune) || (requiresEraseConfirmation && !transfer.confirmErase)} onClick={importSettings}>{transfer.busy ? "Importing…" : "Apply imported settings"}</Button>
          </div>}
          {transfer.error && <Alert>{transfer.error}</Alert>}
          {transfer.message && <p role="status">{transfer.message}</p>}
        </fieldset>
        <div className="cvsj-save"><Button disabled={state.busy}>{state.busy ? "Saving…" : "Save settings"}</Button>
          <span role="status">{state.message}</span></div>
      </form>
    </div>}
    <AlertChannelManager />
    <AlertRuleManager />
    <div className="cvsj-version">Change Vigil Site Journal · Version {config.version}</div>
  </div>;
}

function AlertChannelManager() {
  const [refresh, setRefresh] = useState(0);
  const [slackWebhook, setSlackWebhook] = useState("");
  const [clearSlack, setClearSlack] = useState(false);
  const [accountSid, setAccountSid] = useState("");
  const [authToken, setAuthToken] = useState("");
  const [smsFrom, setSmsFrom] = useState("");
  const [smsRecipients, setSmsRecipients] = useState("");
  const [clearSmsRecipients, setClearSmsRecipients] = useState(false);
  const [clearSms, setClearSms] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const resource = useResource("alerts/channels", null, refresh);

  async function saveChannels(event) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    const data = {
      slack_webhook: slackWebhook.trim(),
      clear_slack: clearSlack,
      twilio_account_sid: accountSid.trim(),
      twilio_auth_token: authToken.trim(),
      sms_from: smsFrom.trim(),
      clear_sms: clearSms,
    };
    const numbers = smsRecipients.split(/[,;\n]/).map(value => value.trim()).filter(Boolean);
    if (numbers.length || clearSmsRecipients) data.sms_recipients = numbers;
    try {
      await request("alerts/channels", { data });
      setSlackWebhook("");
      setClearSlack(false);
      setAccountSid("");
      setAuthToken("");
      setSmsFrom("");
      setSmsRecipients("");
      setClearSmsRecipients(false);
      setClearSms(false);
      setMessage("Notification settings saved. Secret values are never returned to this screen.");
      setRefresh(value => value + 1);
    } catch (saveError) {
      setError(saveError.message);
    } finally {
      setBusy(false);
    }
  }

  async function testChannel(channel) {
    if (channel === "sms" && !window.confirm("Send a test SMS to the first configured number? Twilio may charge for this message.")) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await request("alerts/channels/test", { data: { channel } });
      setMessage(channel === "sms" ? "Twilio accepted the test SMS." : "Slack accepted the test message.");
    } catch (testError) {
      setError(testError.message);
    } finally {
      setBusy(false);
    }
  }

  const channels = resource.data?.channels;
  return <section className="cvsj-channel-manager" aria-labelledby="cvsj-alert-channels-heading">
    <h2 id="cvsj-alert-channels-heading">Notification channels</h2>
    <p>Send selected event summaries, actor names, and affected item names to services you configure. Slack and Twilio credentials and SMS numbers are encrypted with this site’s WordPress keys and are excluded from settings downloads. They remain until removed here, and SMS numbers cannot be matched to a WordPress email address in a privacy request. If those keys change, enter the credentials again.</p>
    {error && <Alert>{error}</Alert>}{message && <p role="status">{message}</p>}
    {resource.error && <Alert>{resource.error}</Alert>}
    {channels?.needs_reentry && <Alert>Saved channel credentials could not be unlocked. Enter them again to reconnect the services.</Alert>}
    {channels?.delivery_paused && <Alert>Custom alert delivery is paused while a privacy erasure is incomplete. Retry the erasure to safely resume notifications.</Alert>}
    <form className="cvsj-alert-channel-form" onSubmit={saveChannels}>
      <fieldset>
        <legend>Slack</legend>
        <label className="cvsj-field"><span>Incoming webhook URL</span><Input type="url" autoComplete="off" value={slackWebhook} onChange={event => setSlackWebhook(event.target.value)} placeholder={channels?.slack_configured ? "A webhook is already saved, leave blank to keep it" : "https://hooks.slack.com/services/…"} /></label>
        <div className="cvsj-alert-channel-actions">
          <Button type="button" variant="outline" disabled={busy || !channels?.slack_configured} onClick={() => testChannel("slack")}>Send Slack test</Button>
          {channels?.slack_configured && <label><input type="checkbox" checked={clearSlack} onChange={event => setClearSlack(event.target.checked)} /> Remove saved Slack webhook</label>}
        </div>
      </fieldset>
      <fieldset>
        <legend>SMS through Twilio</legend>
        <p className="description">SMS sends the test to the first configured number. Message and carrier fees may apply.</p>
        <label className="cvsj-field"><span>Account SID</span><Input autoComplete="off" value={accountSid} onChange={event => setAccountSid(event.target.value)} placeholder={channels?.sms_configured ? "A SID is already saved, leave blank to keep it" : "AC followed by 32 characters"} /></label>
        <label className="cvsj-field"><span>Authentication token</span><Input type="password" autoComplete="new-password" value={authToken} onChange={event => setAuthToken(event.target.value)} placeholder={channels?.sms_configured ? "A token is already saved, leave blank to keep it" : "Enter the Twilio token"} /></label>
        <label className="cvsj-field"><span>Sender number or Messaging Service SID</span><Input value={smsFrom} onChange={event => setSmsFrom(event.target.value)} placeholder={channels?.sms_configured ? "A sender is already saved, leave blank to keep it" : "+353123456789 or MG SID"} /></label>
        <label className="cvsj-field"><span>SMS recipient numbers</span><Input value={smsRecipients} onChange={event => setSmsRecipients(event.target.value)} placeholder={channels?.sms_configured ? String(channels.sms_recipient_count) + " saved, enter numbers to replace" : "+353123456789"} /><small>Use E.164 format, separate up to ten numbers with commas.</small></label>
        {channels?.sms_recipient_count > 0 && <label><input type="checkbox" checked={clearSmsRecipients} onChange={event => setClearSmsRecipients(event.target.checked)} /> Remove saved recipient numbers only</label>}
        <div className="cvsj-alert-channel-actions">
          <Button type="button" variant="outline" disabled={busy || !channels?.sms_configured} onClick={() => testChannel("sms")}>Send SMS test</Button>
          {channels?.sms_configured && <label><input type="checkbox" checked={clearSms} onChange={event => setClearSms(event.target.checked)} /> Remove saved Twilio settings and numbers</label>}
        </div>
      </fieldset>
      <Button disabled={busy}>{busy ? "Saving…" : "Save notification channels"}</Button>
      {channels && <p role="status">Slack {channels.slack_configured ? "connected" : "not configured"}. SMS {channels.sms_configured ? "configured for " + channels.sms_recipient_count + " numbers" : "not configured"}{channels.sms_sender_masked ? ", sender " + channels.sms_sender_masked : ""}.</p>}
    </form>
  </section>;
}

function AlertRuleManager() {
  const [refresh, setRefresh] = useState(0);
  const [name, setName] = useState("");
  const [logic, setLogic] = useState("all");
  const [channels, setChannels] = useState(["email"]);
  const [conditions, setConditions] = useState([{ field: "action_key", operator: "equals", value: "" }]);
  const [recipients, setRecipients] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const resource = useResource("alerts/rules", null, refresh);
  const channelResource = useResource("alerts/channels", null, refresh);

  function usePreset(preset) {
    setName(preset.name);
    setLogic(preset.logic);
    setConditions(preset.conditions.map(condition => ({ ...condition })));
    document.getElementById("cvsj-alert-rule-name")?.focus();
  }

  function updateCondition(index, key, value) {
    setConditions(previous => previous.map((condition, position) => position === index ? { ...condition, [key]: value } : condition));
  }

  async function createRule(event) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const addresses = recipients.split(/[,;\n]/).map(value => value.trim()).filter(Boolean);
      await request("alerts/rules", { data: { name, enabled: true, logic, conditions, channels, recipients: channels.includes("email") ? addresses : [] } });
      setName("");
      setLogic("all");
      setChannels(["email"]);
      setConditions([{ field: "action_key", operator: "equals", value: "" }]);
      setRecipients("");
      setMessage("Immediate alert rule saved.");
      setRefresh(value => value + 1);
    } catch (saveError) {
      setError(saveError.message);
    } finally {
      setBusy(false);
    }
  }

  function toggleChannel(channel) {
    setChannels(previous => previous.includes(channel) ? previous.filter(value => value !== channel) : [...previous, channel]);
  }

  async function toggleRule(rule) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await request(`alerts/rules/${rule.id}/toggle`, { data: { enabled: !rule.enabled } });
      setMessage(rule.enabled ? "Alert rule paused." : "Alert rule enabled.");
      setRefresh(value => value + 1);
    } catch (toggleError) {
      setError(toggleError.message);
    } finally {
      setBusy(false);
    }
  }

  async function deleteRule(rule) {
    if (!window.confirm(`Delete the alert rule “${rule.name}”?`)) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await request(`alerts/rules/${rule.id}/delete`, { data: {} });
      setMessage("Alert rule deleted.");
      setRefresh(value => value + 1);
    } catch (deleteError) {
      setError(deleteError.message);
    } finally {
      setBusy(false);
    }
  }

  return <section className="cvsj-alert-rules" aria-labelledby="cvsj-alert-rules-heading">
    <h2 id="cvsj-alert-rules-heading">Immediate alerts</h2>
    <p>Build rules that match every condition or any condition. Choose one or more configured channels. Messages include the event summary and actor details. Email recipients stay on this site and are excluded from settings downloads. Failed delivery is retried twice.</p>
    {error && <Alert>{error}</Alert>}{message && <p role="status">{message}</p>}
    {resource.error && <Alert>{resource.error}</Alert>}
    {!resource.data && !resource.error && <p role="status">Loading alert rules…</p>}
    {resource.data?.rules.length === 0 && <p>No immediate email rules are configured.</p>}
    {resource.data?.rules.length > 0 && <ul className="cvsj-alert-rule-list">{resource.data.rules.map(rule => <li key={rule.id}>
      <div><strong>{rule.name}</strong><small>{rule.enabled ? "Enabled" : "Paused"}, {rule.logic === "all" ? "all conditions" : "any condition"}, {rule.conditions.length} conditions</small><small>Channels: {rule.channels.join(", ")}{rule.channels.includes("email") ? ", to " + rule.recipients.join(", ") : ""}</small></div>
      <div className="cvsj-alert-rule-actions"><Button type="button" variant="outline" disabled={busy} onClick={() => toggleRule(rule)}>{rule.enabled ? "Pause" : "Enable"}</Button><Button type="button" variant="ghost" disabled={busy} onClick={() => deleteRule(rule)}>Delete</Button></div>
    </li>)}</ul>}
    {resource.data?.presets.length > 0 && <div className="cvsj-alert-presets" aria-label="Recommended alert rules">
      <strong>Start from a recommended rule</strong>
      <div>{resource.data.presets.map(preset => <Button key={preset.id} type="button" variant="outline" disabled={busy} onClick={() => usePreset(preset)}>Use {preset.name}</Button>)}</div>
    </div>}
    <form className="cvsj-alert-rule-form" onSubmit={createRule}>
      <h3>Create an alert rule</h3>
      <label className="cvsj-field"><span>Rule name</span><Input id="cvsj-alert-rule-name" required maxLength={80} value={name} onChange={event => setName(event.target.value)} /></label>
      <label className="cvsj-field"><span>Match</span><select value={logic} onChange={event => setLogic(event.target.value)}><option value="all">Every condition</option><option value="any">Any condition</option></select></label>
      <fieldset className="cvsj-alert-channel-choice"><legend>Send through</legend>
        <label><input type="checkbox" checked={channels.includes("email")} onChange={() => toggleChannel("email")} /> Email</label>
        <label><input type="checkbox" checked={channels.includes("slack")} disabled={!channelResource.data?.channels.slack_configured} onChange={() => toggleChannel("slack")} /> Slack {!channelResource.data?.channels.slack_configured && "(configure above first)"}</label>
        <label><input type="checkbox" checked={channels.includes("sms")} disabled={!channelResource.data?.channels.sms_configured} onChange={() => toggleChannel("sms")} /> SMS {!channelResource.data?.channels.sms_configured && "(configure above first)"}</label>
      </fieldset>
      <div className="cvsj-alert-condition-list" aria-label="Alert conditions">{conditions.map((condition, index) => <div className="cvsj-alert-condition" key={index}>
        <label className="cvsj-field"><span>Event field</span><select value={condition.field} onChange={event => updateCondition(index, "field", event.target.value)}>{(resource.data?.fields ?? []).map(field => <option key={field.id} value={field.id}>{field.label}</option>)}</select></label>
        <label className="cvsj-field"><span>Comparison</span><select value={condition.operator} onChange={event => updateCondition(index, "operator", event.target.value)}><option value="equals">Is</option><option value="contains">Contains</option><option value="starts_with">Starts with</option></select></label>
        <label className="cvsj-field"><span>Value</span><Input required maxLength={120} value={condition.value} onChange={event => updateCondition(index, "value", event.target.value)} /></label>
        <Button type="button" variant="ghost" disabled={busy || conditions.length === 1} onClick={() => setConditions(previous => previous.filter((_, position) => position !== index))}>Remove condition</Button>
      </div>)}</div>
      <Button type="button" variant="outline" disabled={busy || conditions.length >= 8} onClick={() => setConditions(previous => [...previous, { field: "action_key", operator: "equals", value: "" }])}>Add condition</Button>
      {channels.includes("email") && <label className="cvsj-field"><span>Email recipients</span><Input required maxLength={2550} value={recipients} onChange={event => setRecipients(event.target.value)} placeholder="admin@example.com, security@example.com" /><small>Enter up to ten addresses separated by commas. These people will receive matching activity summaries.</small></label>}
      <Button disabled={busy || channels.length === 0}>{busy ? "Saving…" : "Save alert rule"}</Button>
    </form>
  </section>;
}

function ActivityView({ filters, apply, heading, initialEventId }) {
  const [cursors, setCursors] = useState([{}]);
  const [refresh, setRefresh] = useState(0);
  const [row, setRow] = useState(null);
  const [selectedIds, setSelectedIds] = useState([]);
  const [bulkAction, setBulkAction] = useState("");
  const [bulkState, setBulkState] = useState({ busy: false, error: "", message: "" });
  const trigger = useRef(null);
  const openedFromLink = useRef("");
  const cursor = cursors.at(-1);
  const query = useMemo(() => ({ ...filters, ...cursor }), [filters, cursor]);
  const { data, error } = useResource("events", query, refresh);
  useEffect(() => setSelectedIds([]), [data]);
  useEffect(() => {
    if (!initialEventId || !data || openedFromLink.current === initialEventId) return;
    const match = data.events.find(event => String(event.id) === initialEventId);
    if (match) {
      openedFromLink.current = initialEventId;
      setRow(match);
    }
  }, [initialEventId, data]);
  const active = Object.values(filters).some(Boolean);
  const exportUrl = apiUrl("export", { ...filters, _wpnonce: config.nonce });
  const reportUrl = apiUrl("report", { ...filters, _wpnonce: config.nonce });
  const selectedRows = (data?.events ?? []).filter(event => selectedIds.includes(event.id));
  async function applyBulkAction() {
    const unique = values => [...new Set(values)];
    let updates = {};
    let count = 0;
    if (bulkAction === "exclude_accounts") {
      const ids = unique(selectedRows.filter(event => event.actor_kind === "user" && Number(event.actor_id) > 0).map(event => Number(event.actor_id)));
      if (!ids.length) {
        setBulkState({ busy: false, error: "Select at least one event made by a user account.", message: "" });
        return;
      }
      updates = { excluded_users: ids };
      count = ids.length;
    } else if (bulkAction === "exclude_items") {
      const items = unique(selectedRows.map(event => `${event.item_kind}:${event.item_key}`).filter(item => !item.endsWith(":")));
      if (!items.length) {
        setBulkState({ busy: false, error: "The selected events do not identify an item that can be excluded.", message: "" });
        return;
      }
      updates = { excluded_items: items };
      count = items.length;
    } else if (bulkAction === "disable_actions") {
      const actionsToDisable = unique(selectedRows.map(event => event.action_key).filter(Boolean));
      if (!actionsToDisable.length) {
        setBulkState({ busy: false, error: "Select at least one event type.", message: "" });
        return;
      }
      updates = { disabled_actions: actionsToDisable };
      count = actionsToDisable.length;
    } else {
      return;
    }
    const descriptions = { exclude_accounts: "Exclude selected accounts from future recording", exclude_items: "Exclude selected items from future recording", disable_actions: "Stop recording the selected event types" };
    if (!window.confirm(`${descriptions[bulkAction]}? This will not remove existing history.`)) return;
    setBulkState({ busy: true, error: "", message: "" });
    try {
      const current = await request("settings");
      const merged = Object.fromEntries(Object.entries(updates).map(([key, values]) => [key, unique([...(current.settings[key] ?? []), ...values])]));
      await request("settings", { data: { ...current.settings, ...merged } });
      setSelectedIds([]);
      setBulkAction("");
      setBulkState({ busy: false, error: "", message: `Future recording rules updated for ${count} ${bulkAction === "exclude_accounts" ? "accounts" : bulkAction === "exclude_items" ? "items" : "event types"}. Existing history was kept.` });
    } catch (bulkError) {
      setBulkState({ busy: false, error: bulkError.message, message: "" });
    }
  }
  return <>
    <section className="cvsj-panel" aria-label="Activity log">
      <Filters key={JSON.stringify(filters)} filters={filters} apply={apply} exportUrl={exportUrl} reportUrl={reportUrl} />
      {config.canManage && selectedIds.length > 0 && <div className="cvsj-bulk-actions" aria-label="Selected event actions">
        <span>{selectedIds.length} {selectedIds.length === 1 ? "event" : "events"} selected</span>
        <label htmlFor="cvsj-bulk-action">Apply to selection</label>
        <select id="cvsj-bulk-action" value={bulkAction} onChange={event => setBulkAction(event.target.value)}>
          <option value="">Choose an action</option>
          <option value="exclude_accounts">Exclude selected accounts</option>
          <option value="exclude_items">Exclude selected items</option>
          <option value="disable_actions">Stop recording these event types</option>
        </select>
        <Button type="button" variant="outline" disabled={bulkState.busy || !bulkAction} onClick={applyBulkAction}>{bulkState.busy ? "Applying…" : "Apply"}</Button>
      </div>}
      {bulkState.error && <Alert>{bulkState.error}</Alert>}
      {bulkState.message && <p role="status">{bulkState.message}</p>}
      <div className="cvsj-results">
        <p aria-live="polite">{data ? `${data.events.length} ${data.events.length === 1 ? "event" : "events"} on this page` : "Activity events"}</p>
        <Button variant="ghost" onClick={() => setRefresh(value => value + 1)}><RefreshCw aria-hidden="true" /> Refresh</Button>
      </div>
      {error ? <Alert>{error}</Alert> : data?.events.length
        ? <EventTable events={data.events} select={(event, button) => { trigger.current = button; setRow(event); }} selectedIds={selectedIds}
          toggleSelected={id => setSelectedIds(current => current.includes(id) ? current.filter(value => value !== id) : [...current, id])}
          selectPage={selected => setSelectedIds(selected ? data.events.map(event => event.id) : [])} />
        : <div className="cvsj-empty">
          {data ? <CircleHelp size={25} aria-hidden="true" /> : <Clock3 size={25} aria-hidden="true" />}
          <h2 role={data ? undefined : "status"}>{data ? (active ? "No matching events" : "No activity recorded yet") : "Loading activity…"}</h2>
          {data && <p>{active ? "Try a different search or clear the filters." : "New events will appear here as changes happen in WordPress."}</p>}
          {data && active && <Button variant="outline" onClick={() => apply({})}>Clear filters</Button>}
        </div>}
      {data && <SeverityLegend />}
      {data && (cursors.length > 1 || data.next) && <div className="cvsj-pagination" aria-label="Activity pages">
        <span>Page {cursors.length}</span>
        <Button variant="outline" disabled={cursors.length === 1} onClick={() => setCursors(previous => previous.slice(0, -1))}><ArrowLeft aria-hidden="true" /> Newer</Button>
        <Button variant="outline" disabled={!data.next} onClick={() => setCursors(previous => [...previous, { before: data.next }])}>Older <ArrowRight aria-hidden="true" /></Button>
      </div>}
    </section>
    <EventDetails row={row} trigger={trigger} fallback={heading} close={() => {
      setRow(null);
      const url = new URL(location.href);
      url.searchParams.delete("event");
      history.replaceState(null, "", url);
    }} related={(key, value) => {
      setRow(null);
      apply(config.networkMode && key === "item" ? { network_site_id: row.network_site_id, [key]: value } : { [key]: value });
      requestAnimationFrame(() => heading.current?.focus());
    }} />
  </>;
}

function siteToday() {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: config.timezone || undefined,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
}

function shiftDate(value, days) {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
}

function dateRangeDays(from, to) {
  const start = Date.parse(`${from}T00:00:00Z`);
  const end = Date.parse(`${to}T00:00:00Z`);
  return Math.max(1, Math.floor((end - start) / 86400000) + 1);
}

function statisticLabel(row, group, interval) {
  if (group === "category") return eventGroupLabels[row.label] ?? row.label;
  if (group === "action") return eventLabel(row.label);
  if (group === "actor_role") return config.roles?.[row.label] ?? row.label;
  if (group === "source") return sourceLabels[row.label] ?? row.label;
  if (group === "day" && interval !== "daily") return row.label;
  return row.label;
}

function Reports({ filters, apply, initialGroup, initialInterval }) {
  const today = siteToday();
  const [from, setFrom] = useState(filters.from || shiftDate(today, -29));
  const [to, setTo] = useState(filters.to || today);
  const [group, setGroup] = useState(initialGroup || "category");
  const [interval, setInterval] = useState(initialInterval || "daily");
  const [refresh, setRefresh] = useState(0);
  const [savedRefresh, setSavedRefresh] = useState(0);
  const [expandedSnapshot, setExpandedSnapshot] = useState("");
  const [savedName, setSavedName] = useState("");
  const [reportTags, setReportTags] = useState("");
  const [reportTitle, setReportTitle] = useState("Activity report");
  const [reportColumns, setReportColumns] = useState(allReportColumns);
  const [reportBranding, setReportBranding] = useState(false);
  const [savedBusy, setSavedBusy] = useState(false);
  const [savedError, setSavedError] = useState("");
  const [savedMessage, setSavedMessage] = useState("");
  const query = useMemo(() => ({ ...filters, from, to, group, interval }), [filters, from, to, group, interval]);
  const { data, error } = useResource("reports/statistics", query, refresh);
  const savedResource = useResource("reports/saved", null, savedRefresh);
  const eventReportUrl = apiUrl("report", {
    ...filters, from, to, title: reportTitle.trim() || "Activity report", columns: reportColumns.join(","),
    branding: reportBranding ? "1" : "0", _wpnonce: config.nonce,
  });

  function run(event) {
    event.preventDefault();
    apply({ ...filters, from, to }, group, interval);
    setRefresh(value => value + 1);
  }

  async function saveTemplate(event) {
    event.preventDefault();
    const tags = reportTags.split(",").map(tag => tag.trim()).filter(Boolean);
    if (tags.length > 5 || tags.some(tag => tag.length > 30)) {
      setSavedError("Use up to five tags, with no more than 30 characters per tag.");
      return;
    }
    setSavedBusy(true);
    setSavedError("");
    setSavedMessage("");
    try {
      await request("reports/saved", { data: {
        name: savedName,
        filters: { ...filters, from, to },
        group,
        interval,
        range_days: dateRangeDays(from, to),
        columns: reportColumns,
        branding: reportBranding,
        tags,
      } });
      setSavedName("");
      setSavedMessage(data?.total === undefined ? "Report saved on this site." : "Report settings and statistics saved on this site.");
      setSavedRefresh(value => value + 1);
    } catch (saveError) {
      setSavedError(saveError.message);
    } finally {
      setSavedBusy(false);
    }
  }

  async function deleteTemplate(report) {
    if (!window.confirm(`Delete the saved report “${report.name}”?`)) return;
    setSavedBusy(true);
    setSavedError("");
    setSavedMessage("");
    try {
      await request(`reports/saved/${report.id}/delete`, { data: {} });
      setSavedMessage("Saved report deleted.");
      setSavedRefresh(value => value + 1);
    } catch (deleteError) {
      setSavedError(deleteError.message);
    } finally {
      setSavedBusy(false);
    }
  }

  function loadTemplate(report) {
    const end = siteToday();
    const start = shiftDate(end, -(report.range_days - 1));
    apply({ ...report.filters, from: start, to: end }, report.group, report.interval);
    setReportTitle(report.name);
    setReportColumns(report.columns?.length ? report.columns : allReportColumns);
    setReportBranding(Boolean(report.branding));
    setReportTags((report.tags ?? []).join(", "));
  }

  return <section className="cvsj-panel cvsj-report-page" aria-label="Activity reports">
    <section className="cvsj-report-download" aria-labelledby="cvsj-event-report-heading">
      <h2 id="cvsj-event-report-heading">Event report</h2>
      <p>Download every event matching the current filters as a private HTML file. This report is generated for you and is not stored by the plugin.</p>
      <label className="cvsj-field"><span>Report title</span><Input maxLength={120} value={reportTitle} onChange={event => setReportTitle(event.target.value)} /></label>
      <fieldset className="cvsj-settings-group">
        <legend>Include fields</legend>
        <div className="cvsj-report-columns">{reportFields.map(([key, label]) => <label className="cvsj-check" key={key}>
          <input type="checkbox" checked={reportColumns.includes(key)} onChange={event => setReportColumns(current => event.target.checked
            ? [...current, key]
            : current.filter(column => column !== key))} />
          <span>{label}</span>
        </label>)}</div>
      </fieldset>
      <label className="cvsj-check"><input type="checkbox" checked={reportBranding} onChange={event => setReportBranding(event.target.checked)} /><span><strong>Include site name</strong><small>Add this site’s name at the top of the downloaded report.</small></span></label>
      {reportColumns.length > 0
        ? <Button variant="outline" asChild><a href={eventReportUrl.href}><FileText aria-hidden="true" /> Download HTML report</a></Button>
        : <Button variant="outline" disabled>Select at least one field</Button>}
    </section>
    <form className="cvsj-report-builder" onSubmit={run}>
      <h2>Activity statistics</h2>
      <p>Compare activity within the date range allowed by this site’s retention setting. Date buckets use the site timezone.</p>
      <div className="cvsj-report-controls">
        <label className="cvsj-field"><span>From</span><Input type="date" required value={from} max={to} onChange={event => setFrom(event.target.value)} /></label>
        <label className="cvsj-field"><span>Through</span><Input type="date" required value={to} min={from} max={today} onChange={event => setTo(event.target.value)} /></label>
        <label className="cvsj-field"><span>Group activity by</span>
          <select value={group} onChange={event => setGroup(event.target.value)}>
            <option value="category">Event category</option><option value="action">Event type</option>
            <option value="severity">Severity</option><option value="actor_role">Actor role</option>
            <option value="source">Request source</option><option value="day">Date</option>
          </select>
        </label>
        <label className="cvsj-field"><span>Date interval</span>
          <select value={interval} disabled={group !== "day"} onChange={event => setInterval(event.target.value)}>
            <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
          </select>
        </label>
      </div>
      <Button type="submit">Run statistics</Button>
    </form>
    <div className="cvsj-report-results">
      <div className="cvsj-results"><p aria-live="polite">{data ? `${data.total.toLocaleString()} matching events` : "Activity totals"}</p></div>
      {error ? <Alert>{error}</Alert> : data?.rows.length
        ? <Table aria-label="Activity statistics">
          <TableHeader><TableRow><TableHead>{group === "day" ? "Date" : "Group"}</TableHead><TableHead>Events</TableHead></TableRow></TableHeader>
          <TableBody>{data.rows.map(row => <TableRow key={row.label}><TableCell>{statisticLabel(row, group, interval)}</TableCell><TableCell>{row.total.toLocaleString()}</TableCell></TableRow>)}</TableBody>
        </Table>
        : <div className="cvsj-empty"><h3 role={data ? undefined : "status"}>{data ? "No activity in this range" : "Loading statistics…"}</h3>{data && <p>Try a different date range or grouping.</p>}</div>}
      {savedError && <Alert>{savedError}</Alert>}
      {savedMessage && <p role="status">{savedMessage}</p>}
      {config.canManage && <form className="cvsj-save-report" onSubmit={saveTemplate}>
        <h3>Save this report</h3>
        <p>Save the filters, grouping, and a snapshot of the statistics shown above. The snapshot contains aggregated counts, not event records, and stays on this site.</p>
        <label className="cvsj-field"><span>Report name</span><Input required maxLength={100} value={savedName} onChange={event => setSavedName(event.target.value)} /></label>
        <label className="cvsj-field"><span>Tags, optional</span><Input maxLength={154} placeholder="Access review, quarterly" value={reportTags} onChange={event => setReportTags(event.target.value)} /><small>Separate up to five tags with commas. Avoid personal information.</small></label>
        <Button type="submit" variant="outline" disabled={savedBusy || !savedName.trim()}>{savedBusy ? "Saving…" : "Save report"}</Button>
      </form>}
      <section className="cvsj-saved-reports" aria-labelledby="cvsj-saved-reports-heading">
        <h3 id="cvsj-saved-reports-heading">Saved reports</h3>
        {!savedResource.data && !savedResource.error && <p role="status">Loading saved reports…</p>}
        {savedResource.error && <Alert>{savedResource.error}</Alert>}
        {savedResource.data?.reports.length === 0 && <p>No report templates have been saved.</p>}
        {savedResource.data?.reports.length > 0 && <ul>{savedResource.data.reports.map(report => <li className={expandedSnapshot === report.id ? "cvsj-has-statistics" : ""} key={report.id}>
          <div><strong>{report.name}</strong><small>{({ category: "Event category", action: "Event type", severity: "Severity", actor_role: "Actor role", source: "Request source", day: "Date" })[report.group] ?? report.group}, past {report.range_days} {report.range_days === 1 ? "day" : "days"}, {report.interval}, {report.columns?.length ?? allReportColumns.length} fields{report.branding ? ", site name included" : ""}</small>{report.tags?.length > 0 && <div className="cvsj-report-tags">{report.tags.map(tag => <Badge variant="outline" key={tag}>{tag}</Badge>)}</div>}</div>
          <div className="cvsj-saved-report-actions"><Button type="button" variant="outline" onClick={() => loadTemplate(report)}>Load</Button>{report.statistics && <><Button type="button" variant="outline" aria-expanded={expandedSnapshot === report.id} aria-controls={`cvsj-statistics-${report.id}`} onClick={() => setExpandedSnapshot(current => current === report.id ? "" : report.id)}>{expandedSnapshot === report.id ? "Hide result" : "View saved result"}</Button><Button type="button" variant="outline" asChild><a href={apiUrl(`reports/saved/${report.id}/statistics/download`, { format: "csv", _wpnonce: config.nonce }).href}><ArrowDownToLine size={14} aria-hidden="true" /> Download CSV</a></Button><Button type="button" variant="outline" asChild><a href={apiUrl(`reports/saved/${report.id}/statistics/download`, { format: "html", _wpnonce: config.nonce }).href}><ArrowDownToLine size={14} aria-hidden="true" /> Download HTML</a></Button></>}{config.canManage && <><Button type="button" variant="outline" onClick={() => window.dispatchEvent(new CustomEvent("cvsj-schedule-report", { detail: report.id }))}>Schedule email</Button><Button type="button" variant="ghost" disabled={savedBusy} onClick={() => deleteTemplate(report)}>Delete</Button></>}</div>
          {expandedSnapshot === report.id && report.statistics && <div className="cvsj-statistics-snapshot" id={`cvsj-statistics-${report.id}`}>
            <p>{report.statistics.total.toLocaleString()} events from {report.statistics.from} through {report.statistics.to}</p>
            <Table aria-label={`Saved statistics for ${report.name}`}>
              <TableHeader><TableRow><TableHead>{report.statistics.group === "day" ? "Date" : "Group"}</TableHead><TableHead>Events</TableHead></TableRow></TableHeader>
              <TableBody>{report.statistics.rows.map(row => <TableRow key={row.label}><TableCell>{statisticLabel(row, report.statistics.group, report.statistics.interval)}</TableCell><TableCell>{row.total.toLocaleString()}</TableCell></TableRow>)}</TableBody>
            </Table>
          </div>}
        </li>)}</ul>}
      </section>
    </div>
    {config.canManage && <ScheduledReportManager templates={savedResource.data?.reports ?? []} />}
  </section>;
}

function ScheduledReportManager({ templates }) {
  const [refresh, setRefresh] = useState(0);
  const [templateId, setTemplateId] = useState("");
  const [cadence, setCadence] = useState("weekly");
  const [hour, setHour] = useState("9");
  const [format, setFormat] = useState("csv");
  const [recipients, setRecipients] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const scheduleHeading = useRef(null);
  const resource = useResource("reports/schedules", null, refresh);
  useEffect(() => {
    const selectTemplate = event => setTemplateId(event.detail);
    window.addEventListener("cvsj-schedule-report", selectTemplate);
    return () => window.removeEventListener("cvsj-schedule-report", selectTemplate);
  }, []);
  useEffect(() => {
    if (templateId) {
      const behavior = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth";
      scheduleHeading.current?.scrollIntoView({ behavior, block: "center" });
      scheduleHeading.current?.focus();
    }
  }, [templateId]);
  const selectedTemplate = templates.find(template => template.id === templateId);

  async function createSchedule(event) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const addresses = recipients.split(/[,;\n]/).map(value => value.trim()).filter(Boolean);
      await request("reports/schedules", { data: { template_id: templateId, cadence, hour: Number(hour), format, recipients: addresses } });
      setTemplateId("");
      setRecipients("");
      setMessage("Scheduled report created.");
      setRefresh(value => value + 1);
    } catch (scheduleError) {
      setError(scheduleError.message);
    } finally {
      setBusy(false);
    }
  }

  async function removeSchedule(schedule) {
    if (!window.confirm(`Delete the scheduled email for “${schedule.name}”?`)) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await request(`reports/schedules/${schedule.id}/delete`, { data: {} });
      setMessage("Scheduled report deleted.");
      setRefresh(value => value + 1);
    } catch (scheduleError) {
      setError(scheduleError.message);
    } finally {
      setBusy(false);
    }
  }

  async function removeHistory(entry) {
    if (!window.confirm(`Delete the saved report copy for “${entry.name}”?`)) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await request(`reports/history/${entry.id}/delete`, { data: {} });
      setMessage("Report history item deleted.");
      setRefresh(value => value + 1);
    } catch (historyError) {
      setError(historyError.message);
    } finally {
      setBusy(false);
    }
  }

  const cadenceNames = { daily: "Daily", weekly: "Weekly on Monday", monthly: "Monthly on the first", quarterly: "Quarterly on the first day of the quarter" };
  const when = value => value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short", timeZone: config.timezone || undefined }).format(new Date(value * 1000)) : "Not sent yet";
  return <section className="cvsj-scheduled-reports" aria-labelledby="cvsj-scheduled-reports-heading">
    <h3 id="cvsj-scheduled-reports-heading">Scheduled email reports</h3>
    <p>Reports are prepared in background batches and sent as CSV or HTML attachments. Failed email delivery is retried twice, after about five minutes and fifteen minutes. Recipients stay on this site. Private report copies follow this site’s retention setting, with up to ten copies stored at once.</p>
    {error && <Alert>{error}</Alert>}{message && <p role="status">{message}</p>}
    {resource.error && <Alert>{resource.error}</Alert>}
    {!resource.data && !resource.error && <p role="status">Loading scheduled reports…</p>}
    {resource.data?.schedules.length === 0 && <p>No email reports are scheduled.</p>}
    {resource.data?.schedules.length > 0 && <ul className="cvsj-schedule-list">{resource.data.schedules.map(schedule => <li key={schedule.id}>
      <div><strong>{schedule.name}</strong><small>{schedule.format === "html" ? "HTML attachment" : "CSV attachment"}, {cadenceNames[schedule.cadence]}, next {when(schedule.next_run)} in {config.timezone}</small><small>To {schedule.recipients.join(", ")}{schedule.last_run ? `, last delivery ${when(schedule.last_run)}` : ""}{schedule.last_status ? `, status ${schedule.last_status}` : ""}</small>{schedule.last_error && <small role="status">{schedule.last_error}</small>}</div>
      <Button type="button" variant="ghost" disabled={busy} onClick={() => removeSchedule(schedule)}>Delete</Button>
    </li>)}</ul>}
    {templateId && <form className="cvsj-schedule-form" onSubmit={createSchedule}>
      <h4 ref={scheduleHeading} tabIndex={-1}>Schedule {selectedTemplate?.name ?? "saved report"}</h4>
      <label className="cvsj-field"><span>Frequency</span><select value={cadence} onChange={event => setCadence(event.target.value)}>
        <option value="daily">Daily</option><option value="weekly">Weekly, Monday</option><option value="monthly">Monthly, first day</option><option value="quarterly">Quarterly, first day of the quarter</option>
      </select></label>
      <label className="cvsj-field"><span>Send at, site time</span><select value={hour} onChange={event => setHour(event.target.value)}>{Array.from({ length: 24 }, (_, value) => <option key={value} value={value}>{String(value).padStart(2, "0")}:00</option>)}</select></label>
      <label className="cvsj-field"><span>Attachment format</span><select value={format} onChange={event => setFormat(event.target.value)}><option value="csv">CSV, for spreadsheets</option><option value="html">HTML, for reading and printing</option></select></label>
      <label className="cvsj-field"><span>Email recipients</span><Input required maxLength={2550} placeholder="admin@example.com, security@example.com" value={recipients} onChange={event => setRecipients(event.target.value)} /><small>Enter up to ten addresses separated by commas. Emails include activity details, use only intended recipients.</small></label>
      <div className="cvsj-schedule-actions"><Button type="submit" disabled={busy}>{busy ? "Saving…" : "Create schedule"}</Button><Button type="button" variant="ghost" onClick={() => setTemplateId("")}>Cancel</Button></div>
    </form>}
    {resource.data?.history.length > 0 && <details className="cvsj-report-history"><summary>Report history</summary><ul>{resource.data.history.map(entry => <li key={entry.id}><strong>{entry.name}</strong><span>{entry.format === "html" ? "HTML" : "CSV"}, {cadenceNames[entry.cadence] ?? entry.cadence}, {when(entry.occurred_at)}, {entry.status}, {entry.event_count} events</span>{entry.message && <small>{entry.message}</small>}<div className="cvsj-report-history-actions">{entry.download_url && <a href={entry.download_url}>Download copy</a>}<Button type="button" variant="ghost" disabled={busy} onClick={() => removeHistory(entry)}>Delete</Button></div></li>)}</ul></details>}
  </section>;
}

function NetworkSites() {
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [refresh, setRefresh] = useState(0);
  const [drafts, setDrafts] = useState({});
  const [busy, setBusy] = useState(0);
  const [messages, setMessages] = useState({});
  const query = useMemo(() => ({ page, search }), [page, search]);
  const { data, error } = useResource("sites", query, refresh);

  useEffect(() => {
    if (!data) return;
    setDrafts(Object.fromEntries(data.sites.map(site => [site.id, {
      retention: String(site.retention),
      categories: Object.fromEntries(Object.entries(site.categories).map(([key, value]) => [key, value.enabled])),
    }])));
  }, [data]);

  function runSearch(event) {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  }

  async function save(site) {
    const draft = drafts[site.id];
    if (!draft || !/^\d+$/.test(draft.retention) || Number(draft.retention) < 1 || Number(draft.retention) > 3650) {
      setMessages(current => ({ ...current, [site.id]: { error: "Enter a retention period from 1 to 3650 days." } }));
      return;
    }
    setBusy(site.id);
    setMessages(current => ({ ...current, [site.id]: null }));
    try {
      await request(`sites/${site.id}/settings`, { data: { retention: Number(draft.retention), categories: draft.categories } });
      setMessages(current => ({ ...current, [site.id]: { success: "Site settings saved." } }));
      setRefresh(value => value + 1);
    } catch (saveError) {
      setMessages(current => ({ ...current, [site.id]: { error: saveError.message } }));
    } finally {
      setBusy(0);
    }
  }

  return <section className="cvsj-panel cvsj-network-sites" aria-label="Network site settings">
    <div className="cvsj-network-sites-intro">
      <h2>Network sites</h2>
      <p>Review each site’s storage and retention. Category choices stay local to each site.</p>
    </div>
    <form className="cvsj-network-site-search" onSubmit={runSearch}>
      <Input aria-label="Search network sites" maxLength={160} placeholder="Search by site ID, domain, or path" value={searchInput} onChange={event => setSearchInput(event.target.value)} />
      <Button type="submit"><Search aria-hidden="true" /> Search sites</Button>
      {search && <Button type="button" variant="ghost" onClick={() => { setSearchInput(""); setSearch(""); setPage(1); }}>Clear search</Button>}
    </form>
    {error && <Alert>{error}</Alert>}
    {!data && !error && <p role="status">Loading network sites…</p>}
    {data && data.sites.length === 0 && <div className="cvsj-empty"><Globe size={24} aria-hidden="true" /><h3>No matching sites</h3><p>Check the site ID or address and try again.</p></div>}
    {data?.sites.length > 0 && <>
      <div className="cvsj-network-site-table-wrap" role="region" aria-label="Network site settings table" tabIndex={0}>
        <Table aria-label="Network sites and activity settings">
          <TableHeader><TableRow><TableHead>Site</TableHead><TableHead>Retention</TableHead><TableHead>Event categories</TableHead><TableHead>Storage</TableHead><TableHead><span className="screen-reader-text">Save changes</span></TableHead></TableRow></TableHeader>
          <TableBody>{data.sites.map(site => {
            const draft = drafts[site.id];
            const message = messages[site.id];
            return <TableRow key={site.id}>
              <TableCell><div className="cvsj-network-site-name"><a href={site.dashboard_url}>{site.name || `Site ${site.id}`}</a><small>Site #{site.id}</small><small>{site.address}</small></div></TableCell>
              <TableCell><label className="cvsj-field cvsj-retention-field"><span>Days</span><Input aria-label={`Retention days for ${site.name}`} type="number" min="1" max="3650" step="1" value={draft?.retention ?? site.retention} onChange={event => setDrafts(current => ({ ...current, [site.id]: { ...current[site.id], retention: event.target.value } }))} /></label></TableCell>
              <TableCell><details className="cvsj-network-category-details"><summary>Manage {Object.keys(site.categories).length} categories</summary><div className="cvsj-network-category-list">{Object.entries(site.categories).map(([key, category]) => <label className="cvsj-check" key={key}><input type="checkbox" checked={Boolean(draft?.categories?.[key])} onChange={event => setDrafts(current => ({ ...current, [site.id]: { ...current[site.id], categories: { ...current[site.id]?.categories, [key]: event.target.checked } } }))} /><span>{category.label}</span></label>)}</div></details></TableCell>
              <TableCell><div className="cvsj-network-storage-status">{site.storage_available ? <Badge variant="outline">Available</Badge> : <Badge variant="outline" className="cvsj-warning">Unavailable</Badge>}{site.recording_failure && <small>Recording issue</small>}{site.cleanup_failure && <small>Cleanup issue</small>}</div></TableCell>
              <TableCell><Button type="button" disabled={busy === site.id || !draft} onClick={() => save(site)}>{busy === site.id ? "Saving…" : "Save"}</Button>{message?.error && <small role="alert" className="cvsj-network-row-message is-error">{message.error}</small>}{message?.success && <small role="status" className="cvsj-network-row-message">{message.success}</small>}</TableCell>
            </TableRow>;
          })}</TableBody>
        </Table>
      </div>
      <div className="cvsj-pagination" aria-label="Network site pages"><span>{data.total} sites, page {data.page}</span><Button variant="outline" disabled={data.page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}><ArrowLeft aria-hidden="true" /> Previous</Button><Button variant="outline" disabled={!data.has_more} onClick={() => setPage(value => value + 1)}>Next <ArrowRight aria-hidden="true" /></Button></div>
    </>}
    <p className="cvsj-settings-version">Change Vigil Site Journal, Version {config.version}</p>
  </section>;
}

function SessionsView() {
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [refresh, setRefresh] = useState(0);
  const [settings, setSettings] = useState(null);
  const [settingsState, setSettingsState] = useState({ busy: false, error: "", message: "" });
  const [actionState, setActionState] = useState({ busy: "", error: "", message: "" });
  const query = useMemo(() => ({ page, search }), [page, search]);
  const { data, error } = useResource("sessions", query, refresh);

  useEffect(() => {
    if (data?.settings) setSettings(data.settings);
  }, [data]);

  function runSearch(event) {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  }

  async function saveSettings(event) {
    event.preventDefault();
    if (!settings) return;
    setSettingsState({ busy: true, error: "", message: "" });
    try {
      const result = await request("sessions/settings", { data: settings });
      setSettings(result.settings);
      setRefresh(value => value + 1);
      setSettingsState({ busy: false, error: "", message: "Privacy settings saved." });
    } catch (saveError) {
      setSettingsState({ busy: false, error: saveError.message, message: "" });
    }
  }

  async function endSession(user, session) {
    if (!window.confirm(`End this session for ${user.login}? The browser using it will be signed out.`)) return;
    setActionState({ busy: `${user.id}:${session.reference}`, error: "", message: "" });
    try {
      await request(`sessions/${user.id}/${session.reference}/revoke`, { data: {} });
      setActionState({ busy: "", error: "", message: `Session ended for ${user.login}.` });
      if (session.current) {
        window.setTimeout(() => { window.location.href = config.loginUrl; }, 1200);
      } else {
        setRefresh(value => value + 1);
      }
    } catch (revokeError) {
      setActionState({ busy: "", error: revokeError.message, message: "" });
    }
  }

  async function endAll(user) {
    if (!window.confirm(`End every active session for ${user.login}? This signs the account out on all browsers.`)) return;
    setActionState({ busy: `${user.id}:all`, error: "", message: "" });
    try {
      const result = await request(`sessions/${user.id}/revoke-all`, { data: {} });
      setActionState({ busy: "", error: "", message: `Ended ${result.ended} session${result.ended === 1 ? "" : "s"} for ${user.login}.` });
      if (user.id === config.currentUserId && result.ended > 0) {
        window.setTimeout(() => { window.location.href = config.loginUrl; }, 1200);
      } else {
        setRefresh(value => value + 1);
      }
    } catch (revokeError) {
      setActionState({ busy: "", error: revokeError.message, message: "" });
    }
  }

  const sessions = data?.users.flatMap(user => user.sessions.map(session => ({ user, session }))) ?? [];
  const emptyAccounts = data?.users.filter(user => user.sessions.length === 0) ?? [];
  return <section className="cvsj-sessions" aria-labelledby="cvsj-sessions-title">
    <div className="cvsj-section-heading">
      <div><h2 id="cvsj-sessions-title">User sessions</h2><p>Review active WordPress logins and end a session if an account looks unexpected.</p></div>
    </div>
    <div className="cvsj-session-privacy-note"><ShieldAlert aria-hidden="true" size={17} /><p>WordPress stores login time, expiry, browser data, and IP address in its session records. Change Vigil hides browser and IP details by default. On Multisite, sessions apply to the account across the network.</p></div>
    {settings && <form className="cvsj-session-settings" onSubmit={saveSettings}>
      <div><h3>Session privacy</h3><p>Choose which session details this screen can show.</p></div>
      <label className="cvsj-check"><input type="checkbox" checked={settings.track_activity} onChange={event => setSettings(current => ({ ...current, track_activity: event.target.checked }))} /><span>Track last activity on this site</span></label>
      <small>When enabled, a one way session reference and time are kept in a site transient for up to 30 days. Turning this off stops updates and clears saved timestamps in background batches. WordPress background tasks can run late on quiet sites.</small>
      <label className="cvsj-check"><input type="checkbox" checked={settings.show_browser} onChange={event => setSettings(current => ({ ...current, show_browser: event.target.checked }))} /><span>Show browser and device type</span></label>
      <label className="cvsj-check"><input type="checkbox" checked={settings.show_ip} onChange={event => setSettings(current => ({ ...current, show_ip: event.target.checked }))} /><span>Show IP addresses</span></label>
      <div className="cvsj-session-policy-group">
        <h4>Concurrent sessions</h4>
        <label className="cvsj-check"><input type="checkbox" checked={settings.limit_enabled} onChange={event => setSettings(current => ({ ...current, limit_enabled: event.target.checked }))} /><span>Limit active sessions per account</span></label>
        <label className="cvsj-field"><span>Maximum sessions</span><Input aria-label="Maximum sessions per account" type="number" min="1" max="20" step="1" disabled={!settings.limit_enabled} value={settings.max_sessions} onChange={event => setSettings(current => ({ ...current, max_sessions: Number(event.target.value) }))} /></label>
        <small>New sign ins end the oldest sessions above this limit. Site administrators, Super Admins, and Change Vigil session managers are always excluded.</small>
      </div>
      <div className="cvsj-session-policy-group">
        <h4>Idle expiry</h4>
        <label className="cvsj-check"><input type="checkbox" checked={settings.idle_enabled} onChange={event => setSettings(current => ({ ...current, idle_enabled: event.target.checked, track_activity: event.target.checked || current.track_activity }))} /><span>End idle sessions automatically</span></label>
        <label className="cvsj-field"><span>Idle time, minutes</span><Input aria-label="Idle time in minutes" type="number" min="5" max="10080" step="1" disabled={!settings.idle_enabled} value={settings.idle_minutes} onChange={event => setSettings(current => ({ ...current, idle_minutes: Number(event.target.value) }))} /></label>
        <small>Idle expiry enables last activity tracking. Existing sessions receive a grace period when this policy is first enabled. Administrators and session managers are excluded.</small>
      </div>
      <div className="cvsj-session-policy-roles"><h4>Additional excluded roles</h4><p>Accounts with any selected role are never affected by automatic session limits.</p><div>{Object.entries(config.roles).map(([role, label]) => <label className="cvsj-check" key={role}><input type="checkbox" checked={settings.excluded_roles.includes(role)} onChange={event => setSettings(current => ({ ...current, excluded_roles: event.target.checked ? [...current.excluded_roles, role] : current.excluded_roles.filter(value => value !== role) }))} /><span>{label}</span></label>)}</div></div>
      {data?.policy_issue && <Alert>{data.policy_issue}</Alert>}
      {settingsState.error && <Alert>{settingsState.error}</Alert>}
      {settingsState.message && <p role="status" className="cvsj-inline-success">{settingsState.message}</p>}
      <Button type="submit" disabled={settingsState.busy}>{settingsState.busy ? "Saving…" : "Save privacy settings"}</Button>
    </form>}
    <form className="cvsj-session-search" onSubmit={runSearch}>
      <Input aria-label="Search accounts" maxLength={160} placeholder="Search by account name or username" value={searchInput} onChange={event => setSearchInput(event.target.value)} />
      <Button type="submit"><Search aria-hidden="true" /> Search accounts</Button>
      {search && <Button type="button" variant="ghost" onClick={() => { setSearchInput(""); setSearch(""); setPage(1); }}>Clear search</Button>}
    </form>
    {error && <Alert>{error}</Alert>}
    {actionState.error && <Alert>{actionState.error}</Alert>}
    {actionState.message && <p role="status" className="cvsj-inline-success">{actionState.message}</p>}
    {!data && !error && <p role="status">Loading sessions…</p>}
    {data && sessions.length === 0 && emptyAccounts.length === 0 && <div className="cvsj-empty"><UsersRound size={24} aria-hidden="true" /><h3>No matching accounts</h3><p>Check the account name or username and try again.</p></div>}
    {data && (sessions.length > 0 || emptyAccounts.length > 0) && <div className="cvsj-session-table-wrap" role="region" aria-label="Active user sessions" tabIndex={0}>
      <Table aria-label="Active user sessions">
        <TableHeader><TableRow><TableHead>Account</TableHead><TableHead>Role</TableHead><TableHead>Session details</TableHead><TableHead>Login time</TableHead><TableHead>Last activity</TableHead><TableHead><span className="screen-reader-text">Session actions</span></TableHead></TableRow></TableHeader>
        <TableBody>
          {sessions.map(({ user, session }) => <TableRow key={`${user.id}:${session.reference}`}>
            <TableCell><strong>{user.name}</strong><small className="cvsj-session-login">{user.login}{session.current && <Badge variant="outline">This browser</Badge>}</small></TableCell>
            <TableCell>{user.role || "No site role"}</TableCell>
            <TableCell><div className="cvsj-session-device">{session.browser && <span><Laptop aria-hidden="true" size={14} />{session.browser}</span>}{session.ip && <span><MapPin aria-hidden="true" size={14} />{session.ip}</span>}{!session.browser && !session.ip && <span>Details hidden</span>}<small>Expires {session.expiration || "Unknown"}</small></div></TableCell>
            <TableCell>{session.login || "Unknown"}</TableCell>
            <TableCell>{settings?.track_activity ? session.last_activity || "Not observed on this site" : "Tracking off"}</TableCell>
            <TableCell><div className="cvsj-session-actions"><Button type="button" variant="outline" disabled={!session.can_revoke || Boolean(actionState.busy)} onClick={() => endSession(user, session)}><LogOut aria-hidden="true" size={15} /> End session</Button>{user.sessions[0]?.reference === session.reference && <Button type="button" variant="ghost" disabled={!session.can_revoke || Boolean(actionState.busy)} onClick={() => endAll(user)}>End all for account</Button>}</div></TableCell>
          </TableRow>)}
          {emptyAccounts.map(user => <TableRow key={`${user.id}:empty`}><TableCell><strong>{user.name}</strong><small className="cvsj-session-login">{user.login}</small></TableCell><TableCell>{user.role || "No site role"}</TableCell><TableCell colSpan={3}>No active sessions</TableCell><TableCell>No session actions</TableCell></TableRow>)}
        </TableBody>
      </Table>
    </div>}
    {data && <div className="cvsj-pagination" aria-label="Account pages"><span>{data.total_users} accounts, page {data.page}</span><Button variant="outline" disabled={data.page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}><ArrowLeft aria-hidden="true" /> Previous</Button><Button variant="outline" disabled={!data.has_more} onClick={() => setPage(value => value + 1)}>Next <ArrowRight aria-hidden="true" /></Button></div>}
    <p className="cvsj-session-policy-note"><Clock3 aria-hidden="true" size={15} /> WordPress controls session expiry. This screen does not change the site's login duration.</p>
    <p className="cvsj-settings-version">Change Vigil Site Journal, Version {config.version}</p>
  </section>;
}

function App() {
  const [view, setView] = useState(readView);
  const [revision, setRevision] = useState(0);
  const heading = useRef(null);
  useEffect(() => {
    const restore = () => { setView(readView()); setRevision(value => value + 1); };
    window.addEventListener("popstate", restore);
    return () => window.removeEventListener("popstate", restore);
  }, []);
  function navigate(filters, destination = "activity", report = null) {
    const url = viewUrl(filters, destination, report);
    if (url.href !== location.href) history.pushState(null, "", url);
    setView(readView());
    setRevision(value => value + 1);
  }
  function follow(event, destination) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    navigate(view.filters, destination, { group: view.reportGroup, interval: view.reportInterval });
  }
  const showSettings = !config.networkMode && view.settings && config.canManage;
  const showNetworkSites = config.networkMode && view.sites;
  const showSessions = !config.networkMode && view.sessions && config.sessionsAllowed;
  const showReports = !config.networkMode && view.reports && !showSettings;
  const currentPage = showNetworkSites ? "sites" : showSessions ? "sessions" : showSettings ? "settings" : showReports ? "reports" : "activity";
  return <div className="cvsj-app">
    <div className="cvsj-heading">
      <div className="cvsj-mark"><Activity size={25} aria-hidden="true" /></div>
      <div><h1 ref={heading} tabIndex={-1}>{config.networkMode ? "Network Change Vigil" : "Change Vigil"}</h1><p>{config.networkMode ? "Review changes across sites in this network." : "See who changed what across this WordPress site."}</p></div>
    </div>
    <nav className="cvsj-nav" aria-label="Change Vigil">
      <a href={viewUrl(view.filters, "activity")} onClick={event => follow(event, "activity")} aria-current={currentPage === "activity" ? "page" : undefined}><Activity size={16} aria-hidden="true" /> Activity</a>
      {config.networkMode && <a href={viewUrl(view.filters, "sites")} onClick={event => follow(event, "sites")} aria-current={currentPage === "sites" ? "page" : undefined}><Globe size={16} aria-hidden="true" /> Sites</a>}
      {!config.networkMode && <a href={viewUrl(view.filters, "reports", { group: view.reportGroup, interval: view.reportInterval })} onClick={event => follow(event, "reports")} aria-current={currentPage === "reports" ? "page" : undefined}><FileText size={16} aria-hidden="true" /> Reports</a>}
      {config.sessionsAllowed && <a href={viewUrl(view.filters, "sessions")} onClick={event => follow(event, "sessions")} aria-current={currentPage === "sessions" ? "page" : undefined}><UsersRound size={16} aria-hidden="true" /> Sessions</a>}
      {config.canManage && !config.networkMode && <a href={viewUrl(view.filters, "settings")} onClick={event => follow(event, "settings")} aria-current={currentPage === "settings" ? "page" : undefined}><Settings2 size={16} aria-hidden="true" /> Settings</a>}
    </nav>
    {showNetworkSites ? <NetworkSites key={revision} /> : showSessions ? <SessionsView key={revision} /> : showSettings ? <Settings /> : showReports
      ? <Reports key={revision} filters={view.filters} apply={(filters, group, interval) => navigate(filters, "reports", { group, interval })} initialGroup={view.reportGroup} initialInterval={view.reportInterval} />
      : <ActivityView key={revision} filters={view.filters} apply={navigate} heading={heading} initialEventId={view.eventId} />}
  </div>;
}

createRoot(document.getElementById("cvsj-root")).render(<App />);
