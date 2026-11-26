# Mobile access

The phone uses the laptop's dashboard in an ordinary mobile browser (no app to install). **Every diagnostic still runs on
the laptop**, from the laptop's network connection. The API is never exposed to the public internet.

## Recommended: private overlay network (Tailscale or corporate VPN)

1. Install Tailscale (or use the corporate VPN client) on **both** the laptop and the phone, signed in to the same
   organisation-controlled tailnet. Use ACLs so only your phone can reach the laptop.
2. Find the laptop's private overlay address (Tailscale: `tailscale ip -4`, usually `100.x.y.z`).
3. In the dashboard (admin), go to *Settings > Mobile access*, enter that address and choose **Enable mobile access**.
4. Restart with `./start_netops.sh --mobile` (or `start_netops.bat --mobile`). The launcher:
   * refuses `0.0.0.0`, public addresses and addresses not assigned to this laptop;
   * generates a local HTTPS certificate for that address (`data/certs/`, valid for 1 year);
   * keeps `http://127.0.0.1:8750` for the laptop and adds `https://<address>:8751` for the phone.
5. On the phone, open `https://<address>:8751`, accept the certificate warning once (or install
   `data/certs/netops-mobile.crt` as a trusted profile), and sign in with your own account.

## Alternative: office Wi-Fi

Use the same steps with the laptop's Wi-Fi address on a trusted corporate network. Do **not** use public or hotel Wi-Fi;
use the VPN/Tailscale option instead.

## Security properties

* HTTPS only for remote access; session cookies are `Secure`, `HttpOnly`, `SameSite=Strict`.
* Every mobile session needs a normal sign-in. The audit log shows interface `mobile` and the phone's IP.
* Sessions expire (8 h by default, *Settings > Session length*); sign out from the *More* menu.
* Mobile access stays off until an admin enables it **and** the app is started with `--mobile`. It never starts silently.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "Cannot reach the NetOps laptop" | Laptop asleep, app not running, or phone not on the VPN/Tailscale/Wi-Fi. |
| Launcher: "is not assigned to this laptop" | Connect the laptop to the VPN/Tailscale first, or update the address. |
| Signed out on the phone | Session expired or was revoked (password change). Sign in again. |
| Connection drops mid-scan | The scan continues on the laptop; reopen *Discovery* to follow its progress. |
