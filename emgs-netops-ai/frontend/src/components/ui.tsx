import { useEffect, useRef, type ReactNode } from "react";
import { AlertTriangle, CheckCircle2, Info, Loader2, XCircle } from "lucide-react";

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

export function Card({ title, icon, children, className = "", actions }: { title?: string; icon?: ReactNode; children: ReactNode; className?: string; actions?: ReactNode }) {
  return (
    <section className={`card p-4 ${className}`}>
      {title && (
        <header className="mb-3 flex items-center justify-between gap-2">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{icon}{title}</h2>
          {actions}
        </header>
      )}
      {children}
    </section>
  );
}

const TONES: Record<string, string> = {
  green: "bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300",
  amber: "bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300",
  red: "bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300",
  blue: "bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300",
  slate: "bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300",
  violet: "bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300",
};

export function toneFor(status: string): keyof typeof TONES {
  const s = (status || "").toLowerCase();
  if (/(^successful|^open$|all open|^valid|resolved|reached|completed|http 2|http 3|registered and active|^ok$|^success)/.test(s)) return "green";
  if (/(partial|some open|expiring|did not respond|no icmp|filtered|mismatch|paused|newly|unknown|no records|cancel|http 4|planned|warning)/.test(s)) return "amber";
  if (/(fail|error|closed|expired|none open|unreachable|untrusted|dns resolution|rejected|conflict|not responding|unauthorised|nxdomain|http 5|tls)/.test(s)) return "red";
  if (/(running|simulat)/.test(s)) return "blue";
  return "slate";
}

export function Badge({ children, tone }: { children: ReactNode; tone?: keyof typeof TONES }) {
  const t = tone ?? toneFor(String(children));
  return <span className={`inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${TONES[t]}`}>{children}</span>;
}

export function Spinner({ label }: { label?: string }) {
  return <span className="inline-flex items-center gap-2 text-sm text-slate-500"><Loader2 className="size-4 animate-spin" aria-hidden />{label}</span>;
}

export function Alert({ kind = "info", children }: { kind?: "info" | "warning" | "error" | "success"; children: ReactNode }) {
  const map = {
    info: ["border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200", Info],
    warning: ["border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200", AlertTriangle],
    error: ["border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200", XCircle],
    success: ["border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200", CheckCircle2],
  } as const;
  const [cls, Icon] = map[kind];
  return <div role={kind === "error" ? "alert" : "status"} className={`flex gap-2 rounded-lg border px-3 py-2 text-sm ${cls}`}><Icon className="mt-0.5 size-4 shrink-0" aria-hidden /><div className="min-w-0">{children}</div></div>;
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{children}</p>;
}

export function Stat({ label, value, icon, tone = "slate", hint }: { label: string; value: ReactNode; icon: ReactNode; tone?: string; hint?: string }) {
  const toneCls: Record<string, string> = { slate: "text-slate-500", green: "text-emerald-600", amber: "text-amber-600", red: "text-rose-600", blue: "text-sky-600" };
  return (
    <div className="card flex items-start justify-between p-4">
      <div>
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
        <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
        {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
      </div>
      <span className={toneCls[tone] ?? toneCls.slate} aria-hidden>{icon}</span>
    </div>
  );
}

/** Native <dialog> confirmation: focus trapping, Escape and backdrop come from the browser. */
export function ConfirmDialog({ open, title, children, confirmLabel = "Confirm", danger, busy, onConfirm, onClose }: {
  open: boolean; title: string; children: ReactNode; confirmLabel?: string; danger?: boolean; busy?: boolean;
  onConfirm: () => void; onClose: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (open && !d.open) d.showModal?.();
    if (!open && d.open) d.close?.();
  }, [open]);
  return (
    <dialog ref={ref} onClose={onClose} aria-labelledby="dlg-title"
      className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-xl dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
      <div className="p-5">
        <h2 id="dlg-title" className="text-base font-semibold">{title}</h2>
        <div className="mt-3 text-sm text-slate-600 dark:text-slate-300">{children}</div>
      </div>
      <div className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-950/50">
        <button className="btn-secondary" onClick={onClose} disabled={busy}>Cancel</button>
        <button className={danger ? "btn-danger" : "btn-primary"} onClick={onConfirm} disabled={busy} autoFocus>
          {busy && <Loader2 className="size-4 animate-spin" aria-hidden />}{confirmLabel}
        </button>
      </div>
    </dialog>
  );
}

export function Field({ label, children, hint, htmlFor }: { label: string; children: ReactNode; hint?: string; htmlFor?: string }) {
  return (
    <div>
      <label className="label" htmlFor={htmlFor}>{label}</label>
      {children}
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </div>
  );
}

export function Table({ children }: { children: ReactNode }) {
  return <div className="-mx-4 overflow-x-auto sm:mx-0"><table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800">{children}</table></div>;
}
