"""Seasonal robust anomaly detection without look-ahead.

For each point the expectation is the median of the same seasonal position
(e.g. same weekday) over the trailing window; the scale is the MAD of trailing
residuals. Score = (actual − expected) / (1.4826 · MAD). |score| ≥ threshold flags."""

from __future__ import annotations

from datetime import date

import numpy as np

SEASON = {"day": 7, "week": 1, "month": 1}
MIN_RESIDUALS = 21


def detect(series: list[dict], grain: str = "day", threshold: float = 3.0, cycles: int = 8) -> dict:
    s = SEASON.get(grain, 1)
    pts = [(str(p["period"])[:10], p["value"]) for p in series]
    values = np.array([np.nan if v is None else float(v) for _, v in pts])
    n = len(values)
    window = s * cycles
    results = []
    residual_history: list[float] = []

    for i in range(n):
        same = [values[j] for j in range(i - s, max(-1, i - window - 1), -s) if j >= 0 and not np.isnan(values[j])]
        if len(same) < 4 or np.isnan(values[i]):
            continue
        expected = float(np.median(same))
        residual = float(values[i]) - expected
        recent_resid = residual_history[-window:]
        residual_history.append(residual)
        if len(recent_resid) < MIN_RESIDUALS:
            continue  # not enough history to judge "normal" yet
        mad = float(np.median(np.abs(np.array(recent_resid) - np.median(recent_resid))))
        # Floor the robust scale so a quiet stretch cannot make ordinary noise look extreme.
        scale = max(1.4826 * mad, 0.5 * float(np.std(recent_resid, ddof=1)))
        if scale <= 1e-12:
            continue
        score = residual / scale
        if abs(score) >= threshold:
            results.append({
                "period": pts[i][0],
                "actual": round(float(values[i]), 6),
                "expected": round(expected, 6),
                "lower": round(expected - threshold * scale, 6),
                "upper": round(expected + threshold * scale, 6),
                "score": round(score, 3),
            })

    return {"method": f"seasonal_median_mad_s{s}_w{window}", "threshold": threshold, "anomalies": results, "evaluated": n}


def detect_dates(series: list[dict]) -> list[date]:
    return [date.fromisoformat(a["period"]) for a in detect(series)["anomalies"]]
