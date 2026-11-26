import { CheckCircle2, HelpCircle, ListChecks, Sparkles } from "lucide-react";
import type { Assessment } from "../services/types";

export function AssessmentView({ a }: { a: Assessment }) {
  if (!a || !a.summary) return null;
  const sections: [string, string[] | undefined, JSX.Element, string][] = [
    ["Confirmed", a.confirmed, <CheckCircle2 className="size-4" />, "text-emerald-700 dark:text-emerald-400"],
    ["Possible causes (not confirmed)", a.possible_causes, <HelpCircle className="size-4" />, "text-amber-700 dark:text-amber-400"],
    ["Recommended next steps", a.recommendations, <ListChecks className="size-4" />, "text-sky-700 dark:text-sky-400"],
  ];
  return (
    <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-950/40">
      <p className="flex items-start gap-2 text-sm font-medium">
        <Sparkles className="mt-0.5 size-4 shrink-0 text-violet-500" aria-hidden />
        <span>{a.summary}</span>
      </p>
      <div className="grid gap-3 md:grid-cols-3">
        {sections.map(([title, items, icon, cls]) => items && items.length > 0 && (
          <div key={title}>
            <h4 className={`mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide ${cls}`}>{icon}{title}</h4>
            <ul className="space-y-1 text-sm text-slate-700 dark:text-slate-300">
              {items.map((x, i) => <li key={i} className="flex gap-1.5"><span aria-hidden className="text-slate-400">•</span><span>{x}</span></li>)}
            </ul>
          </div>
        ))}
      </div>
      <p className="text-[11px] text-slate-500">Assessment by {a.source === "claude" ? "Claude (AI)" : "built-in rules"}. Only "Confirmed" items are proven by the measurement.</p>
    </div>
  );
}
