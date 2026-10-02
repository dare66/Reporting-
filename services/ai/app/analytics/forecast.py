"""Statistical forecasting: Holt-Winters when enough seasonal history exists,
damped Holt otherwise. Prediction intervals come from in-sample residuals and
widen with the horizon; accuracy is reported from a hold-out backtest."""

from __future__ import annotations

import math
import warnings
from datetime import date, timedelta
from typing import Any

import numpy as np
from statsmodels.tsa.holtwinters import ExponentialSmoothing

from ..types import JSON, JSONList

SEASON = {"day": 7, "week": 52, "month": 12, "quarter": 4}
Z80 = 1.2816


def _next_period(d: date, grain: str, k: int) -> date:
    if grain == "day":
        return d + timedelta(days=k)
    if grain == "week":
        return d + timedelta(weeks=k)
    months = k * (3 if grain == "quarter" else 12 if grain == "year" else 1)
    m = d.month - 1 + months
    return date(d.year + m // 12, m % 12 + 1, 1)


def _fit(y: np.ndarray, grain: str) -> tuple[Any, str]:
    """Fits exponential smoothing; returns the statsmodels results object (untyped) and the method name."""
    season = SEASON.get(grain)
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        if season and len(y) >= 2 * season:
            model = ExponentialSmoothing(
                y,
                trend="add",
                damped_trend=True,
                seasonal="add",
                seasonal_periods=season,
                initialization_method="estimated",
            )
            method = "holt_winters_additive"
        else:
            model = ExponentialSmoothing(y, trend="add", damped_trend=True, initialization_method="estimated")
            method = "holt_damped_trend"
        return model.fit(optimized=True), method


def forecast(series: JSONList, grain: str, horizon: int) -> JSON:
    points = [(date.fromisoformat(str(p["period"])[:10]), p["value"]) for p in series if p.get("value") is not None]
    if len(points) < 6:
        raise ValueError("At least 6 historical periods are needed to forecast.")
    dates = [d for d, _ in points]
    y = np.array([float(v) for _, v in points])
    bounded = bool(np.all((y >= 0) & (y <= 1)))  # ratios stay within [0, 1]
    nonneg = bool(np.all(y >= 0))

    fit, method = _fit(y, grain)
    pred = fit.forecast(horizon)
    resid = y - fit.fittedvalues
    sigma = float(np.std(resid[1:], ddof=1)) if len(resid) > 2 else float(np.std(y))

    # Hold-out backtest over the last min(6, n/4) periods.
    k = max(2, min(6, len(y) // 4))
    mape = None
    if len(y) - k >= 6:
        bfit, _ = _fit(y[:-k], grain)
        actual = y[-k:]
        bt = bfit.forecast(k)
        nz = actual != 0
        if nz.any():
            mape = float(np.mean(np.abs((actual[nz] - bt[nz]) / actual[nz])))
        # In-sample residuals flatter the model; out-of-sample error is the honest scale.
        rmse = float(np.sqrt(np.mean((actual - bt) ** 2)))
        sigma = max(sigma, rmse / math.sqrt((k + 1) / 2))

    def clip(v: float) -> float:
        if bounded:
            return min(1.0, max(0.0, v))
        return max(0.0, v) if nonneg else v

    out = []
    for h, v in enumerate(pred, start=1):
        width = Z80 * sigma * math.sqrt(h)
        out.append(
            {
                "period": _next_period(dates[-1], grain, h).isoformat(),
                "value": round(clip(float(v)), 6),
                "lower": round(clip(float(v) - width), 6),
                "upper": round(clip(float(v) + width), 6),
            }
        )

    return {
        "method": method,
        "points": out,
        "diagnostics": {
            "observations": len(y),
            "residual_sigma": round(sigma, 6),
            "backtest_mape": None if mape is None else round(mape, 4),
            "backtest_periods": k,
            "interval": 0.8,
            "seasonal_period": SEASON.get(grain) if method.startswith("holt_winters") else None,
        },
    }
