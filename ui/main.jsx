import { cloneElement, useEffect, useId, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
  Activity, ArrowDown, ArrowDownToLine, ArrowLeft, ArrowRight, ChevronRight, CircleAlert,
  CircleHelp, Clock3, FileText, ListFilter, LockKeyhole, Plug, RefreshCw, Search, Settings2,
  ShieldAlert, SwatchBook, UserRound, X,
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
  signed_in: "Signed in", sign_in_failed: "Failed sign in",
  plugin_installed: "Plugin installed", plugin_activated: "Plugin activated", plugin_deactivated: "Plugin deactivated",
  plugin_updated: "Plugin updated", plugin_deleted: "Plugin deleted", theme_installed: "Theme installed",
  theme_switched: "Theme switched", theme_updated: "Theme updated", theme_deleted: "Theme deleted",
  setting_changed: "Setting changed",
};
const severities = { info: "Info", notice: "Notice", warning: "Warning" };
const kindLabels = { post: "Post", page: "Page", user: "Account", plugin: "Plugin", theme: "Theme", setting: "Setting", login: "Sign in" };
const icons = { post: FileText, page: FileText, user: UserRound, plugin: Plug, theme: SwatchBook, setting: Settings2, login: LockKeyhole };
const statusNames = { publish: "Published", draft: "Draft", pending: "Pending review", private: "Private", future: "Scheduled", trash: "Trash" };

function actorName(row) {
  return row.actor_name || ({ anonymous: "Unauthenticated", system: "WordPress background task", unknown: "Unknown actor" })[row.actor_kind] || "Unknown actor";
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
  return <div role="alert" className="alfa-alert"><CircleAlert aria-hidden="true" size={18} /><span>{children}</span></div>;
}

function Severity({ value }) {
  return <Badge variant="outline" className={`alfa-severity alfa-${value}`}>
    {value === "warning" && <ShieldAlert aria-hidden="true" size={13} />}
    {severities[value] ?? value}
  </Badge>;
}

function FilterField({ label, children }) {
  const id = useId();
  return <div className="alfa-field"><label htmlFor={id}>{label}</label>{cloneElement(children, { id })}</div>;
}

function Filters({ filters, apply, exportUrl }) {
  const [draft, setDraft] = useState(filters);
  const [open, setOpen] = useState(false);
  const count = Object.entries(filters).filter(([key, value]) => key !== "q" && value).length;
  const active = count > 0 || Boolean(filters.q);
  const field = key => ({
    name: key,
    value: draft[key],
    onChange: event => setDraft(current => ({ ...current, [key]: event.target.value })),
  });
  const clear = () => apply({});
  return <form className="alfa-filters" onSubmit={event => { event.preventDefault(); apply(draft); }}>
    <div className="alfa-toolbar">
      <div className="alfa-search">
        <Search size={18} aria-hidden="true" />
        <Input aria-label="Search activity" placeholder="Search by item, actor, or change" maxLength={255} {...field("q")} />
      </div>
      <Button type="submit">Search</Button>
      <Button type="button" variant="outline" aria-expanded={open} aria-controls="alfa-filter-fields" onClick={() => setOpen(!open)}>
        <ListFilter aria-hidden="true" /> Filters{count > 0 && <span className="alfa-filter-count">{count}</span>}
      </Button>
      <Button variant="outline" asChild className="alfa-export">
        <a href={exportUrl}><ArrowDownToLine aria-hidden="true" /> Export CSV</a>
      </Button>
    </div>
    <div id="alfa-filter-fields" hidden={!open}>
      <div className="alfa-filter-grid">
        <FilterField label="From"><Input type="date" {...field("from")} /></FilterField>
        <FilterField label="Through"><Input type="date" {...field("to")} /></FilterField>
        <FilterField label="Actor"><Input placeholder="Username or user ID" maxLength={255} {...field("actor")} /></FilterField>
        <FilterField label="Affected item"><Input placeholder="Title or name" maxLength={255} {...field("item")} /></FilterField>
        <FilterField label="Action"><select {...field("action")}><option value="">All actions</option>{config.actions.map(key => <option key={key} value={key}>{actions[key]}</option>)}</select></FilterField>
        <FilterField label="Severity"><select {...field("severity")}><option value="">All severities</option>{config.severities.map(key => <option key={key} value={key}>{severities[key]}</option>)}</select></FilterField>
      </div>
      <div className="alfa-filter-actions"><Button type="submit">Apply filters</Button></div>
    </div>
    {active && <div className="alfa-applied">
      <span>Filtered activity</span>
      {Object.entries(filters).filter(([, value]) => value).map(([key, value]) =>
        <span className="alfa-chip" key={key}>{({ from: "From ", to: "Through ", actor: "Actor: ", item: "Item: " })[key] ?? ""}{actions[value] ?? severities[value] ?? value}</span>
      )}
      <button className="alfa-text-button" type="button" onClick={clear}><X size={14} aria-hidden="true" /> Clear all</button>
    </div>}
  </form>;
}

