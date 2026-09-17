# FedEx sandbox rate testing

Status: reference

Repo: `polybag`

Background for the issue in this directory. Not a work item.

## Two problems wearing one coat

Testing a FedEx **international** rate quote against the sandbox fails for two reasons
that look like one, and separating them is most of the work.

### 1. The sandbox answers most request shapes with truncated JSON

The FedEx sandbox returns unparseable, truncated responses for the majority of request
shapes. Nobody has characterised the rule. It is not known whether the trigger is payload
size, a particular field, the service codes requested, the international/domestic split,
or something else entirely — only that the example payload from FedEx's developer docs is
one request known to come back valid and complete.

### 2. So the adapter substitutes a canned domestic request

`app/Services/Carriers/FedexAdapter.php`, in `buildRateApiRequest()`, reacts to that by
discarding the caller's request whenever `sandbox_mode` is on and sending FedEx's
US-domestic docs example instead — a fixed account, fixed origin and destination postal
codes, one package at 1 lb.

That makes the sandbox usable for the domestic happy path and makes international
**structurally unreachable**: the addresses are overwritten inside PolyBag before the
request leaves the app, so no international rate request ever reaches FedEx in sandbox
mode, and the sandbox's own behaviour toward international requests has never been
observed.

FedEx's sandbox separately rewrites addresses and forces domestic services when a request
does not match one of its canned test cases. Both layers push the same direction, which
is why the two are easy to conflate. Only the first is ours to change.

## What was tried before

`FedexAdapter` carried a `getMockInternationalRates()` method, an
`INTERNATIONAL_SERVICE_CODES` constant, and two commented-out call sites that would have
intercepted sandbox international requests and returned fabricated rates — invented
prices and transit times, never sent to FedEx.

That was removed in #187 as dead code. It is recorded here because the need it was
reaching for is real, and because a fabricated rate cannot answer the question that
matters: *would FedEx accept this request?* A mock answers only whether our own parsing
works on a payload we wrote.

## The path that does not have this problem

`fedex:run-test-cases` sends fixture payloads **directly through `FedexConnector`**, so
`FedexAdapter` — and therefore the sandbox override — never runs. The payloads are FedEx's
own documented test cases, which is the thing the sandbox is built to accept.

Two international rate fixtures are already committed and already skipped:

| Fixture | Case | State |
|---|---|---|
| `resources/data/carrier-test-cases/fedex/ca/rate.json` | International Economy Rate Quote | `supported: true` since 2026-09-17; sandbox engine 503'd |
| `resources/data/carrier-test-cases/fedex/lac/rate.json` | First Overnight Rate Quote | `supported: true` since 2026-09-17; sandbox engine 503'd |

Both were skipped with *"Rate test case execution is not implemented yet."* until
2026-09-17, when `FedexTestCaseRunner` gained its rate branch and both ran verbatim —
see `issues/01` for what came back, and for the measurement that replaced the two
guesses above (the sandbox is a virtualisation layer keyed on request *shape*, and the
docs example had stopped matching it).

## Why this is measurement before implementation

Wiring the rate branch is a small change. Knowing what to do with `buildRateApiRequest()`
afterwards is not, and it depends on facts nobody has: which request shapes the sandbox
answers properly, and whether an international one is among them. The issue here is
scoped to finding that out.
