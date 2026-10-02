# nginx caches the app upstream address forever

Status: reference — framing for the issues in this directory

## Why this exists

`docker/nginx.conf` points PHP requests at the app container by name:

```nginx
location ~ \.php$ {
    fastcgi_pass app:9000;
```

A literal hostname there is resolved **once, when nginx parses its config**, and the
result is held for the life of the worker process. nginx never looks it up again. So the
moment the `app` container gets a different IP, nginx keeps dialling the address app used
to have and returns 502 to every dynamic request — indefinitely, until something restarts
nginx.

Nothing about that is exotic. Recreating `app` while leaving nginx running is what an
ordinary deploy does, because Compose only recreates containers whose own image or config
changed. Rebuilding the app image changes `app`; it does not change nginx.

This has happened in production: an app container was recreated onto a new address, nginx
was left running, and every dynamic request 502'd for 39 minutes with
`connect() failed (111: Connection refused) … upstream: "fastcgi://<old address>:9000"`
in the nginx log. The deploy that caused it reported success, because it waited only on
the app container's health check — which was genuinely healthy.

## Why it belongs in this repo

The config file is baked into the nginx image stage and ships to **on-prem standalone
installs** exactly as it ships to a hosted deployment. Restarting nginx on every deploy
works around it for whoever controls the deploy, but it does not help a self-hoster, it
does not help a manual `docker compose up -d app`, and it leaves a 502 window inside every
deploy. The fix is a one-line config change here.

## Slices

1. `issues/01-resolve-fastcgi-upstream-at-runtime.md` — resolve the upstream at request
   time via Docker's embedded DNS resolver. **`done`** 2026-09-06.

The deploy-side follow-on — whether hosted deploys should still restart nginx once this
lands — is tracked privately with that deployment tooling, since it concerns no one
running PolyBag themselves.
