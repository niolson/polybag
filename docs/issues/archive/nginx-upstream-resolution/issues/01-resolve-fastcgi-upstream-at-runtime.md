# Make nginx resolve the app upstream at request time

Status: done
Category: bug
Type: AFK
Repo: **`polybag`**

## Parent

`docs/issues/archive/nginx-upstream-resolution/PRD.md`

## Problem

`docker/nginx.conf:46`:

```nginx
location ~ \.php$ {
    fastcgi_pass app:9000;
```

nginx resolves a **literal** hostname in `fastcgi_pass` once at config parse
time and caches it for the life of the worker. When `app` is recreated onto a
new IP and nginx is not restarted, nginx keeps connecting to the old address and
502s every dynamic request until something restarts it. See the parent PRD for a
39-minute production instance.

Why it does not bite on a clean `docker compose up`: `docker-compose.yml` gives
nginx `depends_on: app: {condition: service_healthy}`, so on a full bring-up
nginx starts after app exists and resolves correctly. The bug needs `app` to
move *while nginx keeps running* — which is precisely what recreating or
rebuilding app alone does.

## What to fix

Defer resolution to request time, in `docker/nginx.conf`:

```nginx
location ~ \.php$ {
    resolver 127.0.0.11 valid=10s ipv6=off;
    set $upstream_app app:9000;
    fastcgi_pass $upstream_app;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
    include fastcgi_params;
    fastcgi_hide_header X-Powered-By;
}
```

`resolver` may sit at `server` level instead if that reads better; there is only
one `fastcgi_pass` in the file either way.

### Both halves are required

**Adding `resolver` on its own changes nothing.** nginx only defers resolution
to request time when the passed address *contains a variable*; with a literal
hostname it still resolves once at parse time and ignores the resolver entirely.
Equally, a variable without a `resolver` is a config error. This is the usual way
this fix is gotten wrong — a `resolver` line gets added, the config still parses,
nothing changes, and the bug looks fixed because a restart happened to clear the
cache.

### The three parameters

- **`127.0.0.11`** is Docker's embedded DNS server. It is present on every
  user-defined network, and every Compose network is user-defined, so this holds
  identically for standalone and shared modes. It is Docker-specific — see
  *Alternatives* if running this image outside Compose ever matters.
- **`valid=10s`** caps how long nginx trusts an answer regardless of the record's
  own TTL, which bounds the stale window to 10s. **Confirm what TTL Docker's
  embedded DNS actually returns for a container name** — it has historically been
  long enough to matter. Setting `valid=` explicitly means the fix does not
  depend on that answer, which is why it is here rather than omitted.
- **`ipv6=off`** stops nginx also issuing an AAAA query. These networks are IPv4
  only, so it is a wasted lookup and one more thing to fail.

### A behaviour change to accept deliberately

With a literal hostname, an unresolvable `app` makes nginx **fail to start** —
`host not found in upstream`. With the variable form nginx starts normally and
returns 502 per request instead.

This is an improvement rather than a regression: static assets and `/build/`
keep serving, the container healthcheck still fails on `/up` exactly as it does
today, and nginx cannot crash-loop because app is briefly absent. But it is a
change in failure mode, so it should be a decision rather than a surprise.

## Testing

Reproducible on a laptop; no server needed. From a standalone checkout:

```bash
c() { docker compose --profile standalone "$@"; }   # drop --profile for shared mode
ip() { docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "$(c ps -q app)"; }

c up -d --build
ip   # note the address

# Recreate app alone, leaving nginx running. This is the exact failure condition.
c up -d --force-recreate --no-deps app

# Confirm the IP actually moved. If it did not, app reclaimed its old address
# and the test proved nothing — repeat until it changes.
ip

c exec nginx curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/up
```

(The container is addressed through `compose ps -q` rather than by name: the
project name comes from the checkout directory, so it is not the same string on
an on-prem install as on the server.)

- **Before the fix:** persistent `502`, and nginx logs name the old IP in
  `upstream: "fastcgi://<old-ip>:9000"`.
- **After the fix:** `200`, within at most the `valid=` window.

Also confirm, after the change:

