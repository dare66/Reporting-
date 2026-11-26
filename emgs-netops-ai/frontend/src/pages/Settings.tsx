import { useEffect, useState, type FormEvent } from "react";
import { Bot, Database, Globe, KeyRound, Laptop, Network, Plug, Shield, Smartphone, Trash2, Users } from "lucide-react";
import { api, fmtTime } from "../services/api";
import { useApi } from "../hooks/useApi";
import { useAuth } from "../hooks/useAuth";
import { Alert, Badge, Card, ConfirmDialog, Field, PageHeader, Spinner, Table } from "../components/ui";

type Msg = { k: "success" | "error" | "info"; t: string } | null;

const NUMERIC: [string, string, string][] = [
  ["default_ping_count", "Default ping count", "1-20"], ["ping_timeout_s", "Ping timeout (s)", "1-10"],
  ["execution_timeout_s", "Execution timeout (s)", "5-600"], ["traceroute_max_hops", "Traceroute max hops", "1-30"],
  ["tcp_timeout_s", "TCP timeout (s)", "1-15"], ["scan_concurrency", "Scan concurrency", "1-128"],
  ["scan_rate_per_second", "Scan probes per second", "1-500"], ["max_subnet_prefix", "Largest scan (CIDR prefix)", "16-32; 24 = 256 addresses"],
  ["rate_limit_per_minute", "Diagnostics per user per minute", "5-1000"], ["retention_days", "Data retention (days)", "7-3650"],
  ["session_hours", "Session length (hours)", "1-24"],
];

