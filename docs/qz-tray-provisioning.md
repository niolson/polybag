# QZ Tray Certificate Provisioning

By default, the first time a browser session on a workstation talks to QZ Tray,
QZ Tray shows an **Allow / Block** dialog ("a website wants to access printing")
for any signer it doesn't fully trust. The install scripts pre-trust PolyBag's
signing certificate so the dialog never appears.

## How it works

PolyBag generates a self-signed **signing certificate** (`app:generate-qz-cert`):
the browser signs each QZ request with the private key, and QZ Tray verifies the
signature against the public certificate served at `<site>/qz-certificate.pem`.

To trust that certificate silently we set **`authcert.override`** in QZ Tray's
`qz-tray.properties`, which makes the certificate a trusted *signing anchor* —
full (green-lock) trust, system-wide. The scripts:

1. Download the certificate to `<install dir>/polybag-qz.crt`, and
2. Set `authcert.override=<path to that cert>` in `<install dir>/qz-tray.properties`,
   then restart QZ Tray (the property is read at startup).

> **Why not `allowed.dat`?** An `allowed.dat` entry (what the manual
> "Allow → Remember" click, or a `provision.json` `cert` entry, writes) records an
> *allowed decision* but **does not reliably suppress the prompt** on current QZ
> versions — the certificate shows in the Site Manager's Allowed list, yet the
> dialog still appears. `authcert.override` is the mechanism that actually works.

On Windows the path is written with Java `.properties` escaping — backslashes
doubled and the colon escaped, e.g.
`authcert.override=C\:\\Program Files\\QZ Tray\\polybag-qz.crt` (the exact form
`java.util.Properties` produces and QZ Tray's parser expects). The file is written
**without a BOM** — PowerShell 5.1's `Set-Content -Encoding UTF8` adds one, which
corrupts the first property line.

## Installing

QZ Tray must already be installed (https://qz.io/download/). These scripts write the
certificate + the `authcert.override` property into the QZ Tray install directory and
restart the app. No recompiled installer and no code-signing required.

### Windows — double-click installer (recommended)

From the app's **Workstation Settings → Trust Installer (Windows)**, download
`install-qz-cert.cmd` (this site's URL is baked in). Hand it to the operator:
they **double-click it and approve the admin (UAC) prompt** — no terminal, no
typing. The `.cmd` self-elevates, extracts the bundled PowerShell, and runs it.

#### About the security warnings

A `.cmd` downloaded with a browser carries Windows' **Mark-of-the-Web**, so
double-clicking shows an *"Open File – Security Warning"* (no digital signature).
Because the script self-elevates by relaunching itself as admin, that warning
appears **twice** (initial launch + elevated relaunch), followed by the UAC
"allow changes" prompt. To avoid the file warnings, **right-click the `.cmd` →
Properties → check *Unblock* → OK** before running — that strips the
Mark-of-the-Web and leaves only the UAC prompt (UAC is unavoidable: writing under
`Program Files` requires admin).

At fleet scale this is a non-issue: files pushed via GPO/Intune/Jamf are not in the
internet zone, so they carry no Mark-of-the-Web and show no warnings. Removing the
warnings for *manual* downloads entirely would require Authenticode code-signing
the `.cmd`, which we deliberately don't do (it needs a signing certificate and per-OS
signing pipeline).

### Windows — PowerShell (advanced / scripted)

```powershell
.\scripts\qz-provision\install-qz-cert.ps1 -Url https://acme.polybag.app
```

Run in an **Administrator** PowerShell. Override the install path with `-QzDir`
if QZ Tray is not in the default location. The double-click `.cmd` above wraps
exactly this script.

### macOS / Linux (run with sudo)

```bash
sudo ./scripts/qz-provision/install-qz-cert.sh https://acme.polybag.app
```

Pass the workstation's PolyBag site URL; the certificate is fetched from
`<url>/qz-certificate.pem`.

### Local testing against a self-signed dev site

When testing against a Valet/`.test` site whose TLS is a local self-signed CA, the
cert download fails TLS validation. Pass `-Insecure` (Windows) / `--insecure`
(Mac/Linux) to skip that check — **local testing only, never in production**. On
Windows this path uses `curl.exe` (bundled with Windows 10 1803+), which handles
private-CA certs more reliably than PowerShell 5.1's `Invoke-WebRequest`:

```powershell
.\install-qz-cert.ps1 -Url https://polybag.test -Insecure
```
```bash
sudo ./install-qz-cert.sh --insecure https://polybag.test
```

The workstation must also resolve the hostname (add a `hosts` entry pointing
`polybag.test` at the dev server's IP) and the browser must be able to load the
site (accept the self-signed warning, or trust the dev CA).

### Install directories

The certificate and `qz-tray.properties` are written here:

| OS | QZ Tray config directory |
|---|---|
| Windows | `C:\Program Files\QZ Tray\` |
| macOS | `/Applications/QZ Tray.app/Contents/Resources/` |
| Linux | `/opt/qz-tray/` |

## Verifying

After the script restarts QZ Tray, **restart the browser** (the QZ Tray
connection is negotiated per browser session, so an already-open browser keeps the
old untrusted state), then open the PolyBag packing or device-settings page and
confirm **no** Allow/Block dialog appears and a test print/label dispatches.

If the prompt still appears after a browser restart, **restart the workstation**
(ensures QZ Tray fully reloads `qz-tray.properties`), then retry. If it still
appears after that, see Troubleshooting.

## Scope and caveats

- **System-wide trust.** `authcert.override` lives in the install directory's
  `qz-tray.properties`, so the trust applies to every OS user on the workstation.
- **Per-domain certificate.** PolyBag's signing cert is per-domain. For a
  multi-tenant deployment, generate one wildcard certificate and reuse it across
  tenants so a workstation trusts them all. For on-prem standalone, the
  per-install cert is the one to install.
- **Cert rotation.** If the signing certificate is regenerated, re-run the script;
  it overwrites `polybag-qz.crt` and the `authcert.override` line in place.

## Troubleshooting

- **Allow/Block dialog still appears** — confirm `qz-tray.properties` has a single
  `authcert.override=` line whose path resolves to the downloaded cert (on Windows,
  Java-escaped: `C\:\\Program Files\\QZ Tray\\polybag-qz.crt`), that the cert there
  matches `<site>/qz-certificate.pem`, and that QZ Tray was actually restarted
  (the property is only read at launch).
- **"Invalid Certificate" popup** — that indicates a SHA-1-signed cert, unrelated
  to this. PolyBag pins SHA-256; regenerate with `app:generate-qz-cert`.

## Mass deployment

Both scripts are idempotent and take the site URL as their only required input,
so they drop into existing fleet tooling:

- **Windows:** GPO startup script or Intune/SCCM package, run as SYSTEM/admin.
- **macOS:** Jamf/Munki policy run as root.
- **Linux:** Ansible/config-management task.

## Alternative: compiled installer

QZ Tray also supports baking trust config into a custom-compiled installer
(`ant -Dprovision.file=provision.json nsis`). That requires the full QZ build
toolchain per OS **and** code-signing/notarizing the resulting installers, so it
is only worth it for fully offline/locked-down sites that also need to bundle the
QZ Tray download itself. For everything else, prefer the scripts above.

## References

- QZ Tray Provisioning — https://qz.io/docs/provisioning
- QZ Tray Provisioning (wiki) — https://github.com/qzind/tray/wiki/Provisioning
- QZ Tray Command Line — https://qz.io/docs/command-line
