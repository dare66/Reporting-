// Thin fetch wrapper: session cookie + CSRF header, structured errors.
export class ApiError extends Error {
  status: number;
  type?: string;
  constructor(status: number, message: string, type?: string) {
    super(message);
    this.status = status;
    this.type = type;
  }
}

let csrfToken: string | null = null;
export const setCsrf = (t: string | null) => { csrfToken = t; };

export function clientInterface(): "web" | "mobile" {
  return typeof window !== "undefined" && window.matchMedia?.("(max-width: 768px)").matches ? "mobile" : "web";
}

function readCookie(name: string): string | null {
  const m = document.cookie.match(new RegExp("(?:^|; )" + name + "=([^;]*)"));
  return m ? decodeURIComponent(m[1]) : null;
}

export async function api<T = any>(path: string, opts: { method?: string; body?: unknown; form?: FormData } = {}): Promise<T> {
  const method = opts.method ?? (opts.body || opts.form ? "POST" : "GET");
  const headers: Record<string, string> = { "X-Client-Interface": clientInterface() };
  if (method !== "GET") headers["X-CSRF-Token"] = csrfToken ?? readCookie("netops_csrf") ?? "";
  if (opts.body !== undefined) headers["Content-Type"] = "application/json";
  let res: Response;
  try {
    res = await fetch(path, { method, headers, credentials: "same-origin",
      body: opts.form ?? (opts.body !== undefined ? JSON.stringify(opts.body) : undefined) });
  } catch {
    throw new ApiError(0, "Cannot reach the NetOps laptop. Check that it is running and that you are on the same Wi-Fi or VPN.");
  }
  if (res.status === 401 && !path.startsWith("/api/auth/login")) window.dispatchEvent(new Event("netops:unauthorized"));
  const type = res.headers.get("content-type") ?? "";
  const data = type.includes("json") ? await res.json() : null;
  if (!res.ok) throw new ApiError(res.status, data?.detail ?? res.statusText, data?.error_type);
  return data as T;
}

export const download = (url: string) => { window.location.href = url; };

export function fmtTime(iso?: string | null): string {
  if (!iso) return "-";
  const d = new Date(iso);
  return d.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

export function wsUrl(path: string): string {
  const proto = location.protocol === "https:" ? "wss:" : "ws:";
  return `${proto}//${location.host}${path}${path.includes("?") ? "&" : "?"}interface=${clientInterface()}`;
}
