import { useState, type FormEvent } from "react";
import { Play } from "lucide-react";
import { api } from "../services/api";
import type { Job } from "../services/types";
import { Alert, Field } from "../components/ui";
import { useAuth } from "../hooks/useAuth";

export const DIAG_TYPES = [
  { id: "ping", label: "Ping" },
  { id: "traceroute", label: "Traceroute" },
  { id: "port", label: "TCP port" },
  { id: "dns", label: "DNS" },
  { id: "https", label: "HTTPS" },
  { id: "http", label: "HTTP" },
  { id: "ssl", label: "SSL certificate" },
] as const;

export function QuickDiagnostic({ onResult, initialType = "ping", compact }: { onResult: (j: Job) => void; initialType?: string; compact?: boolean }) {
  const { mode, can } = useAuth();
  const [type, setType] = useState(initialType);
  const [target, setTarget] = useState("");
  const [ports, setPorts] = useState("443");
  const [count, setCount] = useState(4);
  const [record, setRecord] = useState("A");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const disabled = mode === "review" || !can("operator");

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    const body: Record<string, any> = {};
    if (type === "http" || type === "https") body.url = target;
    else if (type === "dns") { body.domain = target; body.record_type = record; }
    else body.target = target;
    if (type === "ping") body.count = count;
    if (type === "port") body.ports = ports === "approved" ? "approved" : ports.split(/[\s,]+/).filter(Boolean).map(Number);
    try { onResult(await api<Job>(`/api/diagnostics/${type}`, { body })); }
    catch (err: any) { setError(err.message); }
    finally { setBusy(false); }
  };

  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Quick diagnostic">
      <div className="flex flex-wrap gap-1" role="radiogroup" aria-label="Diagnostic type">
        {DIAG_TYPES.map(d => (
          <button type="button" key={d.id} role="radio" aria-checked={type === d.id} onClick={() => setType(d.id)}
            className={`rounded-md px-2.5 py-1 text-xs font-medium ${type === d.id ? "bg-sky-600 text-white" : "bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"}`}>{d.label}</button>
        ))}
      </div>
      <div className={`grid gap-3 ${compact ? "" : "sm:grid-cols-[1fr_auto_auto]"} items-end`}>
        <Field label={type === "http" || type === "https" ? "URL or hostname" : type === "dns" ? "Domain or IP" : "IP address or hostname"} htmlFor="qd-target">
          <input id="qd-target" className="input" value={target} onChange={e => setTarget(e.target.value)} required
            placeholder={type === "https" ? "https://visa.emgs.com.my" : type === "dns" ? "emgs.com.my" : "10.1.57.10"} inputMode="url" autoCapitalize="off" spellCheck={false} />
        </Field>
        {type === "port" && <Field label="Ports" htmlFor="qd-ports" hint="Comma-separated, or 'approved'"><input id="qd-ports" className="input sm:w-40" value={ports} onChange={e => setPorts(e.target.value)} /></Field>}
        {type === "ping" && <Field label="Packets" htmlFor="qd-count"><input id="qd-count" type="number" min={1} max={20} className="input sm:w-24" value={count} onChange={e => setCount(Number(e.target.value))} /></Field>}
        {type === "dns" && <Field label="Record" htmlFor="qd-rec"><select id="qd-rec" className="input sm:w-28" value={record} onChange={e => setRecord(e.target.value)}>{["A", "AAAA", "CNAME", "MX", "NS", "TXT", "PTR"].map(r => <option key={r}>{r}</option>)}</select></Field>}
        <button className="btn-primary" disabled={busy || disabled} data-testid="run-diagnostic"><Play className="size-4" aria-hidden />{busy ? "Running…" : "Run"}</button>
      </div>
      {disabled && <p className="text-xs text-amber-600">{mode === "review" ? "Review mode is read-only." : "Your role cannot run diagnostics."}</p>}
      {error && <Alert kind="error">{error}</Alert>}
    </form>
  );
}
