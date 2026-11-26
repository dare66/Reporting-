"""Input validation, range parsing and the target authorisation policy."""
import pytest

from app.security.policy import PolicyError, TargetPolicy
from app.security.ranges import parse_range
from app.security.validation import ValidationError, parse_target, validate_port


@pytest.mark.parametrize("value,kind", [("10.1.57.10", "ip"), ("stars.emgs.com.my", "hostname"),
                                        ("STARS.EMGS.COM.MY.", "hostname"), ("fe80::1", "ip")])
def test_valid_targets(value, kind):
    assert parse_target(value).kind == kind


@pytest.mark.parametrize("value", ["", "10.1.57.300", "10.1.57", "-oX /tmp/x", "a;rm -rf /", "host name",
                                   "$(whoami)", "`id`", "a" * 300, "10.1.57.10 && calc", "--help"])
def test_invalid_targets(value):
    with pytest.raises(ValidationError):
        parse_target(value)


@pytest.mark.parametrize("port", [0, 65536, -1, "abc", None])
def test_invalid_port(port):
    with pytest.raises(ValidationError):
        validate_port(port)


def test_range_formats():
    assert parse_range("10.1.57.10").count == 1
    w = parse_range("10.1.57.xx")
    assert (str(w.first), str(w.last), w.count, w.kind) == ("10.1.57.1", "10.1.57.254", 254, "wildcard")
    w2 = parse_range("10.1.57.*", "10-20")
    assert w2.count == 11
    c = parse_range("10.1.57.0/24")
    assert c.count == 254 and c.kind == "cidr"
    assert parse_range("10.1.57.5/24").normalized == "10.1.57.1-10.1.57.254"
    assert parse_range("10.1.57.1-10.1.57.254").count == 254
    assert parse_range("10.1.57.1-50").count == 50
    assert parse_range("10.1.57.8/30").count == 2


@pytest.mark.parametrize("bad", ["10.1.57.0/33", "10.1.57.300/24", "10.1.57.50-10.1.57.1", "10.1.57.xx; ls",
                                 "abc", "10.1.57.x/24"])
def test_invalid_ranges(bad):
    with pytest.raises(ValidationError):
        parse_range(bad)


def test_policy_simulation_includes_sample_rows(db):
    p = TargetPolicy(db, "simulation")
    assert p.authorize_target(parse_target("10.1.57.10")).addresses == ["10.1.57.10"]
    p.authorize_port(443)


def test_policy_live_ignores_sample_rows(db):
    p = TargetPolicy(db, "live")
    with pytest.raises(PolicyError):
        p.authorize_target(parse_target("10.1.57.10"))
    with pytest.raises(PolicyError):
        p.authorize_target(parse_target("emgs.com.my"), resolved=[])


def test_policy_rejects_public_loopback_and_unapproved(db):
    p = TargetPolicy(db, "simulation")
    for t in ("8.8.8.8", "127.0.0.1", "10.2.0.1", "224.0.0.1"):
        with pytest.raises(PolicyError):
            p.authorize_target(parse_target(t))
    with pytest.raises(PolicyError):
        p.authorize_port(23)


def test_policy_hostname_resolving_outside_approved_networks(db):
    p = TargetPolicy(db, "simulation")
    with pytest.raises(PolicyError):
        p.authorize_target(parse_target("visa.emgs.com.my"), resolved=["192.168.1.5"])
    with pytest.raises(PolicyError):
        p.authorize_target(parse_target("visa.emgs.com.my"), resolved=["127.0.0.1"])
    assert p.authorize_target(parse_target("visa.emgs.com.my"), resolved=["10.1.57.20"]).reason == "approved domain"
    with pytest.raises(PolicyError):
        p.authorize_target(parse_target("www.example.org"), resolved=[])


def test_policy_ranges(db):
    p = TargetPolicy(db, "simulation")
    assert p.authorize_range(parse_range("10.1.57.0/24"))["network"] == "10.1.57.0/24"
    with pytest.raises(PolicyError, match="not fully inside"):
        p.authorize_range(parse_range("10.1.58.0/24"))
    with pytest.raises(PolicyError, match="Public networks"):
        p.authorize_range(parse_range("8.8.8.0/24"))
    with pytest.raises(PolicyError, match="configured maximum"):
        p.authorize_range(parse_range("10.1.56.0/23"))
