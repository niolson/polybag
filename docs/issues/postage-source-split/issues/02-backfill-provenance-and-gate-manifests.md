# Backfill provenance and gate manifests on it

Status: done — PR #159

Repo: `polybag`

## Problem

Rewriting Shopify-bought packages so `carrier` names the real carrier is what makes the
model correct — and it is also what makes them eligible for a USPS SCAN form they must
never enter. `ManifestService::getUnmanifestedPackages()` groups by `packages.carrier` and
`EndOfDay` filters the same way, so today a Shopify label is excluded only because its
carrier column reads `"Shopify"`. Correct behavior, wrong reason.

**The backfill and the manifest filter must land in the same commit.** ADR-0002's migration
hazard section exists for this; separating them opens a window where we would manifest
labels we did not buy and cannot manifest.

## Update — 2026-09-02, after `01` merged

Confirmed with the maintainer: **no Shopify Shipping label has ever been bought, in dev or
in any production tenant.** The Shopify account is still unverified. Three consequences,
and they reshape this slice.

**1. The Shopify branch of the backfill is dead code.** `01` is merged, so every new
purchase records its own provenance inside `markShipped()`. A row needing the Shopify
rewrite could only have been created *before* `01` merged (`3496ee6`), and none exist
anywhere. Do not build the `metadata.shopify_tracking_company` → `carrier` rewrite. Replace
it with a guard: if the backfill finds any pre-`01` row that looks Shopify-bought
(`carrier = 'Shopify'`, or `metadata.shopify_shipping_label_id` present), it must **fail
loudly** rather than quietly guessing at it. Expected matches: zero.

**2. Backfill pre-`01` rows to `carrier_account`, not `legacy_unknown`** — and this one is
a correctness fix, not a simplification. `ManifestService::getUnmanifestedPackages()` has
no date bound: it returns *every* shipped, unmanifested package with a tracking number. Pair
`legacy_unknown` with a `postage_source = carrier_account` filter and every historical
package drops off End of Day the moment the migration runs, including ones shipped that
morning. Since no non-direct label has ever existed, every pre-`01` row *is* a direct
purchase, and `carrier_account` with a null `carrier_account_id` is exactly the state `01`'s
guard already permits. That is recording what we know rather than less than we know.

`legacy_unknown` has been **removed from the enum** (PR #158): with nothing ever writing it,
it named a state no package can be in, and it was never behaviorally distinct from
`carrier_account` anyway. If the guard in (1) ever fires, a human resolves that row to a real
provenance rather than parking it in a placeholder.

**3. `isShopifyShipped()` has to move in this commit too.** It still reads
`carrier === 'Shopify'` (`app/Models/Package.php`), and it gates the void button plus six UI
strings across `PackageResource` and `ViewPackage/`. Anything that rewrites `carrier` breaks
it silently. It should key on `postage_source = postage_data_source` instead. ADR-0002's
migration-hazard note only calls out manifests; this is the same trap one step over.

## What to build

Backfill, plus the postage-source filter on both manifest paths.

- Packages shipped before `01` → `carrier_account`, both pointers left as they are
  (see update item 2 — the slice originally said `legacy_unknown`, which no longer exists)
- Any pre-`01` row that looks Shopify-bought → fail the backfill loudly (update item 1)
- `ManifestService::getUnmanifestedPackages()` and `EndOfDay` restrict to
  `postage_source = carrier_account`
- `Package::isShopifyShipped()` keys on postage source, not carrier name (update item 3)

Note `packages.service` currently holds `trackingCompany`, which is a **carrier** value, not
a service (`ShopifyAdapter::createShipment()`). Nothing here should propagate that mistake
into the service column — ADR-0003 decision 5 owns fixing it.

## Follow-up — test cross-account USPS SCAN forms

**Tracked as of 2026-09-04 in `shopify-shipping-carrier/01`, question 10; moved 2026-09-08
to `shopify-shipping-carrier/17`, which keeps the number.** It was pointed at `01` when
written but never recorded there, so it lived only in this closed issue; `01` then split out
the questions needing a real store shipping a real parcel, and this is one of them. The
provenance gate below stays until that question is answered.

It is not yet clear whether USPS SCAN Forms v3 can include a USPS label bought through
Shopify Shipping (under Shopify's USPS account) when PolyBag creates the form using a
different USPS account. The USPS "Label Shipment" request accepts explicit tracking
numbers and does not document a payment-account field, but the public USPS documentation
does not clearly state whether cross-account or cross-MID tracking numbers are accepted.

EasyPost documents a stricter rule for its own ScanForm API: all shipments on a ScanForm
must belong to the same carrier account, and shipments from different carrier accounts
require separate forms:
https://docs.easypost.com/docs/scan-form. This may reflect a USPS constraint or an EasyPost
platform constraint; it is useful evidence, but not a definitive answer for direct USPS
SCAN Forms v3 requests.

Once a real Shopify Shipping USPS label can be purchased, run a controlled production
test before relaxing this issue's provenance gate:

- Do not add the label to a Shopify manifest first.
- Submit only that tracking number through PolyBag's existing USPS SCAN request, using the
  exact label ship date and origin ZIP.
- Record the complete USPS status and response body and verify whether the returned form
  includes the tracking number.
- Regardless of whether the request succeeds, ask USPS API support whether this use is
  officially supported. Specifically: may a SCAN Forms v3 "Label Shipment" request include
  an IMpb created by a third-party PC Postage provider under that provider's MID when the
  authenticated API customer is the physical mailer but is not the label-owner MID?

Until that test or USPS confirmation resolves the question, keep Shopify-bought postage
excluded from PolyBag-generated manifests.

## What `01` actually shipped

Merged as PR #155. Worth knowing before reading the code:

- `App\Enums\PostageSource` — `carrier_account`, `postage_data_source` (two values; see PR #158)
- `Package::markShipped(ShipResponse $response, PostageSource $postageSource, ?int $shippedByUserId = null)`,
  with the consistency guard running before the transaction
- `carrier_account` permits a null `carrier_account_id` (adapters write `$account?->id`, and
  `resolveForShipment()` can resolve nothing); `postage_data_source` requires its pointer
- `ShipResponse` carries `postageSource` + `postageDataSourceId`
- `PackageFactory::shipped()`, `DemoReset` and both FedEx test-case runners already record
  `carrier_account`, so existing manifest tests have valid provenance and should pass unchanged

## Acceptance criteria

- [x] Backfill is idempotent and re-runnable, and only touches rows where `postage_source` is null
- [x] No package ends with a discriminator that disagrees with its pointers
- [x] A package shipped before `01` stays eligible for its SCAN form — it is not silently
      dropped from End of Day by the new filter
- [x] A pre-`01` row that looks Shopify-bought fails the backfill loudly; a test proves it
- [x] A Shopify-bought USPS package (constructed as a fixture — none exist) has
      `carrier = 'USPS'` and is **excluded** from the USPS SCAN form, by postage source
      rather than by carrier name
- [x] `isShopifyShipped()` is true for a package whose carrier is `USPS` and whose postage
      source is a Shopify data source
- [x] Existing `ManifestServiceTest`, `EndOfDayTest` and `EndOfDayManifestTest` pass unchanged
- [x] A new test asserts the exclusion holds for a package whose carrier *is* USPS

## Blocked by

- `01-record-postage-source-provenance` — merged 2026-09-02 (PR #155)
