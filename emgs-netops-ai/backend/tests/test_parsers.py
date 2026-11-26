"""Native command output parsing (Windows and Linux)."""
from app.diagnostics import parsers
from app.diagnostics.live import parse_nmap_xml
from app.diagnostics.parsers import classify_ping

LINUX_OK = """PING 10.1.57.10 (10.1.57.10) 56(84) bytes of data.
64 bytes from 10.1.57.10: icmp_seq=1 ttl=63 time=1.21 ms
64 bytes from 10.1.57.10: icmp_seq=2 ttl=63 time=1.05 ms
64 bytes from 10.1.57.10: icmp_seq=3 ttl=63 time=0.98 ms
64 bytes from 10.1.57.10: icmp_seq=4 ttl=63 time=1.40 ms

--- 10.1.57.10 ping statistics ---
4 packets transmitted, 4 received, 0% packet loss, time 3004ms
rtt min/avg/max/mdev = 0.980/1.160/1.400/0.160 ms
"""
LINUX_PARTIAL = """PING 10.1.57.25 (10.1.57.25) 56(84) bytes of data.
64 bytes from 10.1.57.25: icmp_seq=1 ttl=63 time=18.2 ms
64 bytes from 10.1.57.25: icmp_seq=3 ttl=63 time=21.0 ms

--- 10.1.57.25 ping statistics ---
4 packets transmitted, 2 received, 50% packet loss, time 3010ms
rtt min/avg/max/mdev = 18.200/19.600/21.000/1.400 ms
"""
LINUX_FAIL = """PING 10.1.57.15 (10.1.57.15) 56(84) bytes of data.

--- 10.1.57.15 ping statistics ---
4 packets transmitted, 0 received, 100% packet loss, time 3062ms
"""
WIN_OK = """
Pinging stars.emgs.local [10.1.57.10] with 32 bytes of data:
Reply from 10.1.57.10: bytes=32 time=1ms TTL=63
Reply from 10.1.57.10: bytes=32 time<1ms TTL=63
Reply from 10.1.57.10: bytes=32 time=2ms TTL=63
Reply from 10.1.57.10: bytes=32 time=1ms TTL=63

Ping statistics for 10.1.57.10:
    Packets: Sent = 4, Received = 4, Lost = 0 (0% loss),
Approximate round trip times in milli-seconds:
    Minimum = 0ms, Maximum = 2ms, Average = 1ms
"""
WIN_TIMEOUT = """
Pinging 10.1.57.15 with 32 bytes of data:
Request timed out.
Request timed out.
Request timed out.
Request timed out.

Ping statistics for 10.1.57.15:
    Packets: Sent = 4, Received = 0, Lost = 4 (100% loss),
"""
WIN_UNREACHABLE = """
Pinging 10.1.58.9 with 32 bytes of data:
Reply from 10.1.57.1: Destination host unreachable.
Reply from 10.1.57.1: Destination host unreachable.
Request timed out.
Reply from 10.1.57.1: Destination host unreachable.

Ping statistics for 10.1.58.9:
    Packets: Sent = 4, Received = 3, Lost = 1 (25% loss),
"""


def test_linux_ping_success():
    r = parsers.parse_ping_linux(LINUX_OK)
    assert (r["transmitted"], r["received"], r["loss_pct"]) == (4, 4, 0.0)
    assert (r["min_ms"], r["avg_ms"], r["max_ms"]) == (0.98, 1.16, 1.4)
    assert classify_ping(r) == "Successful"


def test_linux_ping_partial_loss():
    r = parsers.parse_ping_linux(LINUX_PARTIAL)
    assert r["received"] == 2 and r["loss_pct"] == 50.0
    assert classify_ping(r) == "Partial packet loss"


def test_linux_ping_failed():
    r = parsers.parse_ping_linux(LINUX_FAIL)
    assert r["received"] == 0 and r["avg_ms"] is None
    assert classify_ping(r) == "No ICMP response"


def test_windows_ping_success_handles_sub_millisecond():
    r = parsers.parse_ping_windows(WIN_OK)
    assert r["resolved_ip"] == "10.1.57.10"
    assert r["received"] == 4 and len(r["replies"]) == 4
    assert r["avg_ms"] == 1.0
    assert classify_ping(r) == "Successful"


def test_windows_ping_timeout():
    r = parsers.parse_ping_windows(WIN_TIMEOUT)
    assert r["transmitted"] == 4 and r["received"] == 0
    assert classify_ping(r) == "No ICMP response"


