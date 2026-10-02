# Security Policy

PolyBag handles carrier API credentials, OAuth secrets, and real shipping addresses.
We take reports about any of that seriously and we would much rather hear from you
privately than read about it in a public issue.

## Reporting a vulnerability

**Do not open a public GitHub issue for a security problem.**

Two private channels, either is fine:

1. **GitHub private vulnerability reporting** — [open a private
   advisory](https://github.com/niolson/polybag/security/advisories/new). This is
   enabled on the repository, keeps the discussion attached to the code, and lets us
   credit you and issue a CVE if one is warranted.
2. **Email** — `security@polybag.app`. Use this if you would rather not report through
   GitHub, or if the finding concerns the hosted service rather than the code.

Please include enough for us to reproduce it: affected version or commit, deployment
mode (standalone / shared / external), the request or steps that trigger it, and what
an attacker gets out of it. A proof of concept helps a great deal. If you have already
written the fix, say so — we will work through it with you rather than around you.

## What to expect

| Stage | Target |
| --- | --- |
| Acknowledgment that a human has read it | 5 business days |
| Initial assessment — severity, whether we can reproduce it | 10 business days |
| Fix or a dated plan for one | 90 days from acknowledgment |

This is a small project. Those are the numbers we believe we can actually meet, not
aspirational ones. If a deadline slips we will tell you rather than go quiet.

We ask that you give us those 90 days before disclosing publicly. If you intend to
disclose on a different timeline, tell us up front and we will work to it.

We do not run a paid bug bounty. We will credit you in the advisory and the release
notes unless you would rather stay anonymous.

## Scope

**In scope**

- Anything in this repository — the Laravel application, the carrier adapters, the
  import and export sources, the Docker images and `docker/entrypoint.sh`, the
  provisioning and install scripts, and the QZ Tray signing endpoint (`POST /qz/sign`).
- The hosted service at `*.polybag.app`. **Report it through the same two channels
  above.** Test only against an account you control, and stop at the point where you
  have demonstrated the issue — do not pivot, do not access another tenant's data, and
  do not run automated scanners or load tests against it.

**Out of scope**

- QZ Tray itself, and the workstation it runs on. Report those to
  [QZ Industries](https://qz.io/).
- Carrier APIs (USPS, FedEx, UPS), Shopify, and Amazon SP-API. Report those to the
  vendor. We do want to hear about it if *our* handling of their credentials or
  responses is the weak point.
- Known dependency advisories with no demonstrated impact on PolyBag. Those are already
  watched by Dependabot and the `security-audit` CI workflow.
- Scanner output with no working proof of concept, missing hardening headers on
  endpoints that carry nothing sensitive, and self-XSS.
- Social engineering, physical access, and denial of service.

## Supported versions

There are no tagged releases yet. Only the current `main` branch is supported — fixes
land there, and self-hosted deployments should track it. If you are running an older
commit, please confirm the issue still reproduces on `main` before reporting.

## A note on the license

PolyBag is **source-available, not open source**. It is licensed under the
[Business Source License 1.1](LICENSE) — you may read, modify, and run it for personal,
educational, internal evaluation, and development use, but not for a production
commercial purpose. The license converts to Apache-2.0 on March 11, 2030.

This does not restrict security research in any way, and we will not use the license as
grounds for a complaint against anyone reporting in good faith under this policy. We
mention it only so you know what you are looking at. For commercial terms, contact
`license@polybag.app`.
