"""Executive number formatting — mirrors the API's Format class so text and visuals agree."""


def compact(v: float) -> str:
    a = abs(v)
    if a >= 1e9:
        return f"{v / 1e9:,.2f}B"
    if a >= 1e6:
        return f"{v / 1e6:,.2f}M"
    if a >= 1e4:
        return f"{v / 1e3:,.1f}K"
    if a >= 100:
        return f"{v:,.0f}"
    return f"{v:,.2f}".rstrip("0").rstrip(".")


def value(v: float | None, fmt: str, currency: str = "RM") -> str:
    if v is None:
        return "—"
    if fmt == "percent":
        return f"{v * 100:.1f}%"
    if fmt == "currency":
        return f"{currency} {compact(v)}"
    if fmt == "duration_days":
        return f"{v:.1f} days"
    return compact(v)


def change(ch: float | None, pct: float | None, fmt: str) -> str:
    if ch is None:
        return "no comparison available"
    sign = "+" if ch >= 0 else "−"
    if fmt == "percent":
        return f"{sign}{abs(ch) * 100:.1f} pts"
    if pct is not None:
        return f"{sign}{abs(pct) * 100:.1f}%"
    return f"{sign}{compact(abs(ch))}"


def period(label: str) -> str:
    return "the " + label[0].lower() + label[1:] if label.startswith(("Last ", "This ")) else label