export function SettingsPage() {
  const { can, refresh } = useAuth();
  const admin = can("admin");
  const s = useApi<any>("/api/settings");
  const sys = useApi<any>("/api/system");
  const [vals, setVals] = useState<Record<string, any>>({});
  const [msg, setMsg] = useState<Msg>(null);
  useEffect(() => { if (s.data) setVals(s.data.values); }, [s.data]);

  const save = async (values: Record<string, any>) => {
    try { await api("/api/settings", { method: "PUT", body: { values } }); setMsg({ k: "success", t: "Settings saved." }); s.reload(); sys.reload(); refresh(); }
    catch (e: any) { setMsg({ k: "error", t: e.message }); }
  };
  if (!s.data) return <Spinner label="Loading settings…" />;

  return (
    <div className="space-y-5">
      <PageHeader title="Settings" subtitle={admin ? "Administrator configuration for this laptop." : "Read-only for your role. Account and API tokens are yours to manage."} />
      {msg && <Alert kind={msg.k}>{msg.t}</Alert>}

      <Card title="Operating mode" icon={<Laptop className="size-4 text-sky-500" />}>
        <div className="grid gap-3 md:grid-cols-3">
          {[["simulation", "Simulation", "Development and testing. Built-in lab answers; nothing touches EMGS infrastructure."],
            ["live", "Live", "Real diagnostics from this laptop on the authorised EMGS network. Sample policy rows are ignored."],
            ["review", "Review", "Read-only review of collected results and inventory, e.g. when travelling or offline."]].map(([id, label, text]) => (
            <label key={id} className={`card cursor-pointer p-3 ${vals.operating_mode === id ? "ring-2 ring-sky-500" : ""} ${!admin ? "cursor-not-allowed opacity-70" : ""}`}>
              <div className="flex items-center gap-2"><input type="radio" name="mode" disabled={!admin} checked={vals.operating_mode === id} onChange={() => save({ operating_mode: id })} className="accent-sky-600" /><span className="font-medium">{label}</span></div>
              <p className="mt-1 text-xs text-slate-500">{text}</p>
            </label>))}
        </div>
      </Card>

      <PolicyCard admin={admin} setMsg={setMsg} />

      <Card title="Execution and discovery limits" icon={<Shield className="size-4 text-slate-500" />}>
        <form onSubmit={(e: FormEvent) => { e.preventDefault(); const out: Record<string, any> = {}; NUMERIC.forEach(([k]) => { out[k] = Number(vals[k]); });
          out.allow_large_scans = vals.allow_large_scans; out.default_wildcard_range = vals.default_wildcard_range; out.application_name = vals.application_name;
          out.report_storage_path = vals.report_storage_path; out.backup_path = vals.backup_path; save(out); }}>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Application name" htmlFor="s-app"><input id="s-app" className="input" disabled={!admin} value={vals.application_name ?? ""} onChange={e => setVals({ ...vals, application_name: e.target.value })} /></Field>
            {NUMERIC.map(([k, label, hint]) => <Field key={k} label={label} hint={hint} htmlFor={`s-${k}`}><input id={`s-${k}`} type="number" className="input" disabled={!admin} value={vals[k] ?? ""} onChange={e => setVals({ ...vals, [k]: e.target.value })} /></Field>)}
            <Field label="Wildcard default octets" htmlFor="s-wc"><input id="s-wc" className="input" disabled={!admin} value={vals.default_wildcard_range ?? ""} onChange={e => setVals({ ...vals, default_wildcard_range: e.target.value })} /></Field>
            <Field label="Report storage path" htmlFor="s-rp" hint="Blank = data/reports"><input id="s-rp" className="input" disabled={!admin} value={vals.report_storage_path ?? ""} onChange={e => setVals({ ...vals, report_storage_path: e.target.value })} /></Field>
            <Field label="Backup path" htmlFor="s-bp" hint="Blank = data/backups"><input id="s-bp" className="input" disabled={!admin} value={vals.backup_path ?? ""} onChange={e => setVals({ ...vals, backup_path: e.target.value })} /></Field>
          </div>
          <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" disabled={!admin} checked={!!vals.allow_large_scans} onChange={e => setVals({ ...vals, allow_large_scans: e.target.checked })} className="size-4 accent-sky-600" />Allow scans larger than the maximum (users must also confirm each one)</label>
          {admin && <button className="btn-primary mt-3">Save limits</button>}
        </form>
        <dl className="mt-4 grid gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500 sm:grid-cols-3 dark:border-slate-800">
          <div><dt className="font-medium">Host OS</dt><dd>{s.data.bootstrap.host_os}</dd></div>
          <div><dt className="font-medium">Backend</dt><dd className="mono">{s.data.bootstrap.bind_host}:{s.data.bootstrap.backend_port} (frontend dev {s.data.bootstrap.frontend_port})</dd></div>
          <div><dt className="font-medium">Database</dt><dd className="mono break-all">{s.data.bootstrap.database_path}</dd></div>
          {sys.data && <div className="sm:col-span-3"><dt className="font-medium">Network tools on this laptop</dt><dd className="flex flex-wrap gap-1.5 pt-1">{Object.entries(sys.data.tools).map(([t, ok]) => <Badge key={t} tone={ok ? "green" : "slate"}>{`${t}: ${ok ? "yes" : "no"}`}</Badge>)}</dd></div>}
        </dl>
      </Card>

      <AiCard admin={admin} setMsg={setMsg} />
      <TokensCard setMsg={setMsg} />
      <MobileCard sys={sys.data} vals={vals} admin={admin} save={save} />
      {admin && <UsersCard setMsg={setMsg} />}
      {admin && <BackupCard setMsg={setMsg} />}
      <PasswordCard setMsg={setMsg} />
    </div>
  );
}

