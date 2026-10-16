#!/bin/bash
set -euo pipefail

# Pre-trusts the PolyBag QZ Tray signing certificate on a macOS or Linux workstation,
# removing the "Allow / Block" prompt QZ Tray shows for untrusted signers.
#
# Mechanism: downloads PolyBag's signing certificate into the QZ Tray install
# directory and sets "authcert.override=<cert>" in qz-tray.properties, making it a
# trusted signing anchor. Applied on QZ Tray restart.
#
# Usage:   sudo ./install-qz-cert.sh [--insecure] <polybag-url>
# Example: sudo ./install-qz-cert.sh https://acme.polybag.app
#
# --insecure skips TLS validation when downloading the certificate. Only for local
# testing against a self-signed site (e.g. a Valet/.test dev site). Never in production.

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

CURL_TLS_OPT=""
if [[ "${1:-}" == "--insecure" ]]; then
    CURL_TLS_OPT="-k"
    shift
fi

# Defaults to the placeholder below; the app bakes the real URL in when the
# script is downloaded from Workstation Settings. Pass the URL as an argument to override.
URL="${1:-__POLYBAG_URL__}"

# Validate by shape, not by the placeholder literal: the app bakes the URL by
# replacing every copy of the placeholder token in this file, so a guard comparing
# against that token would itself be rewritten and always match.
if [[ ! "$URL" =~ ^https?:// ]]; then
    error "No PolyBag URL set. Usage: sudo $0 <polybag-url>   (e.g. https://acme.polybag.app)"
    error "(or download this script from the app's Workstation Settings page)."
    exit 1
fi

URL="${URL%/}"
CERT_URL="${URL}/qz-certificate.pem"

# --- Locate the QZ Tray install dir per OS ------------------------------------

case "$(uname -s)" in
    Darwin)
        CONFIG_DIR="/Applications/QZ Tray.app/Contents/Resources"
        APP_DIR="/Applications/QZ Tray.app"
        ;;
    Linux)
        CONFIG_DIR="/opt/qz-tray"
        APP_DIR="/opt/qz-tray"
        ;;
    *)
        error "Unsupported OS: $(uname -s). Use install-qz-cert.ps1 on Windows."
        exit 1
        ;;
esac

if [[ ! -d "$APP_DIR" ]]; then
    error "QZ Tray not found at '$APP_DIR'. Install QZ Tray first: https://qz.io/download/"
    exit 1
fi

if [[ "$(id -u)" -ne 0 ]]; then
    error "Must be run with sudo (writes into the QZ Tray install directory)."
    exit 1
fi

CERT_PATH="${CONFIG_DIR}/polybag-qz.crt"

# --- Fetch certificate --------------------------------------------------------

info "Downloading signing certificate from $CERT_URL"
if [[ -n "$CURL_TLS_OPT" ]]; then
    info "TLS validation disabled (--insecure) — local testing only."
fi
if ! curl $CURL_TLS_OPT -fsSL "$CERT_URL" -o "$CERT_PATH"; then
    error "Failed to download certificate from $CERT_URL"
    exit 1
fi

if ! grep -q "BEGIN CERTIFICATE" "$CERT_PATH"; then
    error "Downloaded file is not a PEM certificate. Check the URL: $CERT_URL"
    exit 1
fi
ok "Certificate saved to $CERT_PATH"

# --- Trust as a signing anchor (authcert.override) ----------------------------

# authcert.override makes our self-signed certificate a trusted signing anchor in
# qz-tray.properties, suppressing QZ Tray's Allow/Block prompt system-wide. (An
# allowed.dat "allowed" entry alone does not, on current QZ versions.) Read at
# QZ Tray startup, so the restart below applies it.

PROPS_PATH="${CONFIG_DIR}/qz-tray.properties"

# Drop any existing authcert.override line, then point it at our certificate.
if [[ -f "$PROPS_PATH" ]]; then
    grep -v '^[[:space:]]*authcert\.override[[:space:]]*=' "$PROPS_PATH" > "${PROPS_PATH}.tmp" || true
    mv "${PROPS_PATH}.tmp" "$PROPS_PATH"
fi
printf 'authcert.override=%s\n' "$CERT_PATH" >> "$PROPS_PATH"
ok "Set authcert.override in $PROPS_PATH"

# --- Restart QZ Tray (applies authcert.override) ------------------------------

info "Restarting QZ Tray to apply trust..."
case "$(uname -s)" in
    Darwin)
        osascript -e 'quit app "QZ Tray"' >/dev/null 2>&1 || true
        sleep 2
        open -a "QZ Tray" || info "Start QZ Tray manually to apply trust."
        ;;
    Linux)
        pkill -f qz-tray >/dev/null 2>&1 || true
        sleep 2
        if [[ -x "${APP_DIR}/qz-tray" ]]; then
            nohup "${APP_DIR}/qz-tray" >/dev/null 2>&1 &
        else
            info "Start QZ Tray manually to apply trust."
        fi
        ;;
esac

echo ""
ok "Done. PolyBag is now a trusted signer for this workstation (all users)."
info "Restart your browser to apply. If the prompt still appears, restart the workstation."
