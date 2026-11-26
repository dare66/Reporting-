import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { CheckCheck, FileDown, Pause, Play, Plus, Radar, Square } from "lucide-react";
import { api, fmtTime, wsUrl } from "../services/api";
import { useApi } from "../hooks/useApi";
import { useAuth } from "../hooks/useAuth";
import type { Scan } from "../services/types";
import { Alert, Badge, Card, ConfirmDialog, Empty, Field, PageHeader, Spinner, Table } from "../components/ui";

const METHODS = [
  { id: "icmp", label: "ICMP ping" }, { id: "tcp", label: "TCP connect (approved ports)" }, { id: "arp", label: "ARP (local subnet)" },
  { id: "reverse_dns", label: "Reverse DNS" }, { id: "nmap", label: "Nmap host discovery" }, { id: "os_fingerprint", label: "OS fingerprint (if authorised)" },
];

export function Discovery() {
  const { reference } = useParams();
  return reference ? <ScanDetail reference={reference} /> : <PlanAndHistory />;
}

function PlanAndHistory() {
  const { mode, can } = useAuth();
  const nav = useNavigate();
  const policy = useApi<any>("/api/policy");
  const scans = useApi<Scan[]>("/api/discovery");
  const [range, setRange] = useState("");
  const [octets, setOctets] = useState("1-254");
  const [methods, setMethods] = useState<string[]>(["icmp", "tcp", "arp", "reverse_dns"]);
  const [ports, setPorts] = useState<number[] | null>(null);
  const [large, setLarge] = useState(false);
  const [plan, setPlan] = useState<any>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const wildcard = /\.(x{1,3}|\*)$/i.test(range.trim());
  const portOptions = useMemo(() => (policy.data?.ports ?? []).filter((p: any) => p.enabled), [policy.data]);
  const selectedPorts = ports ?? portOptions.filter((p: any) => p.use_in_discovery).map((p: any) => p.port);
  const disabled = mode === "review" || !can("operator");

  const review = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null); setPlan(null);
    try {
      setPlan(await api("/api/discovery/plan", { body: { range, wildcard_octets: wildcard ? octets : undefined, methods, ports: selectedPorts, confirm_large: large } }));
    } catch (err: any) { setError(err.message); } finally { setBusy(false); }
  };
  const start = async () => {
    setBusy(true);
    try { await api(`/api/discovery/${plan.reference}/start`, { body: { confirm: true } }); nav(`/discovery/${plan.reference}`); }
    catch (err: any) { setError(err.message); setConfirmOpen(false); } finally { setBusy(false); }
  };
  const toggle = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter(x => x !== v) : [...list, v]);

  return (
    <div className="space-y-5">
      <PageHeader title="Network range discovery" subtitle="Find active devices in approved on-premises ranges and compare them with the inventory." />
      <div className="grid gap-5 lg:grid-cols-5">
        <Card title="1. Define the range" icon={<Radar className="size-4 text-sky-500" />} className="lg:col-span-3">
          <form onSubmit={review} className="space-y-4">
            <Field label="IP range" htmlFor="rng" hint="Single IP, wildcard 10.1.57.xx, CIDR 10.1.57.0/24 or range 10.1.57.1-10.1.57.254">
              <input id="rng" className="input mono" value={range} onChange={e => { setRange(e.target.value); setPlan(null); }} placeholder="10.1.57.xx" required spellCheck={false} autoCapitalize="off" />
            </Field>
            {wildcard && <Field label="Final-octet range for the wildcard" htmlFor="oct"><input id="oct" className="input mono w-40" value={octets} onChange={e => setOctets(e.target.value)} /></Field>}
            <fieldset>
              <legend className="label">Discovery methods</legend>
              <div className="grid gap-2 sm:grid-cols-2">{METHODS.map(m => (
                <label key={m.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={methods.includes(m.id)} onChange={() => setMethods(toggle(methods, m.id))} className="size-4 accent-sky-600" />{m.label}</label>))}</div>
            </fieldset>
            <fieldset>
              <legend className="label">Approved ports to check</legend>
              <div className="flex flex-wrap gap-1.5">{portOptions.map((p: any) => (
                <button type="button" key={p.port} aria-pressed={selectedPorts.includes(p.port)} onClick={() => setPorts(toggle(selectedPorts, p.port))}
                  className={`rounded-md px-2 py-1 text-xs font-medium ${selectedPorts.includes(p.port) ? "bg-sky-600 text-white" : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"}`}>{p.port} {p.service}</button>))}</div>
            </fieldset>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={large} onChange={e => setLarge(e.target.checked)} className="size-4 accent-sky-600" />I confirm a range larger than the configured maximum (requires admin setting)</label>
            {error && <Alert kind="error">{error}</Alert>}
            <button className="btn-primary" disabled={busy || disabled}>{busy ? "Checking…" : "Review plan"}</button>
            {disabled && <p className="text-xs text-amber-600">{mode === "review" ? "Review mode is read-only." : "Your role cannot run discovery."}</p>}
          </form>
        </Card>
        <Card title="2. Review and confirm" className="lg:col-span-2">
          {!plan ? <Empty>The plan shows exactly what will be checked before anything is sent on the network.</Empty> : (
            <div className="space-y-3 text-sm" data-testid="scan-plan">
              <p className="text-3xl font-semibold tabular-nums">{plan.address_count}<span className="ml-1 text-sm font-normal text-slate-500">addresses</span></p>
              <dl className="space-y-1.5">
                <div><dt className="label">Range</dt><dd className="mono">{plan.first} – {plan.last}</dd></div>
                <div><dt className="label">Approved network</dt><dd>{plan.approved_network} ({plan.approved_network_name})</dd></div>
                <div><dt className="label">Methods</dt><dd>{plan.methods.map((m: any) => m.description).join("; ")}</dd></div>
                <div><dt className="label">Ports</dt><dd>{plan.ports.join(", ")}</dd></div>
                <div><dt className="label">Limits</dt><dd>{plan.concurrency} concurrent, {plan.rate_per_second} probes/s, about {Math.ceil(plan.estimated_seconds)}s</dd></div>
              </dl>
              {plan.notes.map((n: string) => <p key={n} className="text-xs text-slate-500">• {n}</p>)}
              {plan.simulated && <Alert kind="info">Simulation mode: the lab model answers; nothing is sent on the network.</Alert>}
              <button className="btn-primary w-full" onClick={() => setConfirmOpen(true)}><Play className="size-4" />Start discovery</button>
            </div>)}
        </Card>
      </div>
      <Card title="Discovery history">
        {scans.loading ? <Spinner /> : !scans.data?.length ? <Empty>No scans yet.</Empty> : (
          <Table>
            <thead><tr><th className="th">Reference</th><th className="th">Range</th><th className="th">Status</th><th className="th">Responding</th><th className="th hidden sm:table-cell">By</th><th className="th hidden md:table-cell">Created</th></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{scans.data.map(s => (
              <tr key={s.reference}><td className="td"><Link className="mono text-sky-600 hover:underline" to={`/discovery/${s.reference}`}>{s.reference}</Link>{s.simulated && <span className="ml-1"><Badge tone="blue">sim</Badge></span>}</td>
                <td className="td mono">{s.normalized_range}</td><td className="td"><Badge>{s.status}</Badge></td><td className="td tabular-nums">{s.responding}/{s.progress || s.address_count}</td>
                <td className="td hidden sm:table-cell">{s.user}</td><td className="td hidden md:table-cell">{fmtTime(s.created_at)}</td></tr>))}</tbody>
          </Table>)}
      </Card>
      <ConfirmDialog open={confirmOpen} title="Start network discovery?" confirmLabel="Start discovery" busy={busy} onClose={() => setConfirmOpen(false)} onConfirm={start}>
        {plan && <p>This will check <strong>{plan.address_count}</strong> addresses in <span className="mono">{plan.normalized_range}</span> from this laptop using {plan.methods.map((m: any) => m.id).join(", ")} on ports {plan.ports.join(", ")}. The scan is logged under your name.</p>}
      </ConfirmDialog>
    </div>
  );
}