def test_windows_destination_unreachable_is_not_success():
    r = parsers.parse_ping_windows(WIN_UNREACHABLE)
    assert r["received"] == 0, "'Destination host unreachable' replies come from a gateway, not the target"
    assert r["loss_pct"] == 100.0


TRACE_LINUX = """traceroute to 10.1.57.15 (10.1.57.15), 20 hops max, 60 byte packets
 1  _gateway (192.168.88.1)  1.123 ms  1.002 ms
 2  10.0.0.1 (10.0.0.1)  2.400 ms  2.511 ms
 3  * *
 4  core-sw01.lab.local (10.1.57.1)  3.012 ms *
 5  * *
"""
TRACERT_WIN = """
Tracing route to stars.emgs.local [10.1.57.10]
over a maximum of 30 hops:

  1    <1 ms    <1 ms    <1 ms  192.168.88.1
  2     2 ms     2 ms     3 ms  core-rtr.lab.local [10.0.0.1]
  3     *        *        *     Request timed out.
  4     3 ms     3 ms     4 ms  10.1.57.10

Trace complete.
"""
TRACEPATH = """ 1?: [LOCALHOST]                      pmtu 1500
 1:  192.168.88.1                                          1.010ms
 2:  10.0.0.1                                              2.333ms
 3:  no reply
 4:  10.1.57.10                                            3.900ms reached
"""


def test_traceroute_linux_with_intermediate_and_destination_timeouts():
    hops = parsers.parse_traceroute_linux(TRACE_LINUX)
    assert [h["hop"] for h in hops] == [1, 2, 3, 4, 5]
    assert hops[0]["hostname"] == "_gateway" and hops[0]["ip"] == "192.168.88.1"
    assert hops[1]["hostname"] is None  # name equal to IP is dropped
    assert hops[2]["status"] == "timeout"
    assert hops[3]["status"] == "partial" and hops[3]["timeouts"] == 1
    assert hops[4]["status"] == "timeout"


def test_tracert_windows():
    hops = parsers.parse_tracert_windows(TRACERT_WIN)
    assert len(hops) == 4
    assert hops[0]["times_ms"] == [0.5, 0.5, 0.5]
    assert hops[1]["hostname"] == "core-rtr.lab.local" and hops[1]["ip"] == "10.0.0.1"
    assert hops[2]["status"] == "timeout"
    assert hops[3]["ip"] == "10.1.57.10"


def test_tracepath():
    hops = parsers.parse_tracepath(TRACEPATH)
    assert [h["ip"] for h in hops] == ["192.168.88.1", "10.0.0.1", None, "10.1.57.10"]


def test_arp_parsers():
    proc = ("IP address       HW type     Flags       HW address            Mask     Device\n"
            "10.1.57.1        0x1         0x2         aa:bb:cc:dd:ee:01     *        eth0\n"
            "10.1.57.9        0x1         0x0         00:00:00:00:00:00     *        eth0\n")
    assert parsers.parse_proc_net_arp(proc) == {"10.1.57.1": "aa:bb:cc:dd:ee:01"}
    neigh = ("10.1.57.10 dev eth0 lladdr AA:BB:CC:DD:EE:10 REACHABLE\n"
             "10.1.57.11 dev eth0  FAILED\n")
    assert parsers.parse_ip_neigh(neigh) == {"10.1.57.10": "aa:bb:cc:dd:ee:10"}
    win = ("Interface: 10.1.57.200 --- 0xb\n  Internet Address      Physical Address      Type\n"
           "  10.1.57.1             aa-bb-cc-dd-ee-01     dynamic\n"
           "  10.1.57.255           ff-ff-ff-ff-ff-ff     static\n")
    assert parsers.parse_arp_windows(win) == {"10.1.57.1": "aa:bb:cc:dd:ee:01"}


def test_nmap_xml():
    xml = """<?xml version="1.0"?><nmaprun><host><status state="up" reason="arp-response"/>
    <address addr="10.1.57.10" addrtype="ipv4"/><address addr="AA:BB:CC:00:00:01" addrtype="mac" vendor="Dell"/>
    <ports><port protocol="tcp" portid="443"><state state="open"/></port></ports>
    <os><osmatch name="Linux 5.X" accuracy="96"/></os></host>
    <host><status state="down"/><address addr="10.1.57.11" addrtype="ipv4"/></host></nmaprun>"""
    r = parse_nmap_xml(xml)
    assert list(r) == ["10.1.57.10"]
    assert r["10.1.57.10"]["mac"] == "aa:bb:cc:00:00:01"
    assert r["10.1.57.10"]["ports"] == [{"port": 443, "state": "open"}]
    assert "96%" in r["10.1.57.10"]["os_guess"]
