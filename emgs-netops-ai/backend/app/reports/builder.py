"""Report generation (Excel via openpyxl, PDF via reportlab).

Every report carries: reference, execution date/time, requesting user,
source laptop/network interface, destination or subnet, methodology, raw
results, assessment and recommendations. Simulation-mode data and sample
inventory are labelled on every page/sheet.
"""
from __future__ import annotations

import io
import re
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import PageBreak, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import get_settings
from app.core.context import ExecutionContext, new_reference
from app.discovery.comparison import scan_changes
from app.models import entities as m
from app.security.validation import ValidationError
from app.services import audit
from app.services.settings import current_mode, get_setting

REPORT_TYPES = {
    "ping": "Ping Report",
    "traceroute": "Traceroute Report",
    "ports": "Port Connectivity Report",
    "dns": "DNS Report",
    "ssl": "SSL Certificate Report",
    "discovery": "Network Discovery Report",
    "inventory": "Server Inventory Report",
    "unknown_devices": "Unknown Device Report",
    "consolidated": "Consolidated Infrastructure Diagnostic Report",
}
_JOB_TYPE = {"ping": ["ping"], "traceroute": ["traceroute"], "ports": ["port"], "dns": ["dns"],
             "ssl": ["ssl"]}
METHODOLOGY = {
    "ping": "ICMP echo requests sent from the laptop using the operating system's native ping utility "
            "(argument-validated, time-limited). No reply does not by itself mean a host is offline.",
    "traceroute": "Native tracert/traceroute/tracepath with a bounded hop count and per-probe timeout. "
                  "Intermediate hops that do not answer are recorded but are not treated as failures.",
    "ports": "TCP connect test (three-way handshake) to approved ports only. Open = handshake completed; "
             "Closed = reset received; Filtered = no answer within the timeout.",
    "dns": "DNS queries using the laptop's configured resolver (or a specified DNS server) with dnspython.",
    "ssl": "TLS connection to the service, retrieval of the public certificate, validation against the "
           "operating system trust store and hostname matching. No private key material is accessible.",
    "discovery": "Authorised range validated and confirmed before execution. Hosts detected with permitted methods "
                 "(ICMP, TCP connect to approved ports, ARP for the local subnet, reverse DNS, optional Nmap) under "
                 "concurrency and rate limits. Non-response to ping is not treated as offline. Results compared "
                 "with the local inventory.",
    "inventory": "Export of the locally maintained server inventory.",
    "unknown_devices": "Devices from the selected discovery that are not matched to the inventory. "
                       "Classification indicates a need for review only, not wrongdoing.",
    "consolidated": "Summary of diagnostics, certificates and discovery results recorded on this laptop.",
}


@dataclass
class Section:
    heading: str
    text: str = ""
    bullets: list[str] = field(default_factory=list)
    columns: list[str] = field(default_factory=list)
    rows: list[list] = field(default_factory=list)


@dataclass
class ReportData:
    title: str
    reference: str
    meta: list[tuple[str, str]]
    sections: list[Section]
    banner: str = ""


# ------------------------------------------------------------------ data gathering

def _jobs(db: Session, types: list[str], refs: list[str] | None, target: str, days: int, mode: str) -> list[m.DiagnosticJob]:
    stmt = select(m.DiagnosticJob).where(m.DiagnosticJob.diagnostic_type.in_(types))
    if refs:
        stmt = stmt.where(m.DiagnosticJob.reference.in_(refs))
    else:
        stmt = stmt.where(m.DiagnosticJob.started_at >= datetime.utcnow() - timedelta(days=days),
                          m.DiagnosticJob.mode == mode)
    if target:
        stmt = stmt.where(m.DiagnosticJob.target.ilike(f"%{target}%"))
    return list(db.scalars(stmt.order_by(m.DiagnosticJob.id.desc()).limit(200)))


def _assessment_sections(jobs: list[m.DiagnosticJob]) -> list[Section]:
    confirmed, possible, recs = [], [], []
    for j in jobs[:25]:
        if not j.results:
            continue
        a = j.results[-1].assessment or {}
        if a.get("summary"):
            confirmed.append(f"[{j.reference}] {a['summary']}")
        for p in a.get("possible_causes", []):
            item = f"{j.target}: {p}"
            if item not in possible:
                possible.append(item)
        for r in a.get("recommendations", []):
            if r not in recs:
                recs.append(r)
    return [Section("Assessment (confirmed results)", bullets=confirmed or ["No results in scope."]),
            Section("Possible causes (not confirmed)", bullets=possible or ["None identified."]),
            Section("Recommendations", bullets=recs or ["No further action identified."])]


