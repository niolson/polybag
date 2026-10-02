<#
.SYNOPSIS
    Pre-trusts the PolyBag QZ Tray signing certificate on a Windows workstation.

.DESCRIPTION
    Makes QZ Tray silently trust requests signed by PolyBag, removing the
    "Allow / Block" prompt that otherwise appears the first time each browser
    session talks to QZ Tray.

    Mechanism: downloads PolyBag's signing certificate into the QZ Tray install
    directory and sets "authcert.override=<cert>" in qz-tray.properties, which
    makes it a trusted signing anchor system-wide. Applied on QZ Tray restart.

    Must be run as Administrator (writes under Program Files).

.PARAMETER Url
    The PolyBag site base URL, e.g. https://acme.polybag.app
    The signing certificate is fetched from <Url>/qz-certificate.pem

.PARAMETER QzDir
    QZ Tray install directory. Defaults to "C:\Program Files\QZ Tray".

.EXAMPLE
    .\install-qz-cert.ps1 -Url https://acme.polybag.app
#>

[CmdletBinding()]
param(
    # Defaults to the placeholder below; the app bakes the real URL in when the
    # script is downloaded from Device Settings. Pass -Url to override.
    [string]$Url = "__POLYBAG_URL__",

    [string]$QzDir = "C:\Program Files\QZ Tray",

    # Skip TLS validation when downloading the certificate. Only for local testing
    # against a self-signed site (e.g. a Valet/.test dev site). Never use in production.
    [switch]$Insecure
)

$ErrorActionPreference = "Stop"

function Write-Info { param($m) Write-Host "[INFO]  $m" -ForegroundColor Cyan }
function Write-Ok   { param($m) Write-Host "[OK]    $m" -ForegroundColor Green }
function Write-Err  { param($m) Write-Host "[ERROR] $m" -ForegroundColor Red }

# Validate by shape, not by the placeholder literal: the app bakes the URL by
# replacing every copy of the placeholder token in this file, so a guard comparing
# against that token would itself be rewritten and always match.
if ($Url -notmatch '^https?://') {
    Write-Err "No PolyBag URL set. Run with -Url https://your-site.example.com"
    Write-Err "(or download this script from the app's Device Settings page)."
    exit 1
}

# --- Pre-flight ---------------------------------------------------------------

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Err "Must be run as Administrator (writes under Program Files)."
    Write-Err "Right-click PowerShell -> Run as administrator, then re-run."
    exit 1
}

if (-not (Test-Path $QzDir)) {
    Write-Err "QZ Tray not found at '$QzDir'. Install QZ Tray first (https://qz.io/download/)"
    Write-Err "or pass -QzDir with the correct path."
    exit 1
}

$certUrl = "$($Url.TrimEnd('/'))/qz-certificate.pem"
$certPath = Join-Path $QzDir "polybag-qz.crt"

# --- Fetch certificate --------------------------------------------------------

Write-Info "Downloading signing certificate from $certUrl"
if ($Insecure) {
    Write-Info "TLS validation disabled (-Insecure) - local testing only."
    # PowerShell 5.1's Invoke-WebRequest is unreliable against self-signed /
    # private-CA certs (e.g. a Valet .test site) even with a validation callback;
    # curl.exe (ships with Windows 10 1803+) handles them reliably.
    if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
        Write-Err "curl.exe not found; it is required for -Insecure downloads."
        exit 1
    }
    $curlOut = & curl.exe -fsSL --insecure $certUrl -o $certPath 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Err "Failed to download certificate: curl exited ${LASTEXITCODE}: $curlOut"
        exit 1
    }
} else {
    # Enable TLS 1.2 (+1.3 where available); older PS 5.1 defaults can be too old.
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls13 } catch {}
    try {
        Invoke-WebRequest -Uri $certUrl -OutFile $certPath -UseBasicParsing
    } catch {
        Write-Err "Failed to download certificate: $($_.Exception.Message)"
        exit 1
    }
}

if ((Get-Content $certPath -Raw) -notmatch "BEGIN CERTIFICATE") {
    Write-Err "Downloaded file is not a PEM certificate. Check the URL: $certUrl"
    exit 1
}
Write-Ok "Certificate saved to $certPath"

# --- Trust as a signing anchor (authcert.override) ----------------------------

# authcert.override makes our self-signed certificate a trusted signing anchor in
# qz-tray.properties, suppressing QZ Tray's Allow/Block prompt system-wide. (An
# allowed.dat "allowed" entry alone does not, on current QZ versions.) Read at
# QZ Tray startup, so the restart below applies it.
#
# Java .properties escaping for the Windows path: backslashes doubled and the colon
# escaped, e.g. C\:\\Program Files\\QZ Tray\\polybag-qz.crt. This is exactly how
# java.util.Properties serializes a path, and what QZ Tray's parser expects.
$overridePath = ($certPath -replace '\\', '\\') -replace ':', '\:'
$propsPath = Join-Path $QzDir "qz-tray.properties"

$props = @()
if (Test-Path $propsPath) {
    $props = @(Get-Content $propsPath | Where-Object { $_ -notmatch '^\s*authcert\.override\s*=' })
}
$props += "authcert.override=$overridePath"

# UTF-8 without BOM: a BOM would corrupt the first property line.
[System.IO.File]::WriteAllLines($propsPath, [string[]]$props, (New-Object System.Text.UTF8Encoding($false)))
Write-Ok "Set authcert.override in $propsPath"

# --- Restart QZ Tray (applies authcert.override) ------------------------------

Write-Info "Restarting QZ Tray to apply trust..."
Get-Process -Name "qz-tray" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

$exe = Join-Path $QzDir "qz-tray.exe"
if (Test-Path $exe) {
    Start-Process $exe
    Write-Ok "QZ Tray restarted."
} else {
    Write-Info "qz-tray.exe not found at '$exe'. Start QZ Tray manually to apply trust."
}

Write-Host ""
Write-Ok "Done. PolyBag is now a trusted signer for this workstation (all users)."
Write-Info "Restart your browser to apply. If the prompt still appears, restart the workstation."