function PolicyCard({ admin, setMsg }: { admin: boolean; setMsg: (m: Msg) => void }) {
  const p = useApi<any>("/api/policy");
  const [net, setNet] = useState({ name: "", cidr: "", allow_discovery: true, allow_os_fingerprint: false });
  const [dom, setDom] = useState("");
  const [port, setPort] = useState({ port: "", service: "", use_in_discovery: false });
  const act = async (fn: () => Promise<any>, ok: string) => { try { await fn(); setMsg({ k: "success", t: ok }); p.reload(); } catch (e: any) { setMsg({ k: "error", t: e.message }); } };
  if (!p.data) return null;
  return (
    <Card title="Approved targets" icon={<Network className="size-4 text-emerald-500" />}>
      <Alert kind="info">Diagnostics and discovery only reach these networks, domains and ports. Rows marked sample apply only in Simulation mode; edit or re-add them to approve for Live mode.</Alert>
      <div className="mt-4 grid gap-5 xl:grid-cols-3">
        <div>
          <h3 className="label">Networks</h3>
          <ul className="space-y-1.5">{p.data.networks.map((n: any) => (
            <li key={n.id} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm dark:border-slate-800">
              <span className="min-w-0"><span className="mono">{n.cidr}</span> <span className="text-xs text-slate-500">{n.name}</span> {n.is_sample && <Badge tone="violet">sample</Badge>}
                <span className="block text-[11px] text-slate-500">{[n.allow_diagnostics && "diagnostics", n.allow_discovery && "discovery", n.allow_os_fingerprint && "OS fingerprint"].filter(Boolean).join(" · ")}</span></span>
              {admin && <button className="btn-ghost px-1.5 py-1 text-rose-600" aria-label={`Remove ${n.cidr}`} onClick={() => act(() => api(`/api/policy/networks/${n.id}`, { method: "DELETE" }), "Network removed.")}><Trash2 className="size-3.5" /></button>}
            </li>))}</ul>
          {admin && <form className="mt-2 space-y-2" onSubmit={e => { e.preventDefault(); act(() => api("/api/policy/networks", { body: net }), "Network approved."); }}>
            <div className="grid grid-cols-2 gap-2"><input aria-label="Network name" className="input" placeholder="Name" value={net.name} onChange={e => setNet({ ...net, name: e.target.value })} required /><input aria-label="CIDR" className="input mono" placeholder="10.20.0.0/24" value={net.cidr} onChange={e => setNet({ ...net, cidr: e.target.value })} required /></div>
            <div className="flex flex-wrap gap-3 text-xs"><label className="flex items-center gap-1"><input type="checkbox" checked={net.allow_discovery} onChange={e => setNet({ ...net, allow_discovery: e.target.checked })} />Discovery</label><label className="flex items-center gap-1"><input type="checkbox" checked={net.allow_os_fingerprint} onChange={e => setNet({ ...net, allow_os_fingerprint: e.target.checked })} />OS fingerprint</label></div>
            <button className="btn-secondary w-full">Add network</button></form>}
        </div>
        <div>
          <h3 className="label">Domains (DNS, HTTP(S), SSL)</h3>
          <ul className="space-y-1.5">{p.data.domains.map((d: any) => (
            <li key={d.id} className="flex items-center justify-between rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm dark:border-slate-800">
              <span><span className="mono">{d.domain}</span> {d.is_sample && <Badge tone="violet">sample</Badge>}</span>
              {admin && <span className="flex">{d.is_sample && <button className="btn-ghost px-1.5 py-1 text-xs" onClick={() => act(() => api(`/api/policy/domains/${d.id}/confirm`, { method: "POST" }), "Domain approved for Live mode.")}>Approve</button>}
                <button className="btn-ghost px-1.5 py-1 text-rose-600" aria-label={`Remove ${d.domain}`} onClick={() => act(() => api(`/api/policy/domains/${d.id}`, { method: "DELETE" }), "Domain removed.")}><Trash2 className="size-3.5" /></button></span>}
            </li>))}</ul>
          {admin && <form className="mt-2 flex gap-2" onSubmit={e => { e.preventDefault(); act(() => api("/api/policy/domains", { body: { domain: dom } }), "Domain approved."); setDom(""); }}>
            <input aria-label="Domain" className="input" placeholder="emgs.com.my" value={dom} onChange={e => setDom(e.target.value)} required /><button className="btn-secondary">Add</button></form>}
        </div>
        <div>
          <h3 className="label">Ports</h3>
          <div className="flex flex-wrap gap-1.5">{p.data.ports.map((x: any) => (
            <span key={x.port} className="inline-flex items-center gap-1 rounded-md bg-slate-100 py-0.5 pl-2 pr-1 text-xs dark:bg-slate-800">
              <span className="font-medium">{x.port}</span><span className="text-slate-500">{x.service}</span>{x.use_in_discovery && <span title="Used in discovery" className="text-sky-600">●</span>}
              {admin && <button className="rounded p-0.5 text-rose-600 hover:bg-rose-100 dark:hover:bg-rose-900/40" aria-label={`Remove port ${x.port}`} onClick={() => act(() => api(`/api/policy/ports/${x.port}`, { method: "DELETE" }), "Port removed.")}>×</button>}
            </span>))}</div>
          <p className="mt-1 text-[11px] text-slate-500">● used by default in discovery</p>
          {admin && <form className="mt-2 space-y-2" onSubmit={e => { e.preventDefault(); act(() => api("/api/policy/ports", { body: { port: Number(port.port), service: port.service, use_in_discovery: port.use_in_discovery } }), "Port approved."); }}>
            <div className="grid grid-cols-2 gap-2"><input aria-label="Port" type="number" className="input" placeholder="1433" value={port.port} onChange={e => setPort({ ...port, port: e.target.value })} required /><input aria-label="Service" className="input" placeholder="MSSQL" value={port.service} onChange={e => setPort({ ...port, service: e.target.value })} /></div>
            <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={port.use_in_discovery} onChange={e => setPort({ ...port, use_in_discovery: e.target.checked })} />Use in discovery</label>
            <button className="btn-secondary w-full">Add / update port</button></form>}
        </div>
      </div>
    </Card>
  );
}

