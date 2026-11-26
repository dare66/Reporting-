import { api, ApiError, setCsrf } from "./api";

describe("api client", () => {
  afterEach(() => vi.restoreAllMocks());

  it("sends CSRF and interface headers on writes", async () => {
    setCsrf("tok123");
    const f = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ ok: true }), { headers: { "content-type": "application/json" } }));
    await api("/api/diagnostics/ping", { body: { target: "10.1.57.10" } });
    const init = f.mock.calls[0][1] as RequestInit;
    expect(init.method).toBe("POST");
    expect((init.headers as Record<string, string>)["X-CSRF-Token"]).toBe("tok123");
    expect((init.headers as Record<string, string>)["X-Client-Interface"]).toMatch(/web|mobile/);
  });

  it("raises ApiError with the server detail", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ detail: "Port 23 is not approved", error_type: "PolicyError" }), { status: 403, headers: { "content-type": "application/json" } }));
    await expect(api("/api/x", { body: {} })).rejects.toMatchObject({ status: 403, type: "PolicyError", message: "Port 23 is not approved" });
  });

  it("reports an unreachable laptop clearly", async () => {
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new TypeError("Failed to fetch"));
    const err = await api("/api/health").catch(e => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.message).toMatch(/Cannot reach the NetOps laptop/);
  });

  it("signals expired sessions", async () => {
    const seen = vi.fn();
    window.addEventListener("netops:unauthorized", seen);
    vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ detail: "Not signed in or session expired." }), { status: 401, headers: { "content-type": "application/json" } }));
    await api("/api/dashboard").catch(() => null);
    expect(seen).toHaveBeenCalled();
  });
});
