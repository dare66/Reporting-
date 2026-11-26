import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { api, setCsrf } from "../services/api";
import type { Mode, User } from "../services/types";

interface AuthState {
  loading: boolean; setupRequired: boolean; user: User | null; mode: Mode; appName: string;
  ai: { active: boolean; model: string } | null;
  refresh: () => Promise<void>; login: (u: string, p: string) => Promise<void>; logout: () => Promise<void>;
  setup: (u: string, p: string, n: string) => Promise<void>; can: (role: "admin" | "operator") => boolean;
}

const Ctx = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState({ loading: true, setupRequired: false, user: null as User | null,
    mode: "simulation" as Mode, appName: "EMGS NetOps AI", ai: null as AuthState["ai"] });

  const refresh = useCallback(async () => {
    const h = await api("/api/health").catch(() => null);
    if (!h) { setState(s => ({ ...s, loading: false })); return; }
    if (h.setup_required) { setState(s => ({ ...s, loading: false, setupRequired: true, user: null, mode: h.mode })); return; }
    try {
      const me = await api("/api/auth/me");
      setCsrf(me.csrf_token);
      setState({ loading: false, setupRequired: false, user: me.user, mode: me.mode, appName: h.app, ai: me.ai });
    } catch {
      setState(s => ({ ...s, loading: false, setupRequired: false, user: null, mode: h.mode, appName: h.app }));
    }
  }, []);

  useEffect(() => {
    refresh();
    const onUnauth = () => setState(s => ({ ...s, user: null }));
    window.addEventListener("netops:unauthorized", onUnauth);
    return () => window.removeEventListener("netops:unauthorized", onUnauth);
  }, [refresh]);

  const login = async (username: string, password: string) => {
    const r = await api("/api/auth/login", { body: { username, password } });
    setCsrf(r.csrf_token);
    await refresh();
  };
  const setup = async (username: string, password: string, full_name: string) => {
    const r = await api("/api/auth/setup", { body: { username, password, full_name } });
    setCsrf(r.csrf_token);
    await refresh();
  };
  const logout = async () => { await api("/api/auth/logout", { method: "POST" }).catch(() => null); setCsrf(null); await refresh(); };
  const can = (role: "admin" | "operator") => !!state.user && (state.user.roles.includes("admin") || state.user.roles.includes(role));

  return <Ctx.Provider value={{ ...state, refresh, login, logout, setup, can }}>{children}</Ctx.Provider>;
}

export const useAuth = () => {
  const c = useContext(Ctx);
  if (!c) throw new Error("useAuth outside AuthProvider");
  return c;
};
