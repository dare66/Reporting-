import { useEffect, useRef, useState } from "react";
import { Activity, Radio } from "lucide-react";
import { wsUrl } from "../services/api";
import type { Job } from "../services/types";
import { Card, PageHeader } from "../components/ui";
import { QuickDiagnostic } from "../features/QuickDiagnostic";
import { ResultView } from "../components/ResultView";

export function Diagnostics() {
  const [results, setResults] = useState<Job[]>([]);
  const [live, setLive] = useState(false);
  const wsRef = useRef<WebSocket | null>(null);

  // A WebSocket keeps a live channel open so the console shows when the laptop is reachable.
  useEffect(() => {
    let ws: WebSocket | null = null;
    try {
      ws = new WebSocket(wsUrl("/ws/console"));
      ws.onopen = () => setLive(true);
      ws.onclose = () => setLive(false);
      wsRef.current = ws;
    } catch { setLive(false); }
    return () => ws?.close();
  }, []);

  return (
    <div className="space-y-5">
      <PageHeader title="Diagnostic console" subtitle="Ping, traceroute, TCP port, DNS, HTTP(S) and SSL checks run from this laptop against approved targets only."
        actions={<span className="inline-flex items-center gap-1.5 text-xs text-slate-500"><Radio className={`size-3.5 ${live ? "text-emerald-500" : "text-slate-400"}`} aria-hidden />{live ? "Connected to laptop" : "Connecting…"}</span>} />
      <Card title="Run a diagnostic" icon={<Activity className="size-4 text-sky-500" />}>
        <QuickDiagnostic onResult={j => setResults(r => [j, ...r].slice(0, 20))} />
      </Card>
      {results.length === 0 ? <p className="text-sm text-slate-500">Results appear here and are saved to History.</p> :
        <div className="space-y-4" aria-live="polite">{results.map(j => <ResultView key={j.reference} job={j} />)}</div>}
    </div>
  );
}