function EventTable({ events, select }) {
  return <Table aria-label="Activity events">
    <TableHeader><TableRow>
      <TableHead>Change</TableHead><TableHead className="alfa-actor-col">Actor</TableHead>
      <TableHead className="alfa-time-col" aria-sort="descending">When <ArrowDown size={13} aria-hidden="true" /></TableHead>
      <TableHead className="alfa-severity-col">Severity</TableHead>
    </TableRow></TableHeader>
    <TableBody>
      {events.map(row => {
        const Icon = icons[row.item_kind] ?? Activity;
        return <TableRow key={row.id}>
          <TableCell>
            <div className="alfa-event">
              <span className={`alfa-event-icon alfa-kind-${row.item_kind}`}><Icon size={18} aria-hidden="true" /></span>
              <div className="alfa-event-copy">
                <button className="alfa-event-link" type="button" onClick={event => select(row, event.currentTarget)}>{row.summary}<ChevronRight size={15} aria-hidden="true" /></button>
                <span className="alfa-event-meta">{kindLabels[row.item_kind]} <span aria-hidden="true">·</span> {actions[row.action_key]}</span>
                <div className="alfa-mobile-meta">{actorName(row)} <span aria-hidden="true">·</span> <time dateTime={row.time_iso}>{row.time_short}</time> <Severity value={row.severity} /></div>
              </div>
            </div>
          </TableCell>
          <TableCell className="alfa-actor-col">{actorName(row)}</TableCell>
          <TableCell className="alfa-time-col"><time dateTime={row.time_iso} title={row.time_exact}>{row.time_short}</time></TableCell>
          <TableCell className="alfa-severity-col"><Severity value={row.severity} /></TableCell>
        </TableRow>;
      })}
    </TableBody>
  </Table>;
}

function EventDetails({ row, close, related, trigger, fallback }) {
  const { data, error } = useResource(row ? `events/${row.id}` : null);
  const label = data ?? row;
  const compare = data?.details?.changes;
  return <Sheet open={Boolean(row)} onOpenChange={next => { if (!next) close(); }}>
    {label && <SheetContent className="alfa-sheet" portalContainer={document.getElementById("alfa-root")} onCloseAutoFocus={event => {
      event.preventDefault();
      if (trigger.current?.isConnected) trigger.current.focus();
      else fallback.current?.focus();
    }}>
      <SheetHeader>
        <Severity value={label.severity} />
        <SheetTitle>{label.summary}</SheetTitle>
        <SheetDescription>Event {label.id} · {actions[label.action_key]}</SheetDescription>
      </SheetHeader>
      <div className="alfa-sheet-body">
        <dl className="alfa-details">
          <div><dt>Actor</dt><dd><button type="button" className="alfa-text-button" onClick={() => related("actor", label.actor_kind === "user" ? label.actor_id : label.actor_kind)}>{actorName(label)} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>
          <div><dt>Affected item</dt><dd><button type="button" className="alfa-text-button" onClick={() => related("item", `${label.item_kind}:${label.item_key}`)}>{label.item_name} <ArrowRight size={14} aria-hidden="true" /></button></dd></div>
          <div><dt>Time</dt><dd><time dateTime={label.time_iso}>{label.time_exact}</time></dd></div>
          {data?.details?.version && <div><dt>Component version</dt><dd>{data.details.version}</dd></div>}
        </dl>
        {error && <Alert>{error}</Alert>}
        {!data && !error && <p role="status">Loading event details…</p>}
        {compare && <section className="alfa-changes">
          <h3>What changed</h3>
          {Object.entries(compare).map(([field, values]) => <div className="alfa-change" key={field}>
            <h4>{field}</h4>
            <dl>{values.map((value, index) => <div key={index}><dt>{index === 0 ? "Previous" : "New"}</dt>
              <dd>{(field === "Status" ? statusNames[value] ?? value : value) || "(Empty)"}</dd></div>)}</dl>
          </div>)}
        </section>}
        {data?.details?.content_changed && (data.revision_url
          ? <Button variant="outline" asChild><a href={data.revision_url}>Compare WordPress revisions <ArrowRight aria-hidden="true" /></a></Button>
          : <p>Content changed. The WordPress revisions needed for a comparison are unavailable.</p>)}
      </div>
    </SheetContent>}
  </Sheet>;
}

