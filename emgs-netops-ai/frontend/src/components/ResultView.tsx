import { useState } from "react";
import { ChevronDown, Clock, Network, Terminal } from "lucide-react";
import type { Job } from "../services/types";
import { fmtTime } from "../services/api";
import { AssessmentView } from "./AssessmentView";
import { Badge, Table } from "./ui";

const n = (v: any, d = 2) => (v === null || v === undefined ? "-" : typeof v === "number" ? v.toFixed(d) : String(v));

function KV({ items }: { items: [string, any][] }) {
  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
      {items.map(([k, v]) => (
        <div key={k} className="min-w-0">
          <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{k}</dt>
          <dd className="truncate text-sm font-medium tabular-nums" title={String(v ?? "")}>{v ?? "-"}</dd>
        </div>
      ))}
    </dl>
  );
}

function Body({ job }: { job: Job }) {
  const r = job.result || {};
  switch (job.type) {
    case "ping":
      return (
        <div className="space-y-3">
          <KV items={[["Target IP", r.target_ip], ["Hostname", r.hostname || "-"], ["Sent / received", `${r.packets_transmitted ?? "-"} / ${r.packets_received ?? "-"}`],
            ["Packet loss", r.packet_loss_pct !== undefined ? `${r.packet_loss_pct}%` : "-"], ["Min ms", n(r.min_latency_ms)], ["Avg ms", n(r.avg_latency_ms)],
            ["Max ms", n(r.max_latency_ms)], ["Duration", `${n(job.duration_ms, 0)} ms`]]} />
          {r.replies_ms?.length > 0 && (
            <div className="flex h-12 items-end gap-1" aria-label="Reply latency per packet">
              {r.replies_ms.map((t: number, i: number) => {
                const max = Math.max(...r.replies_ms);
                return <div key={i} title={`${t} ms`} className="w-6 rounded-t bg-sky-500/80" style={{ height: `${Math.max(12, (t / max) * 100)}%` }} />;
              })}
            </div>
          )}
        </div>
      );
    case "traceroute":
      return (
        <Table>
          <thead><tr><th className="th">Hop</th><th className="th">Router IP</th><th className="th">Hostname</th><th className="th">Avg ms</th><th className="th">Timeouts</th><th className="th">Status</th></tr></thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {(r.hops || []).map((h: any) => (
              <tr key={h.hop}>
                <td className="td tabular-nums">{h.hop}</td><td className="td mono">{h.ip ?? "*"}</td><td className="td">{h.hostname ?? "-"}</td>
                <td className="td tabular-nums">{n(h.avg_ms)}</td><td className="td">{h.timeouts}</td>
                <td className="td"><Badge tone={h.status === "ok" ? "green" : h.status === "partial" ? "amber" : "slate"}>{h.status === "timeout" ? "no reply" : h.status}</Badge></td>
              </tr>
            ))}
          </tbody>
        </Table>
      );
    case "port":
      return (
        <Table>
          <thead><tr><th className="th">Destination</th><th className="th">Port</th><th className="th">Service</th><th className="th">Status</th><th className="th">Response</th><th className="th">Detail</th></tr></thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {(r.results || []).map((p: any) => (
              <tr key={p.port}>
                <td className="td mono">{p.destination}</td><td className="td tabular-nums">{p.port}/{p.protocol}</td><td className="td">{p.service || "-"}</td>
                <td className="td"><Badge>{p.status}</Badge></td><td className="td tabular-nums">{p.response_ms != null ? `${n(p.response_ms)} ms` : "-"}</td>
                <td className="td text-xs text-slate-500">{p.error || ""}</td>
              </tr>
            ))}
          </tbody>
        </Table>
      );
    case "dns":
      return (
        <div className="space-y-3">
          <KV items={[["Domain", r.domain], ["Record type", r.record_type], ["DNS server", r.dns_server], ["Query time", `${n(r.duration_ms)} ms`]]} />
          <ul className="space-y-1">{(r.values || []).map((v: string) => <li key={v} className="mono rounded bg-slate-100 px-2 py-1 dark:bg-slate-800">{v}</li>)}</ul>
        </div>
      );
    case "http":
    case "https":
      return (
        <div className="space-y-3">
          <KV items={[["HTTP status", r.status_code ? `${r.status_code} ${r.reason}` : "-"], ["Response", `${n(r.duration_ms)} ms`], ["TLS", r.tls_version ?? (r.tls_ok === false ? "failed" : "-")], ["Final URL", r.final_url || "-"]]} />
          {r.redirects?.length > 0 && <ol className="list-decimal space-y-1 pl-5 text-xs">{r.redirects.map((x: any, i: number) => <li key={i}><span className="mono">{x.status}</span> {x.url} → {x.location}</li>)}</ol>}
          {r.error && <p className="text-sm text-rose-600">{r.error}</p>}
        </div>
      );
    case "ssl":
      return (
        <KV items={[["Subject", r.subject], ["Issuer", r.issuer], ["Valid from", (r.valid_from || "").slice(0, 10)], ["Valid until", (r.valid_until || "").slice(0, 10)],
          ["Days remaining", r.days_remaining], ["Hostname match", r.hostname_match === undefined ? "-" : r.hostname_match ? "Yes" : "No"],
          ["Trusted chain", r.chain_valid === undefined ? "-" : r.chain_valid ? "Yes" : "No"], ["TLS", r.tls_version]]} />
      );
    default:
      return null;
  }
}

export function ResultView({ job, compact }: { job: Job; compact?: boolean }) {
  const [raw, setRaw] = useState(false);
  return (
    <article className="card overflow-hidden">
      <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <div className="flex min-w-0 items-center gap-2">
          <Network className="size-4 shrink-0 text-slate-400" aria-hidden />
          <span className="text-sm font-semibold uppercase">{job.type}</span>
          <span className="mono truncate">{job.target}{job.port ? `:${job.port}` : ""}</span>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {job.simulated && <Badge tone="blue">Simulated</Badge>}
          <Badge>{job.outcome || job.status}</Badge>
        </div>
      </header>
      <div className="space-y-4 p-4">
        {job.error && job.status === "failed" && <p className="text-sm text-rose-600">{job.error}</p>}
        <Body job={job} />
        {!compact && <AssessmentView a={job.assessment} />}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          <span className="mono">{job.reference}</span>
          <span className="inline-flex items-center gap-1"><Clock className="size-3" aria-hidden />{fmtTime(job.started_at)}</span>
          <span>from {job.source_network}</span>
          <span>via {job.source_interface}</span>
        </div>
        {job.raw_output && (
          <div>
            <button className="btn-ghost -ml-2 px-2 py-1 text-xs" onClick={() => setRaw(!raw)} aria-expanded={raw}>
              <Terminal className="size-3.5" aria-hidden />Raw output<ChevronDown className={`size-3.5 transition-transform ${raw ? "rotate-180" : ""}`} aria-hidden />
            </button>
            {raw && <pre className="mono mt-2 max-h-72 overflow-auto rounded-lg bg-slate-950 p-3 text-slate-100">{job.raw_output}</pre>}
          </div>
        )}
      </div>
    </article>
  );
}