def _fmt(v) -> str:
    if v is None or v == "":
        return "-"
    if isinstance(v, float):
        return f"{v:.2f}"
    if isinstance(v, (list, tuple)):
        return ", ".join(map(str, v)) or "-"
    return str(v)


def _diagnostic_sections(kind: str, jobs: list[m.DiagnosticJob]) -> list[Section]:
    out: list[Section] = []
    if kind == "ping":
        rows = []
        for j in jobs:
            s = j.results[-1].summary if j.results else {}
            rows.append([j.reference, _ts(j.started_at), j.target, s.get("target_ip"), s.get("packets_transmitted"),
                         s.get("packets_received"), s.get("packet_loss_pct"), s.get("min_latency_ms"),
                         s.get("avg_latency_ms"), s.get("max_latency_ms"), j.duration_ms, j.outcome])
        out.append(Section("Ping results", columns=["Reference", "Executed", "Target", "IP", "Sent", "Received", "Loss %",
                                                    "Min ms", "Avg ms", "Max ms", "Duration ms", "Status"], rows=rows))
    elif kind == "traceroute":
        rows = []
        for j in jobs:
            s = j.results[-1].summary if j.results else {}
            for h in s.get("hops", []):
                rows.append([j.reference, j.target, h["hop"], h.get("ip"), h.get("hostname"), h.get("avg_ms"),
                             h.get("timeouts"), h.get("status")])
        out.append(Section("Route hops", columns=["Reference", "Destination", "Hop", "Router IP", "Hostname",
                                                  "Avg ms", "Timeouts", "Status"], rows=rows))
        out.append(Section("Route outcome", columns=["Reference", "Executed", "Destination", "Hops", "Reached", "Status"],
                           rows=[[j.reference, _ts(j.started_at), j.target,
                                  (j.results[-1].summary.get("hop_count") if j.results else None),
                                  (j.results[-1].summary.get("destination_reached") if j.results else None), j.outcome]
                                 for j in jobs]))
    elif kind == "ports":
        rows = []
        for j in jobs:
            for r in (j.results[-1].summary.get("results", []) if j.results else []):
                rows.append([j.reference, _ts(j.started_at), j.target, r["destination"], r["port"], r["protocol"],
                             r.get("service"), r["status"], r.get("response_ms"), r.get("error")])
        out.append(Section("Port connectivity", columns=["Reference", "Executed", "Target", "Destination IP", "Port",
                                                         "Protocol", "Service", "Status", "Response ms", "Detail"], rows=rows))
    elif kind == "dns":
        rows = []
        for j in jobs:
            s = j.results[-1].summary if j.results else {}
            rows.append([j.reference, _ts(j.started_at), s.get("domain", j.target), s.get("record_type"),
                         s.get("values"), s.get("dns_server"), s.get("duration_ms"), s.get("status")])
        out.append(Section("DNS results", columns=["Reference", "Executed", "Domain", "Type", "Values", "DNS server",
                                                   "Duration ms", "Status"], rows=rows))
    elif kind == "ssl":
        rows = []
        for j in jobs:
            s = j.results[-1].summary if j.results else {}
            rows.append([j.reference, _ts(j.started_at), f"{s.get('host', j.target)}:{s.get('port', 443)}",
                         s.get("subject"), s.get("issuer"), (s.get("valid_from") or "")[:10],
                         (s.get("valid_until") or "")[:10], s.get("days_remaining"), s.get("hostname_match"),
                         s.get("status")])
        out.append(Section("Certificates", columns=["Reference", "Checked", "Service", "Subject", "Issuer", "Valid from",
                                                    "Valid until", "Days left", "Hostname match", "Status"], rows=rows))
    out.append(Section("Raw diagnostic output (latest 5)", bullets=[
        f"{j.reference} {j.target}:\n{(j.results[-1].raw_output or '(structured result only)')[:1500]}"
        for j in jobs[:5] if j.results]))
    return out