function Settings() {
  const { data, error } = useResource("settings");
  const [saved, setSaved] = useState(null);
  const [state, setState] = useState({ busy: false, error: "", message: "" });
  const [draft, setDraft] = useState(null);
  const current = saved ?? data;
  const values = draft ?? current?.settings;
  const health = current?.health;
  async function save(event) {
    event.preventDefault();
    setState({ busy: true, error: "", message: "" });
    try {
      const result = await request("settings", { data: { ...values, retention: Number(values.retention) } });
      setSaved(result);
      setDraft(null);
      setState({ busy: false, error: "", message: "Settings saved." });
    } catch (error) {
      setState({ busy: false, error: error.message, message: "" });
    }
  }
  return <div className="alfa-settings">
    {(error || state.error) && <Alert>{error || state.error}</Alert>}
    {!current && !error && <p role="status">Loading settings…</p>}
    {current && <>
      <section className="alfa-panel alfa-settings-panel">
        <h2>Log health</h2>
        {!health.available && <Alert>The activity log table is unavailable. Check the database connection.</Alert>}
        {health.write_error && <Alert>Last event recording failure: {health.write_error}. Some activity may be missing.</Alert>}
        {health.cleanup_error && <Alert>Last cleanup failure: {health.cleanup_error}.</Alert>}
        {!health.next_cleanup && <Alert>Automatic cleanup is unscheduled. Save the settings to try scheduling it again.</Alert>}
        <dl className="alfa-health">
          <div><dt>Oldest event</dt><dd>{health.oldest || (health.available ? "No events recorded" : "Unavailable")}</dd></div>
          <div><dt>Estimated database space</dt><dd>{health.space ?? "Unavailable"}</dd></div>
          <div><dt>Last cleanup</dt><dd>{health.last_cleanup ?? "Has yet to run"}</dd></div>
          <div><dt>Next scheduled cleanup</dt><dd>{health.next_cleanup ?? "Unscheduled"}</dd></div>
        </dl>
      </section>
      <form className="alfa-panel alfa-settings-panel" onSubmit={save}>
        <h2>Retention</h2>
        <label className="alfa-field alfa-retention"><span>Keep events for (days)</span>
          <Input type="number" required min="1" max="3650" value={values.retention} onChange={event => setDraft({ ...values, retention: event.target.value })} />
        </label>
        <p>Automatic cleanup deletes events older than this limit. Cleanup runs on the WordPress schedule.</p>
        <label className="alfa-check"><input type="checkbox" checked={values.erase} onChange={event => setDraft({ ...values, erase: event.target.checked })} />
          <span><strong>Erase all activity when deleting the plugin</strong><small>Deactivating the plugin preserves your history.</small></span>
        </label>
        <div className="alfa-save"><Button disabled={state.busy}>{state.busy ? "Saving…" : "Save settings"}</Button>
          <span role="status">{state.message}</span></div>
      </form>
    </>}
    <div className="alfa-version">Activity Log for Admins · Version {config.version}</div>
  </div>;
}