function AiCard({ admin, setMsg }: { admin: boolean; setMsg: (m: Msg) => void }) {
  const ai = useApi<any>("/api/ai");
  const [key, setKey] = useState("");
  const [model, setModel] = useState("");
  const put = async (body: any) => { try { await api("/api/ai", { method: "PUT", body }); setKey(""); ai.reload(); setMsg({ k: "success", t: "AI settings saved." }); } catch (e: any) { setMsg({ k: "error", t: e.message }); } };
  if (!ai.data) return null;
  return (
    <Card title="Claude API (optional)" icon={<Bot className="size-4 text-violet-500" />}>
      <p className="mb-3 text-sm text-slate-500">All diagnostics work without AI. When enabled, Claude interprets free-form requests and writes explanations from structured results. Raw command output is only sent if you allow it. The key is stored encrypted on this laptop and never shown again.</p>
      <div className="mb-3 flex flex-wrap gap-2"><Badge tone={ai.data.active ? "green" : "slate"}>{ai.data.active ? "Active" : "Not active"}</Badge><Badge tone="slate">{`model: ${ai.data.model}`}</Badge>{ai.data.configured && <Badge tone="slate">{`key: ${ai.data.key_source}`}</Badge>}</div>
      {admin && <div className="grid gap-3 sm:grid-cols-3">
        <Field label="API key" htmlFor="ai-key"><input id="ai-key" type="password" className="input" autoComplete="off" placeholder={ai.data.configured ? "•••••• (stored)" : "sk-ant-…"} value={key} onChange={e => setKey(e.target.value)} /></Field>
        <Field label="Model" htmlFor="ai-model"><input id="ai-model" className="input mono" placeholder={ai.data.model} value={model} onChange={e => setModel(e.target.value)} /></Field>
        <div className="flex flex-wrap items-end gap-2">
          <button className="btn-primary" onClick={() => put({ api_key: key || undefined, model: model || undefined, enabled: true })}>Save &amp; enable</button>
          {ai.data.enabled && <button className="btn-secondary" onClick={() => put({ enabled: false })}>Disable</button>}
          {ai.data.key_source === "settings" && <button className="btn-ghost text-rose-600" onClick={() => put({ clear_api_key: true, enabled: false })}>Remove key</button>}
        </div>
        <label className="flex items-center gap-2 text-sm sm:col-span-3"><input type="checkbox" checked={ai.data.send_raw_output} onChange={e => put({ send_raw_output: e.target.checked })} className="size-4 accent-sky-600" />Include raw command output in AI explanations</label>
      </div>}
    </Card>
  );
}