def _scan(db: Session, ref: str | None, mode: str) -> m.DiscoveryScan:
    stmt = select(m.DiscoveryScan)
    stmt = stmt.where(m.DiscoveryScan.reference == ref) if ref else \
        stmt.where(m.DiscoveryScan.status.in_(("completed", "cancelled")), m.DiscoveryScan.mode == mode)
    scan = db.scalar(stmt.order_by(m.DiscoveryScan.id.desc()))
    if not scan:
        raise ValidationError("No completed discovery scan found for this report.")
    return scan


def _discovery_sections(db: Session, scan: m.DiscoveryScan, unknown_only: bool) -> list[Section]:
    comps = list(db.scalars(select(m.InventoryComparison).where(m.InventoryComparison.scan_id == scan.id)))
    review_classes = {"Unknown Device", "Newly Discovered", "Possible Unauthorised Asset"}
    devices = {d.id: d for d in scan.devices}
    out = []
    counts: dict[str, int] = {}
    for c in comps:
        counts[c.classification] = counts.get(c.classification, 0) + 1
    out.append(Section("Scan summary", columns=["Item", "Value"], rows=[
        ["Range requested", scan.range_input], ["Normalised range", scan.normalized_range],
        ["Addresses in scope", scan.address_count], ["Addresses checked", scan.progress],
        ["Responding devices", scan.responding], ["Methods", ", ".join(scan.methods)],
        ["Approved ports checked", ", ".join(map(str, scan.ports))], ["Status", scan.status],
        *[[f"Classified: {k}", v] for k, v in sorted(counts.items())]]))
    sel = [c for c in comps if (c.classification in review_classes) or not unknown_only]
    rows = []
    for c in sel:
        d = devices.get(c.device_id)
        rows.append([c.ip_address, c.classification, d.hostname if d else "", d.mac_address if d else "",
                     ", ".join(str(p.port) for p in d.ports if p.state == "open") if d else "",
                     ", ".join(d.detection_methods) if d else "not detected", c.detail])
    out.append(Section("Devices requiring review" if unknown_only else "Inventory comparison",
                       columns=["IP", "Classification", "Hostname", "MAC", "Open ports", "Detected by", "Detail"],
                       rows=rows))
    if not unknown_only:
        prow = []
        for d in sorted(scan.devices, key=lambda x: tuple(int(o) for o in x.ip_address.split("."))):
            for p in d.ports:
                prow.append([d.ip_address, d.hostname, p.port, p.state, p.response_ms])
        out.append(Section("Approved port results", columns=["IP", "Hostname", "Port", "State", "Response ms"], rows=prow))
        ch = scan_changes(db, scan)
        if ch["previous_reference"]:
            out.append(Section(f"Changes since {ch['previous_reference']}", bullets=[
                f"Appeared: {', '.join(ch['appeared']) or 'none'}", f"Disappeared: {', '.join(ch['disappeared']) or 'none'}",
                *[f"{c['ip']}: {c['changes']}" for c in ch["changes"]]]))
    recs = []
    if counts.get("Registered but Not Responding"):
        recs.append("Confirm with server owners whether non-responding registered servers are decommissioned, "
                    "powered off or filtered; update the inventory status accordingly.")
    if counts.get("Hostname Mismatch"):
        recs.append("Reconcile DNS PTR records and inventory hostnames for mismatched devices.")
    if any(counts.get(k) for k in review_classes):
        recs.append("Review newly discovered and unknown devices with the network team; register legitimate assets.")
    if counts.get("Duplicate IP Conflict"):
        recs.append("Resolve duplicate IP records in the inventory.")
    out.append(Section("Assessment", bullets=[
        f"{scan.responding} device(s) responded out of {scan.progress} address(es) checked.",
        "Devices that did not respond to ICMP were still detected where TCP or ARP evidence existed."]))
    out.append(Section("Recommendations", bullets=recs or ["No discrepancies requiring action."]))
    return out