function ActivityView({ filters, apply, heading }) {
  const [cursors, setCursors] = useState([{}]);
  const [refresh, setRefresh] = useState(0);
  const [row, setRow] = useState(null);
  const trigger = useRef(null);
  const cursor = cursors.at(-1);
  const query = useMemo(() => ({ ...filters, ...cursor }), [filters, cursor]);
  const { data, error } = useResource("events", query, refresh);
  const active = Object.values(filters).some(Boolean);
  const exportUrl = apiUrl("export", { ...filters, _wpnonce: config.nonce });
  return <>
    <section className="alfa-panel" aria-label="Activity log">
      <Filters key={JSON.stringify(filters)} filters={filters} apply={apply} exportUrl={exportUrl} />
      <div className="alfa-results">
        <p aria-live="polite">{data ? `${data.events.length} ${data.events.length === 1 ? "event" : "events"} on this page` : "Activity events"}</p>
        <Button variant="ghost" onClick={() => setRefresh(value => value + 1)}><RefreshCw aria-hidden="true" /> Refresh</Button>
      </div>
      {error ? <Alert>{error}</Alert> : data?.events.length
        ? <EventTable events={data.events} select={(event, button) => { trigger.current = button; setRow(event); }} />
        : <div className="alfa-empty">
          {data ? <CircleHelp size={25} aria-hidden="true" /> : <Clock3 size={25} aria-hidden="true" />}
          <h2 role={data ? undefined : "status"}>{data ? (active ? "No matching events" : "No activity recorded yet") : "Loading activity…"}</h2>
          {data && <p>{active ? "Try a different search or clear the filters." : "New events will appear here as changes happen in WordPress."}</p>}
          {data && active && <Button variant="outline" onClick={() => apply({})}>Clear filters</Button>}
        </div>}
      {data && (cursors.length > 1 || data.next) && <div className="alfa-pagination" aria-label="Activity pages">
        <span>Page {cursors.length}</span>
        <Button variant="outline" disabled={cursors.length === 1} onClick={() => setCursors(previous => previous.slice(0, -1))}><ArrowLeft aria-hidden="true" /> Newer</Button>
        <Button variant="outline" disabled={!data.next} onClick={() => setCursors(previous => [...previous, { before: data.next }])}>Older <ArrowRight aria-hidden="true" /></Button>
      </div>}
    </section>
    <EventDetails row={row} trigger={trigger} fallback={heading} close={() => setRow(null)} related={(key, value) => {
      setRow(null);
      apply({ [key]: value });
      requestAnimationFrame(() => heading.current?.focus());
    }} />
  </>;
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
  function navigate(filters, settings = false) {
    const url = viewUrl(filters, settings);
    if (url.href !== location.href) history.pushState(null, "", url);
    setView(readView());
    setRevision(value => value + 1);
  }
  function follow(event, settings) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    navigate(view.filters, settings);
  }
  return <div className="alfa-app">
    <div className="alfa-heading">
      <div className="alfa-mark"><Activity size={25} aria-hidden="true" /></div>
      <div><h1 ref={heading} tabIndex={-1}>Activity Log</h1><p>See who changed what across this WordPress site.</p></div>
    </div>
    <nav className="alfa-nav" aria-label="Activity Log">
      <a href={viewUrl(view.filters)} onClick={event => follow(event, false)} aria-current={view.settings ? undefined : "page"}><Activity size={16} aria-hidden="true" /> Activity</a>
      <a href={viewUrl(view.filters, true)} onClick={event => follow(event, true)} aria-current={view.settings ? "page" : undefined}><Settings2 size={16} aria-hidden="true" /> Settings</a>
    </nav>
    {view.settings ? <Settings /> : <ActivityView key={revision} filters={view.filters} apply={navigate} heading={heading} />}
  </div>;
}

createRoot(document.getElementById("alfa-root")).render(<App />);
