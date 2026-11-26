import { useState } from "react";
import { Link } from "react-router-dom";
import { AlertTriangle, CheckCircle2, Lock, Radar, Server, ShieldAlert, Sparkles, XCircle, Zap } from "lucide-react";
import { useApi } from "../hooks/useApi";
import { fmtTime } from "../services/api";
import type { Job } from "../services/types";
import { Alert, Badge, Card, Empty, PageHeader, Spinner, Stat } from "../components/ui";
import { QuickDiagnostic } from "../features/QuickDiagnostic";
import { ResultView } from "../components/ResultView";

export function Dashboard() {
  const { data: d, error, loading, reload } = useApi<any>("/api/dashboard");
  const [last, setLast] = useState<Job | null>(null);
  if (loading && !d) return <Spinner label="Loading dashboard…" />;
  if (error) return <Alert kind="error">{error}</Alert>;
  if (!d) return null;
  const t = d.totals;
  const ps = d.port_summary;
  const totalPorts = ps.open + ps.closed + ps.filtered || 1;
  const obsIcon = { error: <XCircle className="size-4 text-rose-500" />, warning: <AlertTriangle className="size-4 text-amber-500" />, info: <Sparkles className="size-4 text-violet-500" /> } as Record<string, JSX.Element>;

  return (
    <div className="space-y-5">
      <PageHeader title="Network overview" subtitle={d.simulated ? "Simulation data from the built-in lab, not EMGS infrastructure." : "Results recorded from this laptop."} />
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Registered assets" value={t.registered_assets} icon={<Server className="size-5" />} hint={t.sample_assets ? `${t.sample_assets} sample records` : undefined} />
        <Stat label="Active in last discovery" value={t.active_discovered} icon={<Radar className="size-5" />} tone="blue" />
        <Stat label="Need review" value={t.unidentified_devices} icon={<ShieldAlert className="size-5" />} tone={t.unidentified_devices ? "amber" : "green"} hint="unknown / new devices" />
        <Stat label="Failures (7 days)" value={t.recent_failures} icon={<XCircle className="size-5" />} tone={t.recent_failures ? "red" : "green"} hint={`${t.diagnostics_7d} checks run`} />
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <Card title="Quick diagnostic" icon={<Zap className="size-4 text-sky-500" />} className="lg:col-span-2">
          <QuickDiagnostic onResult={j => { setLast(j); reload(); }} />
          {last && <div className="mt-4"><ResultView job={last} /></div>}
        </Card>
        <Card title="Observations" icon={<Sparkles className="size-4 text-violet-500" />}>
          <ul className="space-y-2">{d.observations.map((o: any, i: number) => <li key={i} className="flex gap-2 text-sm">{obsIcon[o.level]}<span>{o.text}</span></li>)}</ul>
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <Card title="Recent connectivity failures" icon={<XCircle className="size-4 text-rose-500" />}>
          {d.recent_failures.length === 0 ? <Empty>No failures in the last 7 days.</Empty> : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">{d.recent_failures.map((f: any) => (
              <li key={f.reference} className="flex items-center justify-between gap-2 py-2 text-sm">
                <span className="min-w-0"><span className="mono">{f.target}{f.port ? `:${f.port}` : ""}</span> <span className="text-xs text-slate-500">{f.type}</span></span>
                <Badge>{f.outcome}</Badge>
              </li>))}</ul>)}
        </Card>
        <Card title="Port accessibility (7 days)" icon={<Lock className="size-4 text-slate-500" />}>
          <div className="flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden>
            <div className="bg-emerald-500" style={{ width: `${(ps.open / totalPorts) * 100}%` }} />
            <div className="bg-rose-500" style={{ width: `${(ps.closed / totalPorts) * 100}%` }} />
            <div className="bg-amber-400" style={{ width: `${(ps.filtered / totalPorts) * 100}%` }} />
          </div>
          <dl className="mt-3 grid grid-cols-3 text-center text-sm">
            <div><dt className="text-xs text-slate-500">Open</dt><dd className="font-semibold text-emerald-600">{ps.open}</dd></div>
            <div><dt className="text-xs text-slate-500">Closed</dt><dd className="font-semibold text-rose-600">{ps.closed}</dd></div>
            <div><dt className="text-xs text-slate-500">No answer</dt><dd className="font-semibold text-amber-600">{ps.filtered}</dd></div>
          </dl>
        </Card>
        <Card title="SSL certificates nearing expiry" icon={<Lock className="size-4 text-amber-500" />}>
          {d.ssl_expiring.length === 0 ? <Empty>No certificates expiring within 60 days have been checked.</Empty> : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">{d.ssl_expiring.map((c: any) => (
              <li key={c.host + c.port} className="flex items-center justify-between py-2 text-sm">
                <span className="mono truncate">{c.host}:{c.port}</span>
                <Badge tone={c.days_remaining < 0 ? "red" : c.days_remaining < 15 ? "red" : "amber"}>{c.days_remaining < 0 ? "expired" : `${c.days_remaining} days`}</Badge>
              </li>))}</ul>)}
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Recent subnet discovery" icon={<Radar className="size-4 text-sky-500" />} actions={<Link to="/discovery" className="text-xs text-sky-600 hover:underline">Open discovery</Link>}>
          {!d.latest_scan ? <Empty>No discovery has been run yet.</Empty> : (
            <div className="space-y-3">
              <p className="text-sm"><Link className="mono text-sky-600 hover:underline" to={`/discovery/${d.latest_scan.reference}`}>{d.latest_scan.reference}</Link> · {d.latest_scan.normalized_range} · {d.latest_scan.responding} responding of {d.latest_scan.progress}</p>
              <div className="flex flex-wrap gap-2">{Object.entries(d.latest_scan_classification).map(([k, v]) => <Badge key={k}>{`${k}: ${v}`}</Badge>)}</div>
            </div>)}
          {d.scan_history.length > 1 && (
            <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
              <h3 className="label">Discovery history</h3>
              <ul className="space-y-1 text-sm">{d.scan_history.slice(0, 5).map((s: any) => (
                <li key={s.reference} className="flex justify-between gap-2"><Link to={`/discovery/${s.reference}`} className="mono text-sky-600 hover:underline">{s.reference}</Link><span className="text-slate-500">{s.normalized_range}</span><Badge>{s.status}</Badge></li>))}</ul>
            </div>)}
        </Card>
        <Card title="Recent diagnostic activity" icon={<CheckCircle2 className="size-4 text-emerald-500" />} actions={<Link to="/history" className="text-xs text-sky-600 hover:underline">All history</Link>}>
          {d.recent_activity.length === 0 ? <Empty>No diagnostics yet. Try the quick diagnostic above.</Empty> : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">{d.recent_activity.slice(0, 8).map((j: any) => (
              <li key={j.reference} className="flex items-center justify-between gap-2 py-2 text-sm">
                <span className="min-w-0 truncate"><span className="text-xs uppercase text-slate-500">{j.type}</span> <span className="mono">{j.target}</span></span>
                <span className="flex shrink-0 items-center gap-2"><span className="hidden text-xs text-slate-500 sm:inline">{fmtTime(j.started_at)}</span><Badge>{j.outcome || j.status}</Badge></span>
              </li>))}</ul>)}
        </Card>
      </div>
    </div>
  );
}
