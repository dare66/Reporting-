import math
import random

import pytest

from app.analytics.anomalies import detect
from app.analytics.forecast import forecast


def monthly(n=24, noise=10.0, seed=1):
    random.seed(seed)
    return [
        {
            "period": f"{2024 + i // 12}-{i % 12 + 1:02d}-01",
            "value": 1000 + 15 * i + 120 * math.sin(i / 12 * 2 * math.pi) + random.gauss(0, noise),
        }
        for i in range(n)
    ]


def test_forecast_is_deterministic_and_seasonal():
    a, b = forecast(monthly(), "month", 6), forecast(monthly(), "month", 6)
    assert a == b
    assert a["method"] == "holt_winters_additive"
    assert [p["period"] for p in a["points"]][:2] == ["2026-01-01", "2026-02-01"]
    for p in a["points"]:
        assert p["lower"] <= p["value"] <= p["upper"]
    widths = [p["upper"] - p["lower"] for p in a["points"]]
    assert widths == sorted(widths), "intervals widen with horizon"


def test_short_history_uses_damped_trend_and_ratios_stay_bounded():
    s = [{"period": f"2026-0{i + 1}-01", "value": 0.95 - i * 0.01} for i in range(8)]
    out = forecast(s, "month", 12)
    assert out["method"] == "holt_damped_trend"
    assert all(0 <= p["lower"] <= p["value"] <= p["upper"] <= 1 for p in out["points"])


def test_forecast_rejects_tiny_series():
    with pytest.raises(ValueError):
        forecast(monthly(4), "month", 3)


def test_anomaly_detector_finds_level_shift_without_lookahead():
    random.seed(7)
    series = [
        {"period": f"d{i:03d}", "value": 0.065 + random.gauss(0, 0.004) + (0.04 if i >= 80 else 0)} for i in range(100)
    ]
    found = detect(series)["anomalies"]
    assert found and found[0]["period"] == "d080"
    assert all(a["score"] > 0 for a in found)


def test_anomaly_detector_is_quiet_on_noise_and_respects_weekday_pattern():
    random.seed(3)
    series = [{"period": f"d{i:03d}", "value": (400 if i % 7 < 5 else 180) + random.gauss(0, 12)} for i in range(120)]
    assert len(detect(series, threshold=4.0)["anomalies"]) <= 1


def test_nulls_are_ignored():
    series = [{"period": f"d{i:03d}", "value": None if i % 9 == 0 else 10.0 + (i % 3)} for i in range(60)]
    detect(series)  # must not raise
