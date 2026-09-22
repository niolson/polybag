# Contributing to PolyBag

Thanks for taking an interest. This document covers what you need to know before
opening a pull request: the licence position, the contributor agreement, and the local
development loop.

## Before you write any code

### This project is source-available, not open source

PolyBag is licensed under the [Business Source License 1.1](LICENSE). You may read,
modify, and run it for personal, educational, internal evaluation, and development
purposes. You may **not** use it for a production commercial purpose until the change
date, **March 11, 2030**, when the licence converts to Apache-2.0.

BSL 1.1 is not an OSI-approved licence. We say this plainly here so nobody contributes
under a misunderstanding about what they are contributing to. If you need different
terms, contact `license@polybag.app`.

Separately from the licence: the **PolyBag** name, logo, and `polybag.app` branding are
not licensed with the code, and the 2030 Apache-2.0 conversion will not change that —
Apache-2.0 grants no trademark rights either. Fork it under your own name. See the
[Licence](README.md#licence) section in the README.

### Contributions require a CLA

Because PolyBag is dual-licensed — BSL for everyone, commercial terms available from
POLYBAG.APP LLC — we need the right to sublicense contributed code under those
commercial terms and under the eventual Apache-2.0 conversion. A sign-off alone
(DCO-style) does not grant that, so we ask for a **Contributor License Agreement**.

The CLA does not take your copyright away. You keep it, and you keep the right to use
your own contribution however you like. It grants POLYBAG.APP LLC a licence broad
enough to keep shipping the project under both sets of terms.

There is no bot and no click-through yet. **Open your pull request first** — a
maintainer will email you the CLA on your first contribution, and it only needs signing
once. If you are contributing on behalf of an employer, tell us, as the agreement will
need to be signed by someone who can bind them.

### Reporting a bug or proposing a feature

Use **GitHub Issues** — [bug report](../../issues/new?template=bug_report.yml) or
[feature request](../../issues/new?template=feature_request.yml). Blank issues are off,
so the templates are the way in; they ask for the deployment mode and carrier
configuration, which is usually what determines whether we can reproduce something.

GitHub Issues is the public intake queue. Maintainer planning is recorded as Markdown
under [`docs/issues/`](docs/issues/): triage may promote a report into that tracker and
close the GitHub issue with a link. The two are deliberately not synchronized.

### Security issues do not go here

If you have found a vulnerability, **stop and read [SECURITY.md](SECURITY.md)**. Do not
open a public issue or pull request for it.

## Getting set up

Requirements: PHP 8.4, Composer, Node.js 22.12+, and MySQL 8.4. SQLite works for running
the test suite and is what CI uses, but develop against MySQL — that is what
deployments run.

```bash
git clone https://github.com/niolson/polybag.git
cd polybag
composer run setup   # install deps, copy .env, generate key, migrate, build assets
```

`composer run setup` copies `.env.example`, which is the Docker-shaped config. For
non-Docker local work, `.env.local.example` is the better starting point — no Redis, no
Docker hostnames.

Then run everything at once:

```bash
composer run dev   # serve + queue workers + scheduler + Vite, via concurrently
```

Set `FAKE_CARRIERS=true` in `.env`. It swaps in fake carrier adapters and a fake address
validator, so you can exercise the rate, label, and validation paths without carrier
credentials — and without spending money, since USPS address validation and FedEx
tracking are billed per request.

## The loop

```bash
composer run test                                # full Pest suite (clears config first)
php artisan test --compact --filter="some name"  # one test
php artisan test tests/Feature/AuthorizationTest.php

vendor/bin/pint                                  # auto-fix formatting
vendor/bin/phpstan analyse --memory-limit=1G     # static analysis, level 5
composer run format                              # rector + phpstan + pint together
```

CI gates all three. `composer run format` runs `rector process` in place, so on a branch
that has drifted it rewrites files you never touched — run it, then check `git diff`
before committing rather than after review asks you to.

Browser tests — Pest 4 browser testing, which drives Playwright under the hood. They are
**not** picked up by `php artisan test`: `phpunit.xml` defines only the `Unit` and
`Feature` suites, so `tests/Browser/` and `tests/External/` both need naming explicitly.

```bash
npx playwright install chromium   # once
php artisan test tests/Browser/
```

Browser tests register their own fake carrier adapters per test, so they need no
`FAKE_CARRIERS` setting — CI runs them straight from `.env.example`.

`tests/External/` is excluded from the default suite on purpose — those tests hit real
carrier sandboxes and need real credentials. Do not add to them unless you have an
account to run them against.

## Testing expectations

Every behavioural change needs a test. Most should be feature tests.

- Create them with `php artisan make:test --pest SomeFeatureTest` (add `--unit` for a
  unit test). Note that the name must not include the suite directory.
- `RefreshDatabase` is applied globally in [`tests/Pest.php`](tests/Pest.php) — you do
  not need to add it per file.
- Use model factories, and check for an existing state before setting fields by hand.
- Filament pages and resources are Livewire components. Test them with
  `livewire(SomePage::class)`, and call `$this->actingAs(...)` first — the panel
  requires auth.
- PHPStan runs at level 5 with a baseline in `phpstan-baseline.neon`. Fix new errors;
  do not regenerate the baseline to make them disappear.

## The pre-push hook

`composer install` points `core.hooksPath` at [`.githooks/`](.githooks), so
[`.githooks/pre-push`](.githooks/pre-push) runs automatically.

- **Rector and PHPStan block the push.** Rector runs as a dry run, so it changes
  nothing; it fails if it would rewrite a file. Both are CI gates, so a failure here
  would fail the build anyway. To fix a Rector failure, run `vendor/bin/rector process`
  and review `git diff` before committing.
- **`composer audit` and `npm audit` only warn.** They print findings and let the push
  through. An advisory published upstream this morning is not your bug, and it should
  not stand between you and a pull request on unrelated code. The `security-audit`
  workflow runs both on every push and pull request, so the gate still exists where it
  belongs — in CI, where a maintainer sees it.

To skip the hook entirely for one push:

```bash
git push --no-verify
```

## Pull requests

- Branch off `main`. Descriptive branch names, no fixed prefix — `fix-usps-token-cache`,
  `package-export-ledger`.
- Commit subjects are imperative and sentence case: "Print a package reference on carrier
  labels", not "feat: add reference printing". No Conventional Commits.
- Keep a pull request to one concern. A drive-by reformat buried in a behaviour change is
  the hardest kind of diff to review.
- Fill in the pull request template — particularly how you tested it, and whether it
  touches carrier API behaviour.
- CI must be green: Pint, PHPStan, Pest, the browser suite, the Docker build, the image
  CVE scan, Semgrep, and Hadolint.

## Things worth knowing before you change them

- **Carrier adapters** (`app/Services/Carriers/`) each talk to a live API with its own
  quirks. Changes there need a test using `FakeCarrierAdapter` or a mocked Saloon
  response, and a note in the pull request about what you verified against a real
  sandbox, if anything.
- **Hardware integration** — QZ Tray and scale orchestration lives in Blade components
  (`<x-qz-tray>`, `<x-qz-tray-script>`, `<x-printer-settings-script>`,
  `<x-scale-script>`); npm-backed QZ and barcode libraries are Vite entrypoints under
  `resources/js/`. WebHID needs a secure context, so scale work has to be tested over
  HTTPS or on localhost, in Chrome or Edge.
- **Shipping boundaries** — preparation, Label lifecycle, and purchase orchestration go
  through `PackageDraftWorkflow`, `PackageLabelWorkflow`, and `PackageShippingWorkflow`.
  Carrier policy is not postage-source dispatch: marketplace-bought Labels track and void
  through the source that bought them, not a direct carrier account.
- **Dependencies.** Please ask before adding one. This app ships as a Docker image that
  gets CVE-scanned on every build, so each new package is an ongoing cost.
- **Hosted-only services.** The OAuth broker, and the Resend domain behind transactional
  mail, are ours. [`docs/self-hosting.md`](docs/self-hosting.md) documents the
  bring-your-own-credentials path for every integration — keep it accurate if you change
  how one of them authenticates.
- **`docs/adr/`** records architectural decisions and [`CONTEXT.md`](CONTEXT.md) the
  domain language. Read the relevant one before reshaping something structural, and add
  an ADR if you are making a decision worth remembering.

## Code of Conduct

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). Report problems
to `conduct@polybag.app`.