function ScanDetail({ reference }: { reference: string }) {
  const { can } = useAuth();
  const scan = useApi<Scan>(`/api/discovery/${reference}`);
  const cmp = useApi<any>(`/api/discovery/${reference}/comparison`);
  const changes = useApi<any>(`/api/discovery/${reference}/changes`);
  const [progress, setProgress] = useState<{ processed: number; total: number; responding: number } | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [filter, setFilter] = useState("");
  const [registerRow, setRegisterRow] = useState<any>(null);
  const [regName, setRegName] = useState("");
  const [regCat, setRegCat] = useState("Other");
  const [msg, setMsg] = useState<string | null>(null);
  const s = scan.data;
  const st = status ?? s?.status;
  const active = st === "running" || st === "paused" || st === "cancelling";

  useEffect(() => {
    if (!s || !["running", "paused", "planned", "cancelling"].includes(s.status)) return;
    const ws = new WebSocket(wsUrl(`/ws/scans/${reference}`));
    ws.onmessage = ev => {
      const e = JSON.parse(ev.data);
      if (e.type === "progress") setProgress(e);
      if (e.type === "status") setStatus(e.status);
      if (e.type === "finished") { setStatus(e.status); scan.reload(); cmp.reload(); changes.reload(); }
    };
    ws.onerror = () => { const t = setInterval(() => scan.reload(), 2000); ws.onclose = () => clearInterval(t); };
    return () => ws.close();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [s?.status, reference]);

  const control = async (action: string) => {
    try { const r = await api(`/api/discovery/${reference}/control`, { body: { action } }); setStatus(r.status); }
    catch (e: any) { setMsg(e.message); }
  };
  const report = async (type: string, format: string) => {
    try { const r = await api("/api/reports", { body: { report_type: type, format, scan_reference: reference } }); window.location.href = r.download_url; }
    catch (e: any) { setMsg(e.message); }
  };
  const register = async () => {
    try {
      await api(`/api/discovery/devices/${registerRow.device.id}/register`, { body: { name: regName || undefined, category: regCat } });
      setRegisterRow(null); setMsg(`${registerRow.ip_address} registered in the inventory.`); cmp.reload();
    } catch (e: any) { setMsg(e.message); }
  };
  const reviewed = async (id: number) => { await api(`/api/comparisons/${id}/review`, { method: "POST" }); cmp.reload(); };

  if (scan.loading && !s) return <Spinner label="Loading scan…" />;
  if (scan.error || !s) return <Alert kind="error">{scan.error ?? "Not found"}</Alert>;
  const total = progress?.total ?? s.address_count;
  const done = progress?.processed ?? s.progress;
  const rows = (cmp.data?.rows ?? []).filter((r: any) => !filter || r.classification === filter);
  const counts: Record<string, number> = {};
  (cmp.data?.rows ?? []).forEach((r: any) => { counts[r.classification] = (counts[r.classification] ?? 0) + 1; });
  const unregistered = ["Newly Discovered", "Unknown Device", "Possible Unauthorised Asset"];

  return (
    <div className="space-y-5">
      <PageHeader title={`Discovery ${s.reference}`} subtitle={`${s.normalized_range} · ${s.address_count} addresses · ${s.methods.join(", ")} · ports ${s.ports.join(", ")}`}
        actions={<>
          <Link to="/discovery" className="btn-secondary">All scans</Link>
          {!active && s.status !== "planned" && <>
            <button className="btn-secondary" onClick={() => report("discovery", "pdf")}><FileDown className="size-4" />PDF</button>
            <button className="btn-secondary" onClick={() => report("discovery", "xlsx")}><FileDown className="size-4" />Excel</button>
            <button className="btn-secondary" onClick={() => report("unknown_devices", "pdf")}><FileDown className="size-4" />Unknown devices</button>
          </>}
        </>} />
      {msg && <Alert kind="info">{msg}</Alert>}
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2"><Badge>{st ?? ""}</Badge>{s.simulated && <Badge tone="blue">Simulated</Badge>}<span className="text-sm text-slate-500">from {s.source_network}</span></div>
          {active && can("operator") && <div className="flex gap-2">
            {st === "running" && <button className="btn-secondary" onClick={() => control("pause")}><Pause className="size-4" />Pause</button>}
            {st === "paused" && <button className="btn-secondary" onClick={() => control("resume")}><Play className="size-4" />Resume</button>}
            <button className="btn-danger" onClick={() => control("cancel")}><Square className="size-4" />Cancel</button>
          </div>}
        </div>
        <div className="mt-3">
          <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={done}>
            <div className="h-full bg-sky-500 transition-[width]" style={{ width: `${Math.min(100, (done / total) * 100)}%` }} />
          </div>
          <p className="mt-1 text-xs text-slate-500 tabular-nums">{done} of {total} addresses checked · {progress?.responding ?? s.responding} responding</p>
        </div>
        {s.notes.map(n => <p key={n} className="mt-1 text-xs text-slate-500">• {n}</p>)}
        {s.error && <p className="mt-2 text-sm text-rose-600">{s.error}</p>}
      </Card>

      {!active && cmp.data && (
        <Card title="Inventory comparison">
          <div className="mb-3 flex flex-wrap gap-1.5">
            <button onClick={() => setFilter("")} className={`rounded-md px-2 py-1 text-xs ${!filter ? "bg-sky-600 text-white" : "bg-slate-100 dark:bg-slate-800"}`}>All ({cmp.data.rows.length})</button>
            {Object.entries(counts).map(([k, v]) => <button key={k} onClick={() => setFilter(k)} className={`rounded-md px-2 py-1 text-xs ${filter === k ? "bg-sky-600 text-white" : "bg-slate-100 dark:bg-slate-800"}`}>{k} ({v})</button>)}
          </div>
          <Table>
            <thead><tr><th className="th">IP</th><th className="th">Classification</th><th className="th">Hostname</th><th className="th hidden md:table-cell">MAC</th><th className="th">Open ports</th><th className="th hidden lg:table-cell">Detected by</th><th className="th">Detail</th><th className="th"></th></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((r: any) => (
              <tr key={r.id}>
                <td className="td mono">{r.ip_address}</td>
                <td className="td"><Badge>{r.classification}</Badge></td>
                <td className="td">{r.device?.hostname ?? r.server?.hostname ?? <span className="text-slate-400">unknown</span>}</td>
                <td className="td mono hidden md:table-cell">{r.device?.mac_address ?? <span className="text-slate-400">n/a</span>}</td>
                <td className="td">{r.device?.ports.filter((p: any) => p.state === "open").map((p: any) => p.port).join(", ") || "-"}</td>
                <td className="td hidden text-xs lg:table-cell">{r.device?.detection_methods.join(", ") ?? "not detected"}</td>
                <td className="td max-w-xs text-xs text-slate-500">{r.detail}</td>
                <td className="td whitespace-nowrap">
                  {can("operator") && unregistered.includes(r.classification) && !r.reviewed && <>
                    <button className="btn-ghost px-2 py-1 text-xs" onClick={() => { setRegisterRow(r); setRegName(r.device?.hostname ?? ""); }}><Plus className="size-3.5" />Register</button>
                    <button className="btn-ghost px-2 py-1 text-xs" onClick={() => reviewed(r.id)}><CheckCheck className="size-3.5" />Reviewed</button></>}
                  {r.reviewed && <Badge tone="green">reviewed</Badge>}
                </td>
              </tr>))}</tbody>
          </Table>
          {changes.data?.previous_reference && (
            <div className="mt-4 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-950/50">
              <p className="font-medium">Changes since {changes.data.previous_reference}</p>
              <p className="text-slate-600 dark:text-slate-300">Appeared: {changes.data.appeared.join(", ") || "none"} · Disappeared: {changes.data.disappeared.join(", ") || "none"} · Changed: {changes.data.changed.length}</p>
            </div>)}
        </Card>
      )}

      <ConfirmDialog open={!!registerRow} title={`Register ${registerRow?.ip_address ?? ""}`} confirmLabel="Register asset" onClose={() => setRegisterRow(null)} onConfirm={register}>
        <div className="space-y-3">
          <p>Adds this device to the local inventory with its open ports as approved ports. Review the classification first; it is not proof of a problem.</p>
          <Field label="Server name" htmlFor="rn"><input id="rn" className="input" value={regName} onChange={e => setRegName(e.target.value)} /></Field>
          <Field label="Category" htmlFor="rc"><select id="rc" className="input" value={regCat} onChange={e => setRegCat(e.target.value)}>
            {["STARS", "Visa Website", "EMGS Hub", "CRM", "Database", "File Server", "Web Server", "Application Server", "Network Device", "Other"].map(c => <option key={c}>{c}</option>)}</select></Field>
        </div>
      </ConfirmDialog>
    </div>
  );
}
