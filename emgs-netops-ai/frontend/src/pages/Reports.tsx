import { useState } from "react";
import { Download, FileSpreadsheet, FileText } from "lucide-react";
import { api, fmtTime } from "../services/api";
import { useApi } from "../hooks/useApi";
import { useAuth } from "../hooks/useAuth";
import { Alert, Card, Empty, Field, PageHeader, Spinner, Table } from "../components/ui";

export function Reports() {
  const { can } = useAuth();
  const types = useApi<any[]>("/api/reports/types");
  const list = useApi<any[]>("/api/reports");
  const [type, setType] = useState("consolidated");
  const [days, setDays] = useState(30);
  const [target, setTarget] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ k: "error" | "success"; t: string } | null>(null);
  const sel = types.data?.find(t => t.id === type);

  const gen = async (format: string) => {
    setBusy(true); setMsg(null);
    try {
      const r = await api("/api/reports", { body: { report_type: type, format, days, target } });
      setMsg({ k: "success", t: `${r.title} ${r.reference} generated.` }); list.reload();
      window.location.href = r.download_url;
    } catch (e: any) { setMsg({ k: "error", t: e.message }); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Reports" subtitle="Excel and PDF reports with reference, requester, source network, methodology, raw results, assessment and recommendations." />
      {can("operator") && <Card title="Generate a report">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Report type" htmlFor="rt"><select id="rt" className="input" value={type} onChange={e => setType(e.target.value)}>{types.data?.map(t => <option key={t.id} value={t.id}>{t.title}</option>)}</select></Field>
          <Field label="Period (days)" htmlFor="rd"><input id="rd" type="number" min={1} max={3650} className="input" value={days} onChange={e => setDays(Number(e.target.value))} /></Field>
          <Field label="Target filter (optional)" htmlFor="rtg"><input id="rtg" className="input" value={target} onChange={e => setTarget(e.target.value)} placeholder="10.1.57.10" /></Field>
        </div>
        {sel && <p className="mt-3 text-sm text-slate-500"><strong>Methodology:</strong> {sel.methodology}</p>}
        <div className="mt-3 flex flex-wrap gap-2">
          <button className="btn-primary" disabled={busy} onClick={() => gen("pdf")}><FileText className="size-4" />Generate PDF</button>
          <button className="btn-secondary" disabled={busy} onClick={() => gen("xlsx")}><FileSpreadsheet className="size-4" />Generate Excel</button>
        </div>
        {msg && <div className="mt-3"><Alert kind={msg.k}>{msg.t}</Alert></div>}
      </Card>}
      <Card title="Report history">
        {list.loading && !list.data ? <Spinner /> : !list.data?.length ? <Empty>No reports generated yet.</Empty> : (
          <Table>
            <thead><tr><th className="th">Reference</th><th className="th">Report</th><th className="th hidden sm:table-cell">Created</th><th className="th">File</th></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{list.data.map(r => (
              <tr key={r.reference}><td className="td mono">{r.reference}</td><td className="td">{r.title}</td><td className="td hidden sm:table-cell">{fmtTime(r.created_at)}</td>
                <td className="td whitespace-nowrap"><a className="btn-ghost px-2 py-1 text-xs" href={r.download_url}><Download className="size-3.5" />{r.format.toUpperCase()}</a>
                  {r.format === "pdf" && <a className="btn-ghost px-2 py-1 text-xs" href={`${r.download_url}?inline=true`} target="_blank" rel="noopener">View</a>}</td></tr>))}</tbody>
          </Table>)}
      </Card>
    </div>
  );
}
