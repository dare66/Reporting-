import { useState, type ReactNode } from "react";
import { NavLink, useLocation } from "react-router-dom";
import { Activity, Bot, ClipboardList, FileText, History, LayoutDashboard, LogOut, Menu, Monitor, Moon,
  Radar, Server, Settings, ShieldCheck, Sun, X } from "lucide-react";
import { useAuth } from "../hooks/useAuth";
import { useTheme } from "../hooks/useTheme";

const NAV = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard, mobile: true },
  { to: "/assistant", label: "Assistant", icon: Bot, mobile: true },
  { to: "/diagnostics", label: "Diagnostics", icon: Activity, mobile: true },
  { to: "/discovery", label: "Discovery", icon: Radar, mobile: true },
  { to: "/inventory", label: "Inventory", icon: Server },
  { to: "/history", label: "History", icon: History },
  { to: "/reports", label: "Reports", icon: FileText },
  { to: "/audit", label: "Audit log", icon: ClipboardList, admin: true },
  { to: "/settings", label: "Settings", icon: Settings },
];

const MODE_INFO = {
  simulation: { label: "Simulation mode", text: "Results come from the built-in lab model. Nothing is sent on the network.", cls: "bg-sky-600 text-white" },
  live: { label: "Live mode", text: "Diagnostics run from this laptop's current network connection.", cls: "bg-emerald-700 text-white" },
  review: { label: "Review mode", text: "Read-only. Diagnostics are disabled; history and inventory are available.", cls: "bg-amber-500 text-slate-950" },
};

export function AppLayout({ children }: { children: ReactNode }) {
  const { user, mode, logout, appName, can } = useAuth();
  const { theme, cycle } = useTheme();
  const [more, setMore] = useState(false);
  const loc = useLocation();
  const items = NAV.filter(n => !n.admin || can("admin"));
  const ThemeIcon = theme === "dark" ? Moon : theme === "light" ? Sun : Monitor;
  const mi = MODE_INFO[mode];

  const link = (n: (typeof NAV)[number], onClick?: () => void) => (
    <NavLink key={n.to} to={n.to} end={n.to === "/"} onClick={onClick}
      className={({ isActive }) => `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium ${isActive
        ? "bg-white/10 text-white" : "text-slate-300 hover:bg-white/5 hover:text-white"}`}>
      <n.icon className="size-4" aria-hidden />{n.label}
    </NavLink>
  );

  return (
    <div className="flex min-h-full">
      <aside className="fixed inset-y-0 left-0 hidden w-60 flex-col bg-navy-900 px-3 py-4 lg:flex">
        <div className="mb-6 flex items-center gap-2 px-2">
          <ShieldCheck className="size-6 text-sky-400" aria-hidden />
          <div><p className="text-sm font-semibold text-white">{appName}</p><p className="text-[11px] text-slate-400">Local network diagnostics</p></div>
        </div>
        <nav className="flex-1 space-y-1" aria-label="Main">{items.map(n => link(n))}</nav>
        <div className="border-t border-white/10 pt-3 text-xs text-slate-400">
          <p className="truncate px-2">{user?.full_name || user?.username} · {user?.roles.join(", ")}</p>
          <div className="mt-2 flex gap-1">
            <button className="btn-ghost px-2.5 text-slate-300 hover:bg-white/5" onClick={cycle} aria-label={`Theme: ${theme}`} title={`Theme: ${theme}`}><ThemeIcon className="size-4" /></button>
            <button className="btn-ghost flex-1 whitespace-nowrap text-slate-300 hover:bg-white/5" onClick={logout}><LogOut className="size-4" />Sign out</button>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col lg:pl-60">
        <div className={`${mi.cls} px-4 py-1.5 text-center text-xs font-medium`} role="status" data-testid="mode-banner">
          <strong>{mi.label}.</strong> <span className="hidden sm:inline">{mi.text}</span>
        </div>
        <header className="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/90 px-4 py-2.5 backdrop-blur lg:hidden dark:border-slate-800 dark:bg-slate-950/90">
          <div className="flex items-center gap-2"><ShieldCheck className="size-5 text-sky-600" aria-hidden /><span className="text-sm font-semibold">NetOps AI</span></div>
          <div className="flex items-center gap-1">
            <button className="btn-ghost px-2" onClick={cycle} aria-label={`Theme: ${theme}`}><ThemeIcon className="size-4" /></button>
            <button className="btn-ghost px-2" onClick={() => setMore(true)} aria-label="Open menu"><Menu className="size-5" /></button>
          </div>
        </header>
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 pb-24 pt-5 sm:px-6 lg:pb-8" key={loc.pathname}>{children}</main>
      </div>

      <nav className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden dark:border-slate-800 dark:bg-slate-950" aria-label="Mobile">
        {items.filter(n => n.mobile).map(n => (
          <NavLink key={n.to} to={n.to} end={n.to === "/"} className={({ isActive }) => `flex flex-col items-center gap-0.5 py-2 text-[11px] ${isActive ? "text-sky-600 dark:text-sky-400" : "text-slate-500"}`}>
            <n.icon className="size-5" aria-hidden />{n.label}
          </NavLink>
        ))}
        <button onClick={() => setMore(true)} className="flex flex-col items-center gap-0.5 py-2 text-[11px] text-slate-500"><Menu className="size-5" aria-hidden />More</button>
      </nav>

      {more && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
          <button className="absolute inset-0 bg-slate-950/60" onClick={() => setMore(false)} aria-label="Close menu" />
          <div className="absolute inset-x-0 bottom-0 rounded-t-2xl bg-navy-900 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
            <div className="mb-3 flex items-center justify-between">
              <p className="text-sm text-slate-300">{user?.username} · {user?.roles.join(", ")}</p>
              <button className="btn-ghost px-2 text-slate-300" onClick={() => setMore(false)} aria-label="Close"><X className="size-5" /></button>
            </div>
            <nav className="grid grid-cols-2 gap-1">{items.map(n => link(n, () => setMore(false)))}</nav>
            <button className="btn-ghost mt-3 w-full text-slate-300 hover:bg-white/5" onClick={logout}><LogOut className="size-4" />Sign out</button>
          </div>
        </div>
      )}
    </div>
  );
}
