import { useState, type FormEvent } from "react";
import { KeyRound, ShieldCheck } from "lucide-react";
import { useAuth } from "../hooks/useAuth";
import { Alert, Field } from "../components/ui";

export function Login() {
  const { login, setupRequired, setup, appName } = useAuth();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [fullName, setFullName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    if (setupRequired && password !== confirm) { setError("Passwords do not match."); return; }
    setBusy(true);
    try { setupRequired ? await setup(username, password, fullName) : await login(username, password); }
    catch (err: any) { setError(err.message); }
    finally { setBusy(false); }
  };

  return (
    <div className="flex min-h-full items-center justify-center bg-gradient-to-br from-navy-900 to-slate-900 px-4 py-10">
      <form onSubmit={submit} className="card w-full max-w-sm space-y-4 p-6" aria-labelledby="login-title">
        <div className="flex items-center gap-3">
          <span className="rounded-lg bg-navy-800 p-2"><ShieldCheck className="size-6 text-sky-400" aria-hidden /></span>
          <div>
            <h1 id="login-title" className="text-lg font-semibold">{setupRequired ? "First-time setup" : appName}</h1>
            <p className="text-xs text-slate-500">{setupRequired ? "Create the local administrator account" : "Sign in to the local diagnostics console"}</p>
          </div>
        </div>
        {setupRequired && <Alert kind="info">This laptop has no users yet. The account you create here is the local administrator. Setup only works on the laptop itself.</Alert>}
        {error && <Alert kind="error">{error}</Alert>}
        {setupRequired && <Field label="Full name" htmlFor="fn"><input id="fn" className="input" value={fullName} onChange={e => setFullName(e.target.value)} autoComplete="name" /></Field>}
        <Field label="Username" htmlFor="un"><input id="un" className="input" value={username} onChange={e => setUsername(e.target.value)} autoComplete="username" required autoFocus /></Field>
        <Field label="Password" htmlFor="pw" hint={setupRequired ? "At least 12 characters with three of: lowercase, uppercase, digit, symbol." : undefined}>
          <input id="pw" type="password" className="input" value={password} onChange={e => setPassword(e.target.value)} autoComplete={setupRequired ? "new-password" : "current-password"} required />
        </Field>
        {setupRequired && <Field label="Confirm password" htmlFor="pw2"><input id="pw2" type="password" className="input" value={confirm} onChange={e => setConfirm(e.target.value)} autoComplete="new-password" required /></Field>}
        <button className="btn-primary w-full" disabled={busy}><KeyRound className="size-4" aria-hidden />{setupRequired ? "Create administrator" : "Sign in"}</button>
      </form>
    </div>
  );
}
