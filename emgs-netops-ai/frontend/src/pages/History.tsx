import { useState } from "react";
import { api, fmtTime } from "../services/api";
import { useApi } from "../hooks/useApi";
import type { Job } from "../services/types";
import { Alert, Badge, Card, Empty, PageHeader, Spinner, Table } from "../components/ui";
import { ResultView } from "../components/ResultView";

export function HistoryPage() {
  const [type, setType] = useState("");
  const [target, setTarget] = useState("");
  const { data, loading, error } = useApi<Job[]>(`/api/history?type=${type}&target=${encodeURIComponent(target)}&limit=200`);
  const [open, setOpen] = useState<Job | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [msg, setMsg] = useState<string | null>(null);
  const reportType = ({ ping: "ping", traceroute: "traceroute", port: "ports", dns: "dns", ssl: "ssl" } as Record<string, string>)[type];

  const report = async (format: string) => {
    try {
      const r = await api("/api/reports", { body: { report_type: reportType, format, references: selected.length ? selected : undefined, target } });
      window.location.href = r.download_url;
    } catch (e: any) { setMsg(e.message); }
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Diagnostic history" subtitle="Every execution from the dashboard, assistant, mobile, MCP and CLI is recorded here."
        actions={reportType ? <><button className="btn-secondary" onClick={() => report("pdf")}>PDF report</button><button className="btn-secondary" onClick={() => report("xlsx")}>Excel report</button></> : undefined} />
      {msg && <Alert kind="error">{msg}</Alert>}
      <Card>
        <div className="mb-3 grid gap-2 sm:grid-cols-[auto_1fr]">
          <select aria-label="Type" className="input" value={type} onChange={e => { setType(e.target.value); setSelected([]); }}>
            <option value="">All types</option>{["ping", "traceroute", "port", "dns", "http", "https", "ssl"].map(t => <option key={t}>{t}</option>)}</select>
          <input aria-label="Target filter" className="input" placeholder="Filter by target" value={target} onChange={e => setTarget(e.target.value)} />
        </div>
        {reportType && <p className="mb-2 text-xs text-slate-500">Select rows to limit the report to those executions; otherwise it covers the last 30 days.</p>}
        {loading && !data ? <Spinner /> : error ? <Alert kind="error">{error}</Alert> : !data?.length ? <Empty>No diagnostics recorded.</Empty> : (
          <Table>
            <thead><tr>{reportType && <th className="th"><span className="sr-only">Select</span></th>}<th className="th">Executed</th><th className="th">Type</th><th className="th">Target</th><th className="th">Outcome</th><th className="th hidden md:table-cell">User</th><th className="th hidden md:table-cell">Interface</th><th className="th hidden lg:table-cell">Reference</th></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{data.map(j => (
              <tr key={j.reference} className="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50" onClick={() => setOpen(j)}>
                {reportType && <td className="td" onClick={e => e.stopPropagation()}><input type="checkbox" aria-label={`Select ${j.reference}`} className="size-4 accent-sky-600" checked={selected.includes(j.reference)} onChange={() => setSelected(s => s.includes(j.reference) ? s.filter(x => x !== j.reference) : [...s, j.reference])} /></td>}
                <td className="td whitespace-nowrap">{fmtTime(j.started_at)}</td><td className="td uppercase text-xs">{j.type}</td>
                <td className="td mono">{j.target}{j.port ? `:${j.port}` : ""}</td>
                <td className="td"><Badge>{j.outcome || j.status}</Badge>{j.simulated && <span className="ml-1"><Badge tone="blue">sim</Badge></span>}</td>
                <td className="td hidden md:table-cell">{j.user}</td><td className="td hidden md:table-cell">{j.source_interface}</td>
                <td className="td mono hidden lg:table-cell">{j.reference}</td>
              </tr>))}</tbody>
          </Table>)}
      </Card>
      {open && <div><div className="mb-2 flex justify-end"><button className="btn-ghost text-xs" onClick={() => setOpen(null)}>Close detail</button></div><ResultView job={open} /></div>}
    </div>
  );
}
