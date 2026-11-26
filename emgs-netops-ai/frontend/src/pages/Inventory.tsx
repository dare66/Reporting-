import { useRef, useState } from "react";
import { Download, Pencil, Plus, Search, Trash2, Upload } from "lucide-react";
import { api, fmtTime } from "../services/api";
import { useApi } from "../hooks/useApi";
import { useAuth } from "../hooks/useAuth";
import type { Server } from "../services/types";
import { Alert, Badge, Card, ConfirmDialog, Empty, Field, PageHeader, Spinner, Table } from "../components/ui";

const EMPTY: Partial<Server> = { name: "", hostname: "", ip_address: "", environment: "Production", operating_system: "", application: "",
  category: "Other", technical_owner: "", business_owner: "", criticality: "Medium", approved_ports: [], description: "", status: "Active" };

export function Inventory() {
  const { can } = useAuth();
  const [q, setQ] = useState("");
  const [category, setCategory] = useState("");
  const [status, setStatus] = useState("");
  const list = useApi<Server[]>(`/api/inventory?q=${encodeURIComponent(q)}&category=${encodeURIComponent(category)}&status=${status}`);
  const meta = useApi<any>("/api/inventory/meta");
  const [edit, setEdit] = useState<Partial<Server> | null>(null);
  const [portsText, setPortsText] = useState("");
  const [del, setDel] = useState<Server | null>(null);
  const [delConfirm, setDelConfirm] = useState("");
  const [msg, setMsg] = useState<{ kind: "success" | "error" | "info"; text: string } | null>(null);
  const [importRes, setImportRes] = useState<any>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const editor = can("operator");

  const openEdit = (s: Partial<Server>) => { setEdit({ ...s }); setPortsText((s.approved_ports ?? []).join(", ")); };
  const save = async () => {
    if (!edit) return;
    const body = { ...edit, approved_ports: portsText.split(/[\s,]+/).filter(Boolean).map(Number) };
    try {
      if (edit.id) await api(`/api/inventory/${edit.id}`, { method: "PUT", body });
      else await api("/api/inventory", { body });
      setEdit(null); setMsg({ kind: "success", text: "Inventory saved." }); list.reload();
    } catch (e: any) { setMsg({ kind: "error", text: e.message }); }
  };
  const remove = async () => {
    if (!del) return;
    try { await api(`/api/inventory/${del.id}?confirm=${encodeURIComponent(delConfirm)}`, { method: "DELETE" }); setDel(null); list.reload(); setMsg({ kind: "success", text: `${del.server_code} deleted.` }); }
    catch (e: any) { setMsg({ kind: "error", text: e.message }); }
  };
  const runImport = async (dry: boolean) => {
    if (!file) return;
    const fd = new FormData(); fd.append("file", file);
    try {
      const r = await api(`/api/inventory/import?dry_run=${dry}`, { form: fd });
      setImportRes(r);
      if (!dry) { list.reload(); setFile(null); if (fileRef.current) fileRef.current.value = ""; }
    } catch (e: any) { setMsg({ kind: "error", text: e.message }); }
  };
  const set = (k: keyof Server, v: any) => setEdit(e => ({ ...e!, [k]: v }));

  return (
    <div className="space-y-5">
      <PageHeader title="Server inventory" subtitle="Locally maintained register of authorised servers and devices."
        actions={<>
          <a className="btn-secondary" href="/api/inventory/export"><Download className="size-4" />Export Excel</a>
          {editor && <button className="btn-primary" onClick={() => openEdit(EMPTY)}><Plus className="size-4" />Add server</button>}
        </>} />
      {msg && <Alert kind={msg.kind}>{msg.text}</Alert>}
      {list.data?.some(s => s.is_sample) && <Alert kind="warning">Records marked <strong>SAMPLE</strong> are demonstration data, not real EMGS assets. They only apply in Simulation mode.</Alert>}
      <Card>
        <div className="mb-3 grid gap-2 sm:grid-cols-[1fr_auto_auto]">
          <div className="relative"><Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
            <label htmlFor="inv-q" className="sr-only">Search</label>
            <input id="inv-q" className="input pl-8" placeholder="Search name, hostname, IP, owner…" value={q} onChange={e => setQ(e.target.value)} /></div>
          <select aria-label="Category" className="input" value={category} onChange={e => setCategory(e.target.value)}><option value="">All categories</option>{meta.data?.categories.map((c: string) => <option key={c}>{c}</option>)}</select>
          <select aria-label="Status" className="input" value={status} onChange={e => setStatus(e.target.value)}><option value="">Any status</option><option>Active</option><option>Inactive</option></select>
        </div>
        {list.loading && !list.data ? <Spinner /> : !list.data?.length ? <Empty>No servers match.</Empty> : (
          <Table>
            <thead><tr><th className="th">Server ID</th><th className="th">Name</th><th className="th">IP</th><th className="th hidden md:table-cell">Hostname</th><th className="th hidden lg:table-cell">Category</th><th className="th hidden lg:table-cell">Criticality</th><th className="th hidden xl:table-cell">Last discovered</th><th className="th">Status</th><th className="th"></th></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{list.data.map(s => (
              <tr key={s.id}>
                <td className="td mono">{s.server_code}</td>
                <td className="td"><span className="font-medium">{s.name}</span>{s.is_sample && <span className="ml-1.5"><Badge tone="violet">SAMPLE</Badge></span>}<div className="text-xs text-slate-500">{s.application ?? ""}</div></td>
                <td className="td mono">{s.ip_address}</td>
                <td className="td hidden md:table-cell">{s.hostname || "-"}</td>
                <td className="td hidden lg:table-cell">{s.category}</td>
                <td className="td hidden lg:table-cell"><Badge tone={s.criticality === "High" || s.criticality === "Critical" ? "amber" : "slate"}>{s.criticality}</Badge></td>
                <td className="td hidden xl:table-cell">{fmtTime(s.last_discovered_at)}</td>
                <td className="td"><Badge tone={s.status === "Active" ? "green" : "slate"}>{s.status}</Badge></td>
                <td className="td whitespace-nowrap">{editor && <>
                  <button className="btn-ghost px-2 py-1" onClick={() => openEdit(s)} aria-label={`Edit ${s.name}`}><Pencil className="size-3.5" /></button>
                  <button className="btn-ghost px-2 py-1 text-rose-600" onClick={() => { setDel(s); setDelConfirm(""); }} aria-label={`Delete ${s.name}`}><Trash2 className="size-3.5" /></button></>}</td>
              </tr>))}</tbody>
          </Table>)}
      </Card>

      {editor && (
        <Card title="Import from Excel" icon={<Upload className="size-4 text-slate-500" />}>
          <p className="mb-3 text-sm text-slate-500">Use the exported workbook as a template. Rows match on Server ID; rows without one are added. Validate first.</p>
          <div className="flex flex-wrap items-center gap-2">
            <input ref={fileRef} type="file" accept=".xlsx" aria-label="Excel file" onChange={e => { setFile(e.target.files?.[0] ?? null); setImportRes(null); }} className="text-sm" />
            <button className="btn-secondary" disabled={!file} onClick={() => runImport(true)}>Validate</button>
            <button className="btn-primary" disabled={!file || !importRes?.dry_run} onClick={() => runImport(false)}>Import</button>
          </div>
          {importRes && <div className="mt-3"><Alert kind={importRes.errors.length ? "warning" : "success"}>
            {importRes.dry_run ? "Validation" : "Import"}: {importRes.created} new, {importRes.updated} updated, {importRes.errors.length} error(s).
            {importRes.errors.slice(0, 10).map((e: any) => <div key={e.row} className="text-xs">Row {e.row}: {e.error}</div>)}
          </Alert></div>}
        </Card>)}

      <ConfirmDialog open={!!edit} title={edit?.id ? `Edit ${edit.server_code}` : "Add server"} confirmLabel="Save" onClose={() => setEdit(null)} onConfirm={save}>
        {edit && <div className="grid max-h-[60vh] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
          <Field label="Name" htmlFor="f-name"><input id="f-name" className="input" value={edit.name} onChange={e => set("name", e.target.value)} /></Field>
          <Field label="IP address" htmlFor="f-ip"><input id="f-ip" className="input mono" value={edit.ip_address} onChange={e => set("ip_address", e.target.value)} /></Field>
          <Field label="Hostname" htmlFor="f-host"><input id="f-host" className="input" value={edit.hostname} onChange={e => set("hostname", e.target.value)} /></Field>
          <Field label="Environment" htmlFor="f-env"><input id="f-env" className="input" value={edit.environment} onChange={e => set("environment", e.target.value)} /></Field>
          <Field label="Operating system" htmlFor="f-os"><input id="f-os" className="input" value={edit.operating_system} onChange={e => set("operating_system", e.target.value)} /></Field>
          <Field label="Application" htmlFor="f-app"><input id="f-app" className="input" list="apps" value={edit.application ?? ""} onChange={e => set("application", e.target.value)} />
            <datalist id="apps">{meta.data?.applications.map((a: string) => <option key={a} value={a} />)}</datalist></Field>
          <Field label="Category" htmlFor="f-cat"><select id="f-cat" className="input" value={edit.category} onChange={e => set("category", e.target.value)}>{meta.data?.categories.map((c: string) => <option key={c}>{c}</option>)}</select></Field>
          <Field label="Criticality" htmlFor="f-crit"><select id="f-crit" className="input" value={edit.criticality} onChange={e => set("criticality", e.target.value)}>{meta.data?.criticality.map((c: string) => <option key={c}>{c}</option>)}</select></Field>
          <Field label="Technical owner" htmlFor="f-to"><input id="f-to" className="input" value={edit.technical_owner} onChange={e => set("technical_owner", e.target.value)} /></Field>
          <Field label="Business owner" htmlFor="f-bo"><input id="f-bo" className="input" value={edit.business_owner} onChange={e => set("business_owner", e.target.value)} /></Field>
          <Field label="Approved ports" htmlFor="f-ports"><input id="f-ports" className="input" value={portsText} onChange={e => setPortsText(e.target.value)} placeholder="22, 443" /></Field>
          <Field label="Status" htmlFor="f-st"><select id="f-st" className="input" value={edit.status} onChange={e => set("status", e.target.value)}><option>Active</option><option>Inactive</option></select></Field>
          <div className="sm:col-span-2"><Field label="Description" htmlFor="f-desc"><textarea id="f-desc" className="input" rows={2} value={edit.description} onChange={e => set("description", e.target.value)} /></Field></div>
        </div>}
      </ConfirmDialog>
      <ConfirmDialog open={!!del} title="Delete server record?" danger confirmLabel="Delete permanently" onClose={() => setDel(null)} onConfirm={remove}>
        <p className="mb-2">This removes <strong>{del?.name}</strong> from the inventory. Consider setting it to Inactive instead.</p>
        <Field label={`Type ${del?.server_code} to confirm`} htmlFor="del-c"><input id="del-c" className="input mono" value={delConfirm} onChange={e => setDelConfirm(e.target.value)} /></Field>
      </ConfirmDialog>
    </div>
  );
}