def _inventory_sections(db: Session, mode: str) -> list[Section]:
    servers = list(db.scalars(select(m.Server).order_by(m.Server.server_code)))
    rows = [[s.server_code, s.name, s.hostname, s.ip_address, s.environment, s.operating_system,
             s.application.name if s.application else "", s.category, s.technical_owner, s.business_owner,
             s.criticality, s.approved_ports, _ts(s.last_discovered_at), s.status, "SAMPLE" if s.is_sample else ""]
            for s in servers]
    return [Section("Registered servers", columns=["Server ID", "Name", "Hostname", "IP", "Environment", "OS",
                                                   "Application", "Category", "Technical owner", "Business owner",
                                                   "Criticality", "Approved ports", "Last discovered", "Status",
                                                   "Sample"], rows=rows),
            Section("Assessment", bullets=[f"{len(servers)} server record(s); "
                                           f"{sum(s.is_sample for s in servers)} are SAMPLE data and are not real EMGS assets."]),
            Section("Recommendations", bullets=["Replace sample records with authorised inventory data before production use."]
                    if any(s.is_sample for s in servers) else ["Keep owners and approved ports up to date."])]


def _consolidated_sections(db: Session, mode: str, days: int) -> list[Section]:
    from app.services.history import dashboard
    d = dashboard(db, mode)
    t = d["totals"]
    out = [Section("Overview", columns=["Measure", "Value"], rows=[
        ["Registered active assets", t["registered_assets"]], ["Devices active in latest discovery", t["active_discovered"]],
        ["Devices needing review", t["unidentified_devices"]], ["Diagnostics in last 7 days", t["diagnostics_7d"]],
        ["Failed / degraded checks (7 days)", t["recent_failures"]],
        ["Ports open / closed / filtered", f"{d['port_summary']['open']} / {d['port_summary']['closed']} / {d['port_summary']['filtered']}"]])]
    out.append(Section("Recent failures", columns=["Reference", "Type", "Target", "Port", "Outcome", "Executed"],
                       rows=[[f["reference"], f["type"], f["target"], f["port"], f["outcome"], f["started_at"][:19]]
                             for f in d["recent_failures"]]))
    out.append(Section("Certificates expiring within 60 days", columns=["Service", "Days remaining", "Valid until", "Status"],
                       rows=[[f"{c['host']}:{c['port']}", c["days_remaining"], (c["valid_until"] or "")[:10], c["status"]]
                             for c in d["ssl_expiring"]]))
    if d["latest_scan"]:
        s = d["latest_scan"]
        out.append(Section(f"Latest discovery {s['reference']} ({s['normalized_range']})", columns=["Classification", "Count"],
                           rows=[[k, v] for k, v in sorted(d["latest_scan_classification"].items())]))
    jobs = _jobs(db, ["ping", "traceroute", "port", "dns", "http", "https", "ssl"], None, "", days, mode)
    out.append(Section("Diagnostics in period", columns=["Reference", "Executed", "Type", "Target", "Port", "Status",
                                                         "Outcome", "User", "Interface"],
                       rows=[[j.reference, _ts(j.started_at), j.diagnostic_type, j.target, j.port, j.status, j.outcome,
                              j.user.username if j.user else "", j.source_interface] for j in jobs]))
    out.append(Section("AI observations", bullets=[o["text"] for o in d["observations"]]))
    out += _assessment_sections(jobs)
    return out


def build(db: Session, ctx: ExecutionContext, report_type: str, *, references: list[str] | None = None,
          target: str = "", scan_reference: str | None = None, days: int = 30) -> ReportData:
    if report_type not in REPORT_TYPES:
        raise ValidationError(f"Unknown report type. Choose one of: {', '.join(REPORT_TYPES)}.")
    days = max(1, min(int(days or 30), 3650))
    mode = current_mode(db)
    destination = target or "All targets in period"
    source = "-"
    if report_type in _JOB_TYPE:
        jobs = _jobs(db, _JOB_TYPE[report_type], references, target, days, mode)
        sections = _diagnostic_sections(report_type, jobs) + _assessment_sections(jobs)
        source = ", ".join(sorted({j.source_network for j in jobs if j.source_network})) or "-"
        simulated = any(j.mode == "simulation" for j in jobs)
    elif report_type in ("discovery", "unknown_devices"):
        scan = _scan(db, scan_reference, mode)
        sections = _discovery_sections(db, scan, report_type == "unknown_devices")
        destination, source, simulated = scan.normalized_range, scan.source_network, scan.mode == "simulation"
    elif report_type == "inventory":
        sections = _inventory_sections(db, mode)
        destination, simulated = "Local server inventory", False
    else:
        sections = _consolidated_sections(db, mode, days)
        simulated = mode == "simulation"
    ref = new_reference("RP")
    meta = [("Report reference", ref), ("Report type", REPORT_TYPES[report_type]),
            ("Execution date and time", datetime.now().strftime("%Y-%m-%d %H:%M:%S (local)")),
            ("Requesting user", ctx.username), ("Source laptop / network interface", source),
            ("Destination or subnet", destination), ("Operating mode", mode.capitalize()),
            ("Period", f"Last {days} days" if not references else "Selected executions"),
            ("Test methodology", METHODOLOGY[report_type])]
    banner = ("SIMULATION MODE - results come from the built-in lab model, not from EMGS infrastructure."
              if simulated else "")
    if report_type == "inventory" and db.scalar(select(m.Server).where(m.Server.is_sample.is_(True))):
        banner = "Contains SAMPLE inventory records that are not real EMGS assets."
    return ReportData(REPORT_TYPES[report_type], ref, meta, sections, banner)


