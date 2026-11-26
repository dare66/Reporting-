# User guide

## Roles

* **Viewer**: dashboard, history, inventory, reports (read-only).
* **Operator**: plus diagnostics, discovery, inventory changes, reports, API tokens.
* **Admin**: plus settings, approved targets, users, AI configuration, audit log, backup/restore.

## Reading results correctly

* **Confirmed** items are proven by the measurement. **Possible causes** are hypotheses. **Recommended next steps** tell you how to confirm or rule them out.
* A **failed ping does not mean a server is down**: ICMP is often blocked. Check a TCP port the server should offer.
* **Closed** port = the host answered with a reset (it is up; nothing is listening). **Filtered / timed out** = no answer (firewall, host down or no route; the tool cannot tell which).
* **Traceroute**: hops showing `*` are normal when the destination is still reached.
* Results marked **Simulated** come from the built-in lab, not from EMGS.

## Assistant examples

| You type | What happens |
|---|---|
| Ping 10.1.57.10 | ICMP test with 4 packets |
| Run tracert to 10.1.57.10 | route trace |
| Check whether port 3306 is accessible on 10.1.57.15 | TCP connect test |
| Check port 443. | asks which server, then runs |
| Check DNS for emgs.com.my / check MX records for emgs.com.my | DNS query |
| Check SSL expiry for visa.emgs.com.my | certificate check |
| Test connectivity to Visa Website | looks up the Visa servers in the inventory and runs HTTPS checks |
| Discover all active servers in 10.1.57.xx | prepares a discovery plan; you confirm before it runs |
| Compare 10.1.57.10 and 10.1.57.15 | ping and approved ports on both, side by side |
| Explain why STARS is not accessible | tests every active STARS server and explains |
| Generate an infrastructure connectivity report | consolidated PDF |

## Discovery

1. Enter a range: `10.1.57.10`, `10.1.57.xx` (choose the final-octet range), `10.1.57.0/24` or `10.1.57.1-10.1.57.254`.
2. Choose methods and approved ports, then **Review plan**. The plan shows the address count before anything is sent.
3. **Start discovery** and confirm. Pause, resume or cancel at any time; partial results are kept when you cancel.
4. Review the **inventory comparison**:

| Classification | Meaning |
|---|---|
| Registered and Active | IP and hostname match an inventory record |
| Registered but Not Responding | in the inventory and in range, but no permitted method got an answer |
| Newly Discovered | not in the inventory, has a hostname |
| Unknown Device | not in the inventory, no hostname |
| Duplicate IP Conflict | several inventory records share the IP |
| Hostname Mismatch | DNS name differs from the inventory, or a registered hostname answered at another IP |
| Possible Unauthorised Asset | unregistered, never seen before, and exposing administrative ports. **It needs review; it is not a finding of wrongdoing.** |

5. **Register** legitimate devices, or mark them **Reviewed**. Download the discovery or unknown-device report.

## Reports

*Reports* page: choose the type (Ping, Traceroute, Port, DNS, SSL, Discovery, Inventory, Unknown Devices, Consolidated),
the period and an optional target, then generate a PDF or Excel file. Every report includes its reference, date and time,
requesting user, source laptop/interface, destination, methodology, raw results, assessment and recommendations. From
*History*, filter by type and tick rows to report on specific executions.

## Inventory

Add, edit, deactivate (preferred) or delete servers (deleting requires typing the Server ID). Use *Export Excel* as the
import template; *Validate* checks a file without writing, then *Import* applies it.
