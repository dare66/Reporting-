import { useEffect, useRef, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { Bot, Download, Radar, Send, User as UserIcon } from "lucide-react";
import { api } from "../services/api";
import { useAuth } from "../hooks/useAuth";
import type { Job } from "../services/types";
import { Alert, Badge, PageHeader, Table } from "../components/ui";
import { AssessmentView } from "../components/AssessmentView";
import { ResultView } from "../components/ResultView";

interface Msg { role: "user" | "assistant"; text: string; data?: any }

const SUGGESTIONS = ["Ping 10.1.57.10", "Check whether port 3306 is accessible on 10.1.57.15", "Run tracert to 10.1.57.10",
  "Check DNS for emgs.com.my", "Check SSL expiry for visa.emgs.com.my", "Test connectivity to Visa Website",
  "Discover all active servers in 10.1.57.xx", "Explain why STARS is not accessible", "Compare 10.1.57.10 and 10.1.57.15",
  "Generate an infrastructure connectivity report"];

export function Assistant() {
  const { ai } = useAuth();
  const [msgs, setMsgs] = useState<Msg[]>([{ role: "assistant", text: "Ask me to run a diagnostic in plain English. I only test approved targets, and I'll ask if something essential is missing." }]);
  const [input, setInput] = useState("");
  const [pending, setPending] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const endRef = useRef<HTMLDivElement>(null);
  const nav = useNavigate();

  useEffect(() => { endRef.current?.scrollIntoView({ block: "end" }); }, [msgs]);

  const send = async (text: string) => {
    if (!text.trim() || busy) return;
    setMsgs(m => [...m, { role: "user", text }]);
    setInput(""); setBusy(true);
    try {
      const r = await api("/api/assistant/message", { body: { message: text, pending } });
      setPending(r.pending);
      setMsgs(m => [...m, { role: "assistant", text: r.reply, data: r }]);
    } catch (e: any) {
      setMsgs(m => [...m, { role: "assistant", text: e.message, data: { error: e.message } }]);
    } finally { setBusy(false); }
  };

  const confirmScan = async (ref: string) => {
    try { await api(`/api/discovery/${ref}/start`, { body: { confirm: true } }); nav(`/discovery/${ref}`); }
    catch (e: any) { setMsgs(m => [...m, { role: "assistant", text: e.message, data: { error: e.message } }]); }
  };

  const submit = (e: FormEvent) => { e.preventDefault(); send(input); };

  return (
    <div className="flex h-[calc(100dvh-11rem)] flex-col lg:h-[calc(100dvh-6rem)]">
      <PageHeader title="Network assistant" subtitle={ai?.active ? `Claude (${ai.model}) interprets requests; checks still run locally under policy.` : "Built-in language rules (Claude API not configured). All diagnostics work offline."} />
      <div className="flex-1 space-y-4 overflow-y-auto pr-1" aria-live="polite">
        {msgs.map((m, i) => (
          <div key={i} className={`flex gap-3 ${m.role === "user" ? "justify-end" : ""}`}>
            {m.role === "assistant" && <span className="mt-1 hidden size-7 shrink-0 items-center justify-center rounded-full bg-navy-800 text-sky-300 sm:flex"><Bot className="size-4" aria-hidden /></span>}
            <div className={`min-w-0 ${m.role === "user" ? "max-w-[85%] rounded-2xl rounded-br-sm bg-sky-600 px-3.5 py-2 text-sm text-white" : "w-full max-w-4xl space-y-3"}`}>
              {m.role === "user" ? m.text : <AssistantReply m={m} onConfirm={confirmScan} />}
            </div>
            {m.role === "user" && <span className="mt-1 hidden size-7 shrink-0 items-center justify-center rounded-full bg-slate-200 sm:flex dark:bg-slate-800"><UserIcon className="size-4" aria-hidden /></span>}
          </div>
        ))}
        {busy && <p className="pl-10 text-sm text-slate-500">Working…</p>}
        <div ref={endRef} />
      </div>
      {msgs.length <= 1 && (
        <div className="my-3 flex gap-2 overflow-x-auto pb-1">
          {SUGGESTIONS.map(s => <button key={s} onClick={() => send(s)} className="shrink-0 rounded-full border border-slate-300 px-3 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{s}</button>)}
        </div>
      )}
      <form onSubmit={submit} className="mt-2 flex gap-2">
        <label htmlFor="chat" className="sr-only">Message</label>
        <input id="chat" className="input" value={input} onChange={e => setInput(e.target.value)} placeholder={pending ? "Answer the question above…" : "e.g. Check whether port 443 is open on 10.1.57.20"} autoComplete="off" maxLength={2000} />
        <button className="btn-primary" disabled={busy || !input.trim()} aria-label="Send"><Send className="size-4" /></button>
      </form>
    </div>
  );
}

function AssistantReply({ m, onConfirm }: { m: Msg; onConfirm: (ref: string) => void }) {
  const d = m.data;
  return (
    <>
      {d?.error ? <Alert kind="error">{m.text}</Alert> :
        <div className="card whitespace-pre-line px-3.5 py-2.5 text-sm">{m.text}
          {d && <div className="mt-1.5 flex flex-wrap gap-1.5">{d.simulated && <Badge tone="blue">Simulated</Badge>}{d.intent && d.intent !== "help" && <Badge tone="slate">{`intent: ${d.intent}`}</Badge>}{d.source === "claude" && <Badge tone="violet">Claude</Badge>}</div>}
        </div>}
      {d?.plan && (
        <div className="card space-y-2 p-3 text-sm">
          <p className="flex items-center gap-2 font-medium"><Radar className="size-4 text-sky-500" aria-hidden />Discovery plan {d.plan.reference}</p>
          <ul className="list-disc space-y-0.5 pl-5 text-slate-600 dark:text-slate-300">
            <li><strong>{d.plan.address_count}</strong> addresses ({d.plan.first} – {d.plan.last}) inside {d.plan.approved_network}</li>
            <li>Methods: {d.plan.methods.map((x: any) => x.id).join(", ")}</li>
            <li>Approved ports: {d.plan.ports.join(", ")}</li>
          </ul>
          <button className="btn-primary" onClick={() => onConfirm(d.plan.reference)}>Confirm and start discovery</button>
        </div>
      )}
      {d?.comparison && (
        <div className="card p-3"><Table>
          <thead><tr><th className="th">Target</th><th className="th">Ping</th><th className="th">Avg ms</th><th className="th">Open</th><th className="th">Not open</th></tr></thead>
          <tbody>{d.comparison.map((c: any) => <tr key={c.target}><td className="td mono">{c.target}</td><td className="td"><Badge>{c.ping}</Badge></td><td className="td">{c.avg_ms ?? "-"}</td><td className="td">{c.open_ports.join(", ") || "-"}</td><td className="td">{c.not_open.join(", ") || "-"}</td></tr>)}</tbody>
        </Table></div>
      )}
      {d?.report && <a className="btn-secondary" href={d.report.download_url}><Download className="size-4" />Download {d.report.reference} ({d.report.format.toUpperCase()})</a>}
      {d?.results?.map((j: Job) => <ResultView key={j.reference} job={j} compact />)}
      {d?.explanation && d.results?.length > 0 && <AssessmentView a={d.explanation} />}
      {d?.servers && <div className="card p-3 text-sm">{d.servers.map((s: any) => <div key={s.id} className="flex justify-between border-b border-slate-100 py-1 last:border-0 dark:border-slate-800"><span>{s.name}</span><span className="mono">{s.ip_address}</span></div>)}</div>}
    </>
  );
}