1. `docker compose ... up -d --build` still brings the stack up clean and `/up`
   returns 200 — the ordinary path is unaffected.
2. `docker compose ... stop app` leaves nginx **running** and serving static
   assets, returning 502 only for PHP. This is the behaviour change above; before
   the fix, an nginx started in that state would have refused to start at all.
3. `/build/` assets and the security headers still behave — the `location` block
   is being edited, so it is worth one look that nothing else in it moved.

## Blast radius

`docker/nginx.conf` is copied into the nginx image stage
(`Dockerfile:134`) and ships to **on-prem standalone installs** as well as any
hosted deployment. This is a public release affecting self-hosters and needs to go
out through the app repo's normal release path, not a quiet server-side edit.

There is no config to migrate and no data involved; the change is inert until an
image rebuild.

## Alternatives considered

- **`upstream` block with `server app:9000 resolve;`** — the `resolve` parameter
  is **NGINX Plus only**. It silently does nothing useful on open-source nginx.
  Do not spend time here.
- **Templating the resolver from `/etc/resolv.conf` at container start**, via an
  entrypoint and `envsubst`, instead of hardcoding `127.0.0.11`. More portable if
  the image ever runs outside Docker, but it adds an entrypoint and a templating
  step to a stage that currently has neither. Both deployment modes are Compose,
  so the hardcoded address is fine — recorded here so the choice is visible.
- **Leaving it, and restarting nginx on deploy.** That is what our own hosted
  deployment tooling does today. It does not help on-prem, does not help manual `docker compose up -d
  app`, and leaves a 502 window inside every deploy. The deploy-side half of this is
  tracked privately alongside that tooling.

## Comments

**Implemented 2026-09-06.** `docker/nginx.conf` now carries `resolver 127.0.0.11
valid=10s ipv6=off;` and `set $upstream_app app:9000; fastcgi_pass $upstream_app;`
inside the `location ~ \.php$` block, with the reasoning in a comment beside it so
nobody reverts it to the literal form as a tidy-up. Nothing else in the block moved.

### The `valid=` question, answered

The issue asked to confirm what TTL Docker's embedded DNS actually returns for a
container name. It is **600 seconds**:

```
app.  600  IN  A  172.18.0.2
```

So `valid=10s` is doing real work rather than restating the record: without it nginx
would honour the full ten minutes, and the stale window this issue exists to close
would still be up to ten minutes wide. An `AAAA` query returns an empty answer, which
is what `ipv6=off` avoids paying for.

### Verification

Run against the real `nginx:alpine` image with the real `docker/nginx.conf`, with a
`php:8.4-fpm-alpine` backend under the network alias `app`, both configs through the
same harness. The app container was moved to a new address by holding its old one with
a spacer container, leaving nginx running throughout.

| | before (`fastcgi_pass app:9000`) | after |
|---|---|---|
| baseline `/up` | 200 | 200 |
| `/up` after app moves `…0.2` → `…0.4` | **502**, still 502 at +12s | **200** at +1s |
| nginx error log | `upstream: "fastcgi://172.18.0.2:9000"` — the dead address | no upstream errors |
| `/build/` during the outage | 200 | 200 |
| `nginx -t` with `app` absent | `[emerg] host not found in upstream "app"` | passes |
| app stopped: nginx | would refuse to start | stays up, 502 for PHP, 200 for `/build/` |

The last two rows are the behaviour change the issue asked to accept deliberately, now
observed rather than predicted. The before-column 502 confirms the harness can actually
detect the bug — the recovery in the after-column is not a test that passes either way.

Recovery was immediate rather than taking the `valid=10s` window, since the cached entry
was already past its window by the time the next request arrived.

`docker compose config` still validates for both the standalone/on-prem and base
invocations. A full `up -d --build` of the stack was not run — the change is confined to
this one file and was exercised against the real image, and the ordinary bring-up path
is covered by the baseline row above, where nginx starts with `app` already present.

### Follow-on

The deploy-side half — whether hosted deploys should still restart nginx now that this
has landed — stays tracked privately with that tooling, as the PRD says. This change
makes the restart unnecessary rather than harmful, so there is no ordering constraint
between the two.