function TokensCard({ setMsg }: { setMsg: (m: Msg) => void }) {
  const { can } = useAuth();
  const tokens = useApi<any[]>("/api/auth/tokens");
  const [name, setName] = useState("claude-code");
  const [created, setCreated] = useState<string | null>(null);
  const create = async () => { try { const r = await api("/api/auth/tokens", { body: { name, days: 90 } }); setCreated(r.token); tokens.reload(); } catch (e: any) { setMsg({ k: "error", t: e.message }); } };
  const revoke = async (id: number) => { await api(`/api/auth/tokens/${id}`, { method: "DELETE" }); tokens.reload(); };
  return (
    <Card title="API tokens (Claude Code MCP, Codex, CLI)" icon={<Plug className="size-4 text-slate-500" />}>
      <p className="mb-3 text-sm text-slate-500">Tokens let the MCP server and CLI act as you, with your role. Each execution is audited. Tokens expire after 90 days and can be revoked here.</p>
      {can("operator") && <div className="flex flex-wrap gap-2"><input aria-label="Token name" className="input w-48" value={name} onChange={e => setName(e.target.value)} /><button className="btn-primary" onClick={create}><KeyRound className="size-4" />Create token</button></div>}
      {created && <div className="mt-3"><Alert kind="warning">Copy this token now; it will not be shown again:<code className="mono mt-1 block break-all rounded bg-slate-900 p-2 text-slate-100">{created}</code></Alert></div>}
      {tokens.data && tokens.data.length > 0 && <div className="mt-3"><Table>
        <thead><tr><th className="th">Name</th><th className="th">Prefix</th><th className="th hidden sm:table-cell">Expires</th><th className="th hidden sm:table-cell">Last used</th><th className="th"></th></tr></thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{tokens.data.map(t => (
          <tr key={t.id}><td className="td">{t.name}</td><td className="td mono">{t.prefix}…</td><td className="td hidden sm:table-cell">{fmtTime(t.expires_at)}</td><td className="td hidden sm:table-cell">{fmtTime(t.last_used_at)}</td>
            <td className="td">{t.revoked ? <Badge tone="slate">revoked</Badge> : <button className="btn-ghost px-2 py-1 text-xs text-rose-600" onClick={() => revoke(t.id)}>Revoke</button>}</td></tr>))}</tbody>
      </Table></div>}
    </Card>
  );
}

