"""Deterministic narrative: assembled only from computed facts."""

from .handlers import Outcome

OPENERS = {
    "overview": "Here is how the business is performing.",
    "why": "Here is what drove the change.",
    "breakdown": "Here is the breakdown.",
    "trend": "Here is the trend.",
    "forecast": "Here is the outlook.",
    "what_if": "Here is the simulated impact.",
    "anomalies": "Here is what looks unusual.",
}


def compose(intent: str, o: Outcome, executive: bool = False) -> str:
    if o.summary:
        text = o.summary
    elif not o.facts and o.caveats:
        return "I couldn't complete that: " + " ".join(o.caveats)
    else:
        parts = [f for f in o.facts[: (3 if executive else 5)] if f]
        text = " ".join(parts) if parts else OPENERS.get(intent, "Done.")
    if o.caveats:
        text += "\n\n_Note: " + " ".join(o.caveats[:2]) + "_"
    return text
