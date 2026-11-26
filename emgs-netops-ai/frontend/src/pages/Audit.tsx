import { useState } from "react";
import { Download } from "lucide-react";
import { fmtTime } from "../services/api";
import { useApi } from "../hooks/useApi";
import { Badge, Card, Empty, PageHeader, Spinner, Table } from "../components/ui";

export function AuditPage() {
  const [action, setAction] = useState("");
  const [user, setUser] = useState("");
  const { data, loading } = useApi<any[]>(`/api/audit?action=${encodeURIComponent(action)}&user=${encodeURIComponent(user)}&limit=500`);
  return (
    <div className="space-y-5">
      <PageHeader title="Audit log" subtitle="Who ran what, when, from which interface and network, with the execution reference."
        actions={<a className="btn-secondary" href={`/api/audit/export?action=${encodeURIComponent(action)}&user=${encodeURIComponent(user)}`}><Download className="size-4" />Export CSV</a>} />
      <Card>
        <div className="mb-3 grid gap-2 sm:grid-cols-2">
          <select aria-label="Action" className="input" value={action} onChange={e => setAction(e.target.value)}>
            <option value="">All actions</option>{["diagnostic", "discovery", "auth", "inventory", "settings", "policy", "report", "backup", "user"].map(a => <option key={a}>{a}</option>)}</select>
          <input aria-label="User" className="input" placeholder="Username" value={user} onChange={e => setUser(e.target.value)} />
        </div>
        {loading && !data ? <Spinner /> : !data?.length ? <Empty>No entries.</Empty> : (
          <Table>
            <thead><tr><th className="th">Time</th><th className="th">User</th><th className="th">Action</th><th className="th">Destination</th><th className="th">Status</th><th className="th hidden md:table-cell">Interface</th><th className="th hidden lg:table-cell">Source network</th><th className="th hidden lg:table-cell">Reference</th><th className="th hidden xl:table-cell">Detail</th></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{data.map(a => (
              <tr key={a.id}><td className="td whitespace-nowrap">{fmtTime(a.timestamp)}</td><td className="td">{a.user}</td><td className="td mono">{a.action}</td>
                <td className="td mono">{a.destination}{a.port ? `:${a.port}` : ""}</td><td className="td"><Badge>{a.status}</Badge></td>
                <td className="td hidden md:table-cell">{a.interface}</td><td className="td hidden text-xs lg:table-cell">{a.source_network}</td>
                <td className="td mono hidden lg:table-cell">{a.reference}</td><td className="td hidden max-w-xs truncate text-xs text-slate-500 xl:table-cell" title={a.detail}>{a.detail}</td></tr>))}</tbody>
          </Table>)}
      </Card>
    </div>
  );
}