function MobileCard({ sys, vals, admin, save }: { sys: any; vals: Record<string, any>; admin: boolean; save: (v: any) => void }) {
  const ma = vals.mobile_access ?? {};
  const [bindIp, setBindIp] = useState(ma.bind_ip ?? "");
  return (
    <Card title="Mobile access" icon={<Smartphone className="size-4 text-sky-500" />}>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-2 text-sm">
          <p>The phone uses this laptop's dashboard in its browser; every diagnostic still runs on the laptop. The API is never exposed to the public internet.</p>
          <p className="flex items-center gap-2"><Globe className="size-4 text-slate-400" aria-hidden />Currently listening on <span className="mono">{sys?.bind_host}:{sys?.port}</span> {sys?.https ? <Badge tone="green">HTTPS</Badge> : <Badge tone="amber">HTTP (laptop only)</Badge>}</p>
          <ol className="list-decimal space-y-1 pl-5 text-slate-600 dark:text-slate-300">
            <li>Join the laptop and phone to a private overlay network (Tailscale, or the corporate VPN), or the same trusted office Wi-Fi.</li>
            <li>Note the laptop's private address on that network (e.g. a 100.x Tailscale address) and enter it below.</li>
            <li>Restart with <span className="mono">start_netops --mobile</span>. HTTPS with a local certificate is generated automatically; the server binds only to that address.</li>
            <li>On the phone open <span className="mono">https://&lt;address&gt;:{(sys?.port ?? 8750) + 1}</span>, accept the certificate once, and sign in.</li>
          </ol>
          <p className="text-xs text-slate-500">See docs/deployment/mobile-access.md for full instructions.</p>
        </div>
        {admin && <div className="space-y-3">
          <Field label="Mobile bind address (private IP)" htmlFor="m-ip" hint="Tailscale / VPN / office Wi-Fi address of this laptop. Never 0.0.0.0."><input id="m-ip" className="input mono" value={bindIp} onChange={e => setBindIp(e.target.value)} placeholder="100.101.102.103" /></Field>
          <div className="flex gap-2">
            <button className="btn-primary" onClick={() => save({ mobile_access: { ...ma, enabled: true, bind_ip: bindIp, https: true } })}>Enable mobile access</button>
            {ma.enabled && <button className="btn-secondary" onClick={() => save({ mobile_access: { ...ma, enabled: false } })}>Disable</button>}
          </div>
          <Badge tone={ma.enabled ? "green" : "slate"}>{ma.enabled ? `Enabled for ${ma.bind_ip} (takes effect on restart)` : "Disabled"}</Badge>
        </div>}
      </div>
    </Card>
  );
}

function UsersCard({ setMsg }: { setMsg: (m: Msg) => void }) {
  const users = useApi<any[]>("/api/users");
  const [u, setU] = useState({ username: "", full_name: "", password: "", role: "operator" });
  const add = async (e: FormEvent) => {
    e.preventDefault();
    try { await api("/api/users", { body: { username: u.username, full_name: u.full_name, password: u.password, roles: [u.role] } }); setU({ username: "", full_name: "", password: "", role: "operator" }); users.reload(); setMsg({ k: "success", t: "User created." }); }
    catch (err: any) { setMsg({ k: "error", t: err.message }); }
  };
  const update = async (id: number, body: any) => { try { await api(`/api/users/${id}`, { method: "PUT", body }); users.reload(); } catch (e: any) { setMsg({ k: "error", t: e.message }); } };
  return (
    <Card title="Users and roles" icon={<Users className="size-4 text-slate-500" />}>
      <p className="mb-3 text-xs text-slate-500">admin: settings, policy and users · operator: run diagnostics, discovery, inventory, reports · viewer: read-only</p>
      {users.data && <Table>
        <thead><tr><th className="th">User</th><th className="th">Role</th><th className="th hidden sm:table-cell">Last login</th><th className="th">Status</th></tr></thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{users.data.map(x => (
          <tr key={x.id}><td className="td">{x.username}<div className="text-xs text-slate-500">{x.full_name}</div></td>
            <td className="td"><select aria-label={`Role for ${x.username}`} className="input py-1" value={x.roles[0]} onChange={e => update(x.id, { roles: [e.target.value] })}><option>admin</option><option>operator</option><option>viewer</option></select></td>
            <td className="td hidden sm:table-cell">{fmtTime(x.last_login_at)}</td>
            <td className="td"><button className="btn-ghost px-2 py-1 text-xs" onClick={() => update(x.id, { is_active: !x.is_active })}>{x.is_active ? <Badge tone="green">active</Badge> : <Badge tone="slate">disabled</Badge>}</button></td></tr>))}</tbody>
      </Table>}
      <form onSubmit={add} className="mt-3 grid gap-2 sm:grid-cols-5">
        <input aria-label="Username" className="input" placeholder="username" value={u.username} onChange={e => setU({ ...u, username: e.target.value })} required />
        <input aria-label="Full name" className="input" placeholder="Full name" value={u.full_name} onChange={e => setU({ ...u, full_name: e.target.value })} />
        <input aria-label="Initial password" type="password" autoComplete="new-password" className="input" placeholder="Initial password" value={u.password} onChange={e => setU({ ...u, password: e.target.value })} required />
        <select aria-label="Role" className="input" value={u.role} onChange={e => setU({ ...u, role: e.target.value })}><option>operator</option><option>viewer</option><option>admin</option></select>
        <button className="btn-secondary">Add user</button>
      </form>
    </Card>
  );
}