# ------------------------------------------------------------------ rendering

def render_xlsx(rep: ReportData) -> bytes:
    wb = Workbook()
    ws = wb.active
    ws.title = "Summary"
    ws.append([rep.title])
    ws["A1"].font = Font(bold=True, size=14, color="1F3A5F")
    if rep.banner:
        ws.append([rep.banner])
        ws.cell(ws.max_row, 1).font = Font(bold=True, color="B45309")
    ws.append([])
    for k, v in rep.meta:
        ws.append([k, v])
        ws.cell(ws.max_row, 1).font = Font(bold=True)
        ws.cell(ws.max_row, 2).alignment = Alignment(wrap_text=True, vertical="top")
    ws.column_dimensions["A"].width = 34
    ws.column_dimensions["B"].width = 100
    used = {"Summary"}
    for sec in rep.sections:
        if sec.columns:
            name = re.sub(r"[\[\]:*?/\\]", "", sec.heading)[:28] or "Section"
            base, n = name, 2
            while name in used:
                name = f"{base[:25]} {n}"
                n += 1
            used.add(name)
            sh = wb.create_sheet(name)
            if rep.banner:
                sh.append([rep.banner])
                sh.cell(1, 1).font = Font(bold=True, color="B45309")
            sh.append(sec.columns)
            hdr = sh.max_row
            for c in sh[hdr]:
                c.font = Font(bold=True, color="FFFFFF")
                c.fill = PatternFill("solid", fgColor="1F3A5F")
            for r in sec.rows:
                sh.append([_fmt(v) if not isinstance(v, (int, float)) or isinstance(v, bool) else v for v in r])
            for i, col in enumerate(sec.columns, start=1):
                width = max([len(str(col))] + [len(_fmt(r[i - 1])) for r in sec.rows[:200]]) + 2
                sh.column_dimensions[sh.cell(hdr, i).column_letter].width = min(max(width, 10), 60)
            sh.freeze_panes = sh.cell(hdr + 1, 1)
        else:
            ws.append([])
            ws.append([sec.heading])
            ws.cell(ws.max_row, 1).font = Font(bold=True, color="1F3A5F")
            if sec.text:
                ws.append(["", sec.text])
            for b in sec.bullets:
                ws.append(["", b])
                ws.cell(ws.max_row, 2).alignment = Alignment(wrap_text=True, vertical="top")
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