function BackupCard({ setMsg }: { setMsg: (m: Msg) => void }) {
  const b = useApi<any[]>("/api/maintenance/backups");
  const [restore, setRestore] = useState<string | null>(null);
  const run = async (fn: () => Promise<any>, ok: (r: any) => string) => { try { const r = await fn(); setMsg({ k: "success", t: ok(r) }); b.reload(); } catch (e: any) { setMsg({ k: "error", t: e.message }); } };
  return (
    <Card title="Backup, restore and retention" icon={<Database className="size-4 text-slate-500" />}>
      <div className="flex flex-wrap gap-2">
        <button className="btn-primary" onClick={() => run(() => api("/api/maintenance/backups", { method: "POST" }), r => `Backup ${r.file_name} created.`)}>Back up now</button>
        <button className="btn-secondary" onClick={() => run(() => api("/api/maintenance/retention", { method: "POST" }), r => `Retention applied: ${r.diagnostic_jobs} diagnostics and ${r.discovery_scans} scans older than ${r.retention_days} days removed.`)}>Apply retention now</button>
      </div>
      {b.data && b.data.length > 0 && <ul className="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-800">{b.data.slice(0, 10).map(x => (
        <li key={x.file_name} className="flex items-center justify-between py-1.5"><span className="mono">{x.file_name}</span><span className="flex items-center gap-2 text-xs text-slate-500">{(x.size_bytes / 1024).toFixed(0)} KB<button className="btn-ghost px-2 py-1 text-xs" onClick={() => setRestore(x.file_name)}>Restore</button></span></li>))}</ul>}
      <ConfirmDialog open={!!restore} danger title="Restore this backup?" confirmLabel="Restore" onClose={() => setRestore(null)}
        onConfirm={() => run(async () => { const r = await api("/api/maintenance/restore", { body: { file_name: restore, confirm: true } }); setRestore(null); setTimeout(() => location.reload(), 800); return r; }, r => `Restored. Safety copy: ${r.safety_backup}`)}>
        All current data is replaced by <span className="mono">{restore}</span>. A safety backup of the current database is created first.
      </ConfirmDialog>
    </Card>
  );
}

function PasswordCard({ setMsg }: { setMsg: (m: Msg) => void }) {
  const [cur, setCur] = useState(""); const [nw, setNw] = useState("");
  return (
    <Card title="Change my password" icon={<KeyRound className="size-4 text-slate-500" />}>
      <form className="grid gap-2 sm:grid-cols-3" onSubmit={async e => { e.preventDefault(); try { const r = await api("/api/auth/change-password", { body: { current_password: cur, new_password: nw } }); setMsg({ k: "success", t: r.message }); setCur(""); setNw(""); } catch (err: any) { setMsg({ k: "error", t: err.message }); } }}>
        <input aria-label="Current password" type="password" autoComplete="current-password" className="input" placeholder="Current password" value={cur} onChange={e => setCur(e.target.value)} required />
        <input aria-label="New password" type="password" autoComplete="new-password" className="input" placeholder="New password (12+ characters)" value={nw} onChange={e => setNw(e.target.value)} required />
        <button className="btn-secondary">Change password</button>
      </form>
    </Card>
  );
}