def _p(text: str, style) -> Paragraph:
    safe = (str(text).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\n", "<br/>"))
    return Paragraph(safe, style)


def render_pdf(rep: ReportData) -> bytes:
    buf = io.BytesIO()
    wide = any(len(s.columns) > 7 for s in rep.sections)
    pagesize = landscape(A4) if wide else A4
    doc = SimpleDocTemplate(buf, pagesize=pagesize, leftMargin=14 * mm, rightMargin=14 * mm, topMargin=16 * mm,
                            bottomMargin=16 * mm, title=rep.title, author="EMGS NetOps AI")
    ss = getSampleStyleSheet()
    body = ParagraphStyle("body", parent=ss["BodyText"], fontSize=8.5, leading=11)
    cell = ParagraphStyle("cell", parent=body, fontSize=7, leading=8.5)
    h1 = ParagraphStyle("h1", parent=ss["Title"], fontSize=17, textColor=colors.HexColor("#1F3A5F"), alignment=0)
    h2 = ParagraphStyle("h2", parent=ss["Heading2"], fontSize=11.5, textColor=colors.HexColor("#1F3A5F"), spaceBefore=10)
    warn = ParagraphStyle("warn", parent=body, textColor=colors.HexColor("#B45309"), fontName="Helvetica-Bold")
    story = [_p(rep.title, h1)]
    if rep.banner:
        story.append(_p(rep.banner, warn))
    story.append(Spacer(1, 4 * mm))
    meta = Table([[_p(k, ParagraphStyle("k", parent=body, fontName="Helvetica-Bold")), _p(v, body)] for k, v in rep.meta],
                 colWidths=[55 * mm, doc.width - 55 * mm])
    meta.setStyle(TableStyle([("GRID", (0, 0), (-1, -1), 0.3, colors.HexColor("#CBD5E1")),
                              ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#F1F5F9")),
                              ("VALIGN", (0, 0), (-1, -1), "TOP")]))
    story.append(meta)
    for sec in rep.sections:
        story.append(_p(sec.heading, h2))
        if sec.text:
            story.append(_p(sec.text, body))
        for b in sec.bullets:
            story.append(_p("• " + b, body))
        if sec.columns:
            if not sec.rows:
                story.append(_p("No records.", body))
                continue
            data = [[_p(c, ParagraphStyle("th", parent=cell, textColor=colors.white, fontName="Helvetica-Bold"))
                     for c in sec.columns]]
            data += [[_p(_fmt(v), cell) for v in r] for r in sec.rows[:1500]]
            t = Table(data, repeatRows=1, colWidths=[doc.width / len(sec.columns)] * len(sec.columns))
            t.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1F3A5F")),
                                   ("GRID", (0, 0), (-1, -1), 0.25, colors.HexColor("#CBD5E1")),
                                   ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F8FAFC")]),
                                   ("VALIGN", (0, 0), (-1, -1), "TOP")]))
            story.append(t)

    def footer(canvas, d):
        canvas.saveState()
        canvas.setFont("Helvetica", 7)
        canvas.setFillColor(colors.HexColor("#64748B"))
        text = f"EMGS NetOps AI  |  {rep.reference}  |  Page {d.page}"
        if rep.banner:
            text += "  |  " + ("SIMULATION / SAMPLE DATA" if "SIMULATION" in rep.banner or "SAMPLE" in rep.banner else "")
        canvas.drawString(d.leftMargin, 8 * mm, text)
        canvas.restoreState()

    doc.build(story, onFirstPage=footer, onLaterPages=footer)
    return buf.getvalue()


def report_dir(db: Session) -> Path:
    custom = get_setting(db, "report_storage_path")
    p = Path(custom) if custom else get_settings().data_dir / "reports"
    p.mkdir(parents=True, exist_ok=True)
    return p


def generate(db: Session, ctx: ExecutionContext, report_type: str, fmt: str = "pdf", **kwargs) -> m.ReportHistory:
    if fmt not in ("pdf", "xlsx"):
        raise ValidationError("Format must be pdf or xlsx.")
    rep = build(db, ctx, report_type, **kwargs)
    content = render_pdf(rep) if fmt == "pdf" else render_xlsx(rep)
    fname = f"{rep.reference}_{report_type}.{fmt}"
    (report_dir(db) / fname).write_bytes(content)
    row = m.ReportHistory(reference=rep.reference, report_type=report_type, format=fmt, file_name=fname,
                          parameters={k: v for k, v in kwargs.items() if v}, user_id=ctx.user_id,
                          size_bytes=len(content))
    db.add(row)
    audit.record(db, ctx, "report.generate", reference=rep.reference, destination=rep.meta[5][1],
                 detail=f"{report_type} ({fmt})")
    db.flush()
    return row


def report_to_dict(r: m.ReportHistory) -> dict:
    return {"reference": r.reference, "report_type": r.report_type, "title": REPORT_TYPES.get(r.report_type, r.report_type),
            "format": r.format, "file_name": r.file_name, "size_bytes": r.size_bytes, "parameters": r.parameters,
            "created_at": r.created_at.isoformat() + "Z", "download_url": f"/api/reports/{r.reference}/download"}


def _ts(v) -> str:
    return v.strftime("%Y-%m-%d %H:%M:%S") if v else "-"
