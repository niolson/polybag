# Cross-Carrier Special Services: Restrictions & Availability APIs

> Builds on `polybag-special-services-report.md` (the service catalog / summary matrix). This doc adds: carrier-service-level restrictions, geographic restrictions, other hard constraints, and — critically — how to check whether a special service is actually available *before* buying a label, per carrier. Sources: `{carrier}-api-reference.md`, `ups-Rating.yaml`, `usps-shipping-options_13.yaml`, `fedex-service-availability.json`, plus live sandbox probes run against this app's configured sandbox accounts (2026-07-06).
>
> **Purpose:** input to designing the special-services data model (carrier-service scope, country scope, precheck mechanism). Not a design doc itself.

---

## 0. Sandbox empirical findings (read first — these correct/extend the written docs)

| Carrier | What we tested | Result |
| --- | --- | --- |
| **UPS** | Forced `SaturdayDeliveryIndicator` on a genuinely ineligible day/service (Monday ship, Next Day Air), bypassing our own adapter's preemptive day-map guess | **HTTP 400, error code `111562`: "No matching Rate and Transit times available."** — a fully generic message, no mention of "saturday" anywhere in the payload. |
| **FedEx** | Forced `SATURDAY_DELIVERY` on a genuinely ineligible day/service (Monday ship, PRIORITY_OVERNIGHT) via the Rate API | **HTTP 200** with alert `"VIRTUAL.RESPONSE" / "This is a Virtual Response"` and a canned quote dated 2023-08-02, returning rates for services never requested (e.g. `FEDEX_GROUND`). **FedEx's sandbox does not enforce real eligibility rules and cannot be used to validate this kind of logic.** |
| **USPS** | Sent `extraServices: [999999]` (nonexistent code) to the Labels v3 API | Schema validation rejects it immediately and **the error payload includes the complete valid-code enum**: `365, 415, 480–489, 810–832, 857, 910–913, 920–925, 930–931, 934, 955, 957, 981, 986, 991` |
| **USPS** | Sent `mailClass: USPS_GROUND_ADVANTAGE` + `extraServices: [910]` (Certified Mail — invalid for that class) | **HTTP 400**, structured error: `code: "160012"`, `detail: "mailClass: USPS_GROUND_ADVANTAGE with extraServices: [910] is not a valid combination. Please reference the Service Type Code List..."`, `source.parameter: "packageDescription.mailClass, packageDescription.extraServices"`. Single request, no ambiguity about which field is at fault. |
| **USPS** | Sent the *same* `USPS_GROUND_ADVANTAGE` + Certified Mail (910) combo to the **rating** endpoint (`/options/search`) instead of the label-purchase endpoint | **HTTP 200, no warnings** — the rating endpoint happily priced Certified Mail ($5.30) against `USPS_GROUND_ADVANTAGE` and returned it as a normal `extraServices` line item. **The rating endpoint gives a false positive for a combination the label-purchase endpoint rejects outright.** This directly answers open question #1: the rating endpoint's `extraServices` field cannot be trusted as a precheck — it doesn't enforce the same mail-class compatibility rules as Labels v3. |
| **FedEx (production)** | Called `POST /availability/v1/specialserviceoptions` against **production** (not sandbox — see note below) for `PRIORITY_OVERNIGHT`, checking `SATURDAY_DELIVERY` presence on a Friday ship date vs. a Monday ship date | Friday: `SATURDAY_DELIVERY` **present** in `shipmentSpecialServicesList`. Monday: **absent**. Matches our hardcoded day-map exactly (`FedexAdapter::SATURDAY_DELIVERY_DAY_MAP`). Confirms the availability API works as documented against real data, unlike the Rate API sandbox. |

**How the FedEx production probe was done safely:** sandbox mode is a single global app setting (`SettingsService::get('sandbox_mode')`) shared by USPS, UPS, and FedEx — there's no per-carrier override for this account (it has no `child_key`, so `FedexConnector::resolveBaseUrl()` falls back to the global flag). We flipped it to `false`, made only the two read-only availability calls above (no Rate, no Ship/Label calls), and flipped it back in a `finally` block — confirmed reverted to `true` immediately after. No label was purchased and no other carrier code path ran during the ~1-second window.

**Implications for the retry-logic redesign:**
- UPS and FedEx errors are *not* reliably string-matchable per-service. UPS's real rejection is a generic "no rate available" — the current `UpsAdapter.php` retry (`str_contains($errorJson, 'saturday')`) would **not fire** on this exact error, meaning a real Saturday-ineligible label request could fail outright with no retry today.
- FedEx's *Rate* sandbox cannot be used to verify eligibility-guessing logic — but its dedicated Service Availability API, tested against production, is reliable and matches our existing day-map logic exactly.
- USPS is the one carrier whose *label-purchase* API gives a genuinely parseable, per-field error today — a resolver keyed off `source.parameter` (not string search) would work reliably for USPS. Its *rating* API is not a safe substitute for this — it doesn't enforce the same rules.

---

## 0.1 Implementation-verification probes (2026-07-09, run after the Wave 1–3 build)

| Carrier | What we tested | Result |
| --- | --- | --- |
| **USPS (sandbox)** | Where Labels v3 actually reads `packageValue` / `physicalSignatureRequired` — probed 4 placements with `extraServices: [930]`, which errors (`160017`) whenever `packageValue` isn't seen | **Both fields live in `packageDescription.packageOptions`.** In `packageDescription` directly, at the request top level, or in a top-level `packageOptions`, `packageValue` is silently unread (930 kept demanding it). Probe with `packageDescription.packageOptions.packageValue` bought a label with Insurance ≤ $500 priced $4.40. The written API reference never states the parent object — this was only discoverable empirically. |
| **USPS (sandbox)** | Is `physicalSignatureRequired` really *required* with 931 (docs say yes)? | **No** — a 931 label purchased fine without it. Accepted (or harmlessly ignored) in both `packageDescription` and `packageOptions`; we send it in `packageOptions` alongside `packageValue`. |
| **USPS (sandbox)** | Full adapter-path labels via `UspsAdapter::createShipment()` after the fix: 922+931 (adult signature + insurance $750) and 820 standalone battery with `contentType: HAZMAT` | **Both purchased and voided successfully.** Extra services priced as line items (Adult Signature $9.70, Insurance > $500 $11.95, Signature Confirmation $3.95 in an earlier probe). `contentType: HAZMAT` in `packageDescription` accepted with 820. |
| **USPS (sandbox)** | Rating endpoint `packageValue` placement (`/options/search`) | Reads `packageDescription.packageValue` **directly** (unlike the label APIs) and prices insurance value-sensitively: $4.40 at $200, $59.95 at $4,000. So rating and label placement differ — both now implemented per their respective shapes. |
| **FedEx (production, availability API)** | `POST /availability/v1/specialserviceoptions` for a US→US lane (98072→20770, YOUR_PACKAGING, 5 lb) — same brief `sandbox_mode` flip as the 2026-07-06 probe, one read-only call, restored in `finally` | Full response saved to `fedex-availability-production-response-2026-07-09.json`. Key findings below. |

FedEx availability findings (per-service):

- **Signature options confirmed**: every service returns `signatureOptionsList` including `DIRECT` and `ADULT` for the US lane — the enums our adapter sends.
- **Express battery confirmed exactly**: Express services list `specialServiceType: BATTERY` ("Lithium Batteries", code 93) with a `batteryOptionList` enumerating precisely `batteryMaterialType` (LITHIUM_ION/LITHIUM_METAL) × `batteryPackingType` (CONTAINED_IN_EQUIPMENT/PACKED_WITH_EQUIPMENT), all `batteryRegulatoryType: IATA_SECTION_II`. Our `batteryDetails` payload (`LITHIUM_ION` + `CONTAINED_IN_EQUIPMENT`) matches the "Ion Contained in Equipment (UN3481, PI967)" option verbatim.
- **Ground battery is NOT the BATTERY special service**: on FEDEX_GROUND / GROUND_HOME_DELIVERY, batteries appear only as a `DANGEROUS_GOODS` subtype (`subType: BATTERY`, code A5) with **no** `batteryOptionList`. The BATTERY + batteryDetails shape is an Express/IATA construct. → Adapter now sends battery fields on Express requests only; ground shipments carry no battery API fields (excepted batteries need package marks, not a declaration), and mixed rate requests omit them so ground rates are never poisoned.
- **`STANDALONE_BATTERY` does not exist on this lane**: zero occurrences anywhere in the response. Consistent with IATA's withdrawal of standalone Section II — standalone lithium via FedEx is full dangerous-goods territory (out of scope). → `FedexAdapter` now reports `lithium_battery_standalone` as NotImplemented and the seeder no longer scopes it to FedEx; USPS (820, Ground Advantage) is the only carrier that takes standalone batteries.
- Corroborating detail also captured: `HOLD_AT_LOCATION` present on Express + Ground, `DRY_ICE` package-level on both networks, Home Delivery Premium subtypes (APPOINTMENT/DATE_CERTAIN/EVENING) on GROUND_HOME_DELIVERY only — matches §3.1.

## 1. USPS

### 1.1 Carrier-service (mail class) restrictions — RESOLVED via the official STC list

We obtained the authoritative STC (Service Type Code) list directly (`Service_Type_Codes_Appendix_I_06242026.xlsx`, downloaded from postalpro.usps.com) and parsed it into `.scratch/special-services/usps-stc-list.csv` (346 rows: `STC, Full Description, Class of Mail, Banner Text, Extra Service Code 1-5, CS Y/N, eVS Y/N`). This is the real combination matrix the label-purchase API validates against.

**Important gotcha found in the data itself:** the STC list's `Class of Mail` column (`FC, PM, EX, PS, BB, BL, BS, SA, S2`) is **not** a clean 1:1 mapping to the API's `mailClass` enum values. `FC` is overloaded — it means classic **First-Class Mail** (letters/flats) for some STC rows and **First-Class Package Service** (→ `USPS_GROUND_ADVANTAGE`) for others, distinguishable only by reading each row's `Full Description` text, not the class code alone. A naive join on the 2-letter class code would misclassify several services. The mapping below is built from the description text, not the class column.

Per-code product/mail-class mapping (aggregated from all matching STC rows, by actual product name in the description):

| Code family | Valid for (by product name in STC description) |
| --- | --- |
| 910–911 Certified Mail | **First-Class Mail (letters) and Priority Mail only** — confirmed **not** valid for USPS Ground Advantage. This directly resolves the contradiction flagged earlier: the original report's claim ("primarily FIRST-CLASS_PACKAGE_SERVICE → Ground Advantage") was wrong; our own probe rejecting `USPS_GROUND_ADVANTAGE` + 910 was correct, and the STC data confirms it. |
| 912–913 Certified Mail Adult Signature (variants) | Priority Mail only |
| 940–941 Registered Mail | First-Class Mail, Priority Mail, **and** USPS Ground Advantage |
| 920 USPS Tracking, 921 Signature Confirmation, 924 Sig. Confirmation Restricted | Bound Printed Matter, Library Mail, Media Mail, Parcel Select, Priority Mail, USPS Ground Advantage (920 additionally covers USPS Marketing Mail) |
| 915/917 COD (+ restricted) | Bound Printed Matter, First-Class Mail, Library Mail, Media Mail, Parcel Select, Priority Mail, Priority Mail Express, USPS Ground Advantage — the broadest-applicable family |
| 930/931/934 Insurance (+ restricted) | Broadly applicable — same wide list as COD, plus USPS Marketing Mail; 934 additionally covers Priority Mail Express |
| 955 Return Receipt / 957 Return Receipt Electronic | Broad (Bound Printed Matter, First-Class Mail, Library Mail (955 only), Parcel Select, Priority Mail, Priority Mail Express, USPS Ground Advantage, USPS Marketing Mail) — **despite this broad STC validity, the Labels API separately rejects 955 entirely as of 2025-01-19** (see 1.2) |
| 981 Signature Requested, 925 Merchandise Insurance, 986 PO to Addressee | Priority Mail Express only — confirmed |
| 857 Hazmat (general) | Parcel Select, Priority Mail (+ hazmat variants), Priority Mail Express, USPS Ground Advantage — broad, as expected for a general hazmat flag |
| 452/826 Return-specific codes | Only valid on the `*Return` product variants (Priority Mail Return, Priority Mail Express Return, USPS Ground Advantage Return) — not the outbound products |
| 985 Hold for Pickup | Still present in this STC data (Bound Printed Matter, First-Class Mail, Priority Mail, Priority Mail Express, USPS Ground Advantage) despite being **absent from both the Labels v3 enum we captured and the rating spec's enum** — likely means it's valid per the STC rules but not actually exposed via the modern API surface. Worth a direct probe if we ever want to offer Hold for Pickup. |

**Action item resolved:** `.scratch/special-services/usps-stc-list.csv` is now the durable, greppable source of truth for this matrix — no need to re-derive it from prose docs.

### 1.2 Geographic restrictions (domestic vs. international)

- Separate API specs/endpoints: domestic (`labels_7_0.yaml`) vs. international (`international-labels_15.yaml`).
- **Max codes per request:** domestic 5, international 3 per the labels API docs — though the *rating* spec's schema (`usps-shipping-options_13.yaml`) sets `maxItems: 5` for both domestic and international, an inconsistency between the two specs worth flagging if we ever validate client-side.
- **International extra-service enum is a narrow subset:** Tracking Plus (480–484, 486–488 — not 485/489, which are domestic-only), Insurance (930/931 only), Hazmat (857, 813, 820, 826 only — not the full 810–832 range), Return Receipt (955, but globally unsupported). No COD, no Certified/Registered Mail, no Hold for Pickup internationally.
- The **rating endpoint's** international enum is narrower still (`370, 813, 820, 826, 857, 930, 931, 955` — no Tracking Plus codes at all), meaning the rating and label-purchase specs don't even agree with each other on the international list.

### 1.3 Other constraints

- General package weight cap: 70 lb (1120 oz), independent of any special service.
- Only one Tracking Plus service per package; signature-retention Tracking Plus codes require a signature service too.
- Account/API-tier gating: Registered Mail is CS-API-only, not eVS. The STC CSV's `CS Y/N` / `eVS Y/N` columns are the authoritative gate per code — not available in the reviewed files.
- No per-code weight/size limits beyond the blanket 70 lb.

### 1.4 Availability-check mechanism — RESOLVED (rating endpoint is unsafe to use for this)

**No dedicated availability endpoint, and the obvious candidate doesn't work.** We tested it directly: sent `mailClass: ALL_OUTBOUND` + `extraServices: [910]` (Certified Mail — confirmed invalid for Ground Advantage per 1.1) to `POST /options/search`. Result: **HTTP 200, no warnings, USPS_GROUND_ADVANTAGE still returned in `shippingOptions[]`, and the response priced Certified Mail as a normal $5.30 line item against it** — a silent false positive for a combination the label-purchase endpoint rejects outright with error `160012`. **The rating endpoint does not enforce the same mail-class/extraService compatibility rules as the Labels v3 endpoint — it cannot be used as a precheck.**

This leaves one real option: **the label-purchase endpoint itself (Labels v3), used as a validate-then-abort precheck.** Confirmed clean and structured from our probe (1-request rejection, `source.parameter` names the exact bad fields). This means either (a) accept that USPS special-service validation only happens at actual purchase time and design the retry/error-resolver around that (this carrier's error shape is good enough to make that tractable — see 1.5), or (b) validate client-side against `usps-stc-list.csv` before ever calling the API.

### 1.5 Error shape (for a generalized retry resolver)

Best of the three carriers for this purpose:
```json
{"error": {"code": "400", "message": "Bad Request", "errors": [
  {"title": "Bad Request", "detail": "mailClass: X with extraServices: [N] is not a valid combination...",
   "code": "160012", "source": {"parameter": "packageDescription.mailClass, packageDescription.extraServices"}}
]}}
```
A resolver can key off `source.parameter` to know exactly which special-service code(s) were the problem, and strip just those before retrying — no string search needed.

---

## 2. UPS

### 2.1 Carrier-service-code restrictions

The Rating API (`ups-Rating.yaml`) documents several accessorials as restricted to specific service families, which the current data model (pivot only at the shipping-method level) can't express:

| Accessorial | Restriction |
| --- | --- |
| Saturday Delivery (free variant) | Ground (03) / 3 Day Select (12) only get *free* Saturday when destination is flagged residential (`ResidentialAddressIndicator`) — otherwise treated as commercial |
| DeliveryConfirmation (signature) | Package-level and shipment-level use **different numeric code sets** for the same concept (package: 1/2/3, shipment: 1/2) — a real gotcha for anyone building a shared mapping |
| COD (110/500) | Package-level valid only US/PR↔US/PR, CA↔CA, CA↔US (not for Letters/Envelopes CA→US). Shipment-level COD is **EU-origin only**, and only for Daily Pickup/Drop Shipping accounts |
| AccessPointCOD | Shipment-level: EU only, requires Hold-for-Pickup-at-Access-Point indication, incompatible with return service. Package-level: US/PR↔US/PR and CA↔CA only, without return service |
| ShipperRelease (402) | US50/PR↔US50/PR only, and only without return service |
| HoldForPickupIndicator | In the **Rating API** this is modeled as **freight-only** (UPS Worldwide Express Freight / Freight Midday) — different from the Shipping API path cited in the summary report, which is general-purpose. The two APIs disagree on scope. |
| UPS Premium Care (515) | Canada↔Canada only; specific eligible UPS services (Express Early/Express/Express Saver/Standard); incompatible with signature-required accessorials and with Return Service code 3 |
| Insurance (400, BPI/DVS/EVS/TNT/EPI) | In the Rating API, this container is documented as **freight-only** — small-package insurance is via `DeclaredValue` instead |
| RestrictedArticles (alcohol/biological/perishables/plants/seeds/tobacco/e-cigs/hemp-CBD — the "205 ISC" family) | All documented as **freight-only** in the Rating API — not modeled for small-package rating at all in this spec |
| HazMat, TransportationMode=04 (Cargo Aircraft Only) | Requires the shipper account be specifically authorized for cargo-aircraft-only hazmat |

### 2.2 Geographic restrictions

- COD supported-countries table: all EU countries/territories (with exceptions), Russia (cash only), UAE (cash only). No EU country supports *package-level* COD at all — shipment-level only.
- HazMat/DryIce `RegulationSet` is inherently geographic: `ADR` = Europe↔Europe ground, `CFR` = US DOT domestic/US↔Canada ground, `IATA` = worldwide air, `TDG` = Canada↔Canada or Canada→US ground.
- UPS Premium Care: Canada↔Canada only. Return-of-Document (410): Poland↔Poland only. SED/Certificate of Origin: freight-only (inherently international).
- A master **Country/Territory Codes table** marks, per country, whether it supports forward-origin and/or return-origin shipping at all — this gates every accessorial that requires an international lane (Import Control, ISC codes, HazMat IATA/ADR) before the accessorial-specific rules even apply.

### 2.3 Other constraints

- Monetary caps: COD max $50,000; DeclaredValue max $5,000 (local) / $50,000 (remote).
- Mutual exclusivity: DeliveryConfirmation × COD (both levels); AccessPointCOD × COD; Premium Care × signature-required; several ReturnService codes excluded for Worldwide Express Freight.
- `AvailableServicesOption`, when set, **silently causes** `SaturdayDeliveryIndicator`/`SundayDeliveryIndicator` to be ignored — an interaction bug-in-waiting if both are ever set by our code at once.
- Several accessorials require account-level contract enablement, surfaced only as prose (not queryable via API): UPS Proactive Response, Refrigeration, Heavy Goods Service (Inside Delivery/Item Disposal), Ground Freight Pricing, State Department License rates, Negotiated Rates.
- HazMat/DryIce fields are only honored if the request's `SubVersion >= 1701` (Rating) / consistent version gating in the Shipping API — omitting the right subversion means the fields are **silently ignored**, not rejected. This is a real footgun: sending hazmat data with the wrong API version looks like success but the carrier never saw it.

### 2.4 Availability-check mechanism

**No dedicated availability endpoint exists for UPS**, unlike FedEx. Two partial mechanisms:

1. **`AvailableServicesOption`** (`Shop`/`Shoptimeintransit` requests only) — values `1` (weekday+Saturday), `2` (weekday+Sunday), `3` (weekday+Sat+Sun). This is Saturday/Sunday-only, not general-purpose.
2. **Request-and-observe against `Rate`/`Ratetimeintransit`:** submit a rate request with the accessorial populated for a specific service code, then inspect the response:
   - `TimeInTransit.ServiceSummary.SaturdayDelivery` — explicit `0`/`1` flag, described as the most direct "was this actually honored" signal for Saturday specifically. (Requires `Ratetimeintransit`/`Shoptimeintransit`, not plain `Rate`.)
   - `RatedShipment.ItemizedCharges[]` — itemized by accessorial `Code`, but **only populated when `SubVersion >= 1601`** — this is likely why our current adapter doesn't see per-accessorial confirmation today.
   - `RatedShipment.RatedShipmentAlert[]` / top-level `Response.Alert[]` — generic, un-enumerated `{Code, Description}` warnings; the only structured localization (`Level` H/S/P/C + `ElementIdentifier`) is documented as HazMat-specific.

**Net: for UPS, Saturday delivery is the one accessorial with a real signal (`ServiceSummary.SaturdayDelivery` 0/1) baked into a normal rate response. Everything else requires actually submitting the accessorial and watching for a generic alert or a rejected request** — there's no general precheck call to make first.

### 2.5 Error shape — RESOLVED, better than first thought

`ErrorResponse.response.errors[]: {code, message}` — both unconstrained free-form strings, no enum in the spec, and the actual documented code catalog lives at a URL not captured in our files (`developer.ups.com/api/reference/rating/error-codes`). Our first probe found one generic data point (`111562`), which looked discouraging. **A follow-up round of sandbox probes, deliberately triggering different documented restrictions, shows the codes are actually differentiated per failure type** — some genuinely name the accessorial:

| Scenario tested | Error code | Message |
| --- | --- | --- |
| Saturday delivery requested on an ineligible day/service (§0) | `111562` | "No matching Rate and Transit times available." |
| `Ratetimeintransit`/`Shoptimeintransit` request missing the required `DeliveryTimeInformation` container | `111563` | "Delivery Time Information Container is required for ratetimeintransit or shoptimeintransit request options." |
| `DeliveryConfirmation` + `COD` together on one package (documented mutually exclusive, §2.3) | `111262` | "The accessory is not valid with the selected option." |
| `ShipperReleaseIndicator` on a US→Canada package (documented US50/PR-only, §2.1) | `111538` | "Invalid Origin." |
| Package-level `COD` on a Letter/Envelope US→Canada package | `110646` | **"Package Level COD is not valid for the shipment origin and/or destination"** — names the specific accessorial by name |

#### A second cluster: fields a domestic payload never needed (2026-08-12)

Found while buying the first international UPS label, not by probing accessorials — the requests below carried no accessorials at all. Each is a field UPS requires only once the lane leaves the origin country, and they surfaced one at a time, each one masking the next:

| Scenario tested | Error code | Message |
| --- | --- | --- |
| `Rate` US→JP, origin given as `PostalCode` + `CountryCode` with no city or state | `111538` | "Invalid Origin." |
| `Rate` US→JP with no `Shipment.InvoiceLineTotal` | `111549` | "Invalid Shipment Contents Value." |
| `Rate` US→JP with no `Shipment.ShipmentTotalWeight` | `111546` | "Invalid Weight." — despite a present, valid `Package.PackageWeight` |
| `Shipment` US→JP with no `ShipTo.Phone` | `120209` | "Missing or invalid ship to phone number" |

**`111538` has at least two unrelated causes** — the accessorial violation in the table above, and a thin origin with no accessorials anywhere in the request. A `{code → accessorial}` resolver keyed on it alone would misdiagnose the second, so correlate against which fields the request actually carried before concluding anything.

`DeliveryTimeInformation` is what makes the middle two mandatory rather than optional: including it turns a rate request into a time-in-transit request, which will not resolve without knowing what is moving and how heavy it is. All four are fixed in `UpsAdapter` rather than worked around.

Takeaways: codes are stable and distinct per violation category — not a single catch-all. `110646` in particular is exactly the kind of specific signal a retry resolver needs (no string search required, just a code→accessorial lookup). `111538`/`111262` are more oblique (don't name the accessorial directly) but are still distinguishable from the generic no-rate case and from each other, so a resolver could still narrow down "which of the accessorials I requested is implicated" by correlating the error code against which accessorial-specific fields were present in that particular request. **Recommended approach for UPS:** build a small, empirically-grown map of `{error code → likely accessorial(s)}` as we encounter these in practice, rather than assuming (as the first probe suggested) that UPS gives us nothing to work with. The day-map-based preemptive guess should still be the primary mechanism for Saturday specifically (fastest, no round-trip), with error-code-based retry as the general-purpose fallback for everything else.

---

## 3. FedEx

### 3.1 Carrier-service / packaging-type restrictions

FedEx has by far the richest per-service restriction data, mostly from the "Variable Handling Fees" reference tables plus conditional-required fields in the availability-API schema:

| Special service | Restriction |
| --- | --- |
| SATURDAY_DELIVERY (US domestic) | First Overnight, Priority Overnight, 2Day only (+ International Priority / Europe Domestic internationally) |
| SATURDAY_PICKUP | FedEx Express U.S., International First, International Priority (+ Europe domestic) |
| SIGNATURE_OPTION = INDIRECT | Residential deliveries only |
| SIGNATURE_OPTION = DIRECT | U.S. addresses, and Canada **only via FedEx Ground** |
| SIGNATURE_OPTION = ADULT | U.S. addresses (Europe domestic: legal age varies by destination) |
| COD | **Structurally split by network** — Ground COD must be populated at the *package* level (`PackageSpecialServicesRequestedCodDetail`); Express COD at the *shipment* level (`CodDetail`). Mixing these up is a real implementation risk. U.S. Express COD surcharge itself restricted to Priority/Standard Overnight, 2Day A.M./2Day, Express Saver. |
| DANGEROUS_GOODS | Cannot ship in `FEDEX_10KG_BOX`/`FEDEX_25KG_BOX` (except permitted IATA Section II lithium). On Ground/Home Delivery/International Ground, only ORM-D or Limited Quantity hazmat is allowed at all — full hazmat requires the Express air network. |
| DRY_ICE / lithium battery Section II surcharge | Restricted to International Priority, International Economy, First, Priority Express/Freight (i.e., the premium/international Express tiers) |
| HOLD_AT_LOCATION | Requires a `locationId` obtained from a *separate* FedEx Location Search API — this is a real dependency: our adapter can't offer HAL without also integrating that lookup |
| APPOINTMENT/DATE_CERTAIN/EVENING (home-delivery premium detail) | **`FedEx Ground Home Delivery` only** — the schema makes the detail object's type field mandatory once selected |
| BROKER_SELECT_OPTION | International Priority / International Economy only |
| PRIORITY_ALERT / PRIORITY_ALERT_PLUS | Described as "fee-based and contract-only" — requires an account-level contract with FedEx, not something enabled per-shipment |

### 3.2 Geographic restrictions

- Signature INDIRECT: US residential only. DIRECT: US + Canada-via-Ground only. ADULT: US only (Europe domestic has separate age-of-majority-by-country logic).
- International Controlled Export Service: exports originating from **US and Puerto Rico only**.
- U.S. Inbound Processing Fee: applies to all US-inbound international shipments **except** Puerto Rico↔US and US-origin shipments.
- FedEx Ground Call Tag (return pickup): US shipments only.
- Ground/Home Delivery/International Ground hazmat: ORM-D/Limited-Quantity only (an implicit geographic constraint — full hazmat needs the Express air network instead).
- No explicit country list found tying Alcohol to specific countries — only the recipient-type (`LICENSEE`/`CONSUMER`) detail fields are documented.

### 3.3 Other constraints

- Package weight/size caps by packaging type: `YOUR_PACKAGING` Express/Ground/Home Delivery 150 lb; Ground Economy/SmartPost 70 lb; `FEDEX_ENVELOPE` 1 lb; branded boxes 20 lb; 10kg/25kg boxes 22/55 lb.
- Additional Handling / Oversize surcharge thresholds are dimension- and weight-based, independent of special services, but interact with them (e.g. dangerous goods + oversize doesn't stack surcharges — "only the respective Dangerous Goods surcharge applies").
- Several detail objects are **conditionally required**: `HoldAtLocationDetail` required if `HOLD_AT_LOCATION` selected; `ShipmentDryIceDetail` required if `DRY_ICE` selected; `AlcoholDetail.alcoholRecipientType` required for `ALCOHOL`; `HomeDeliveryPremiumDetail.homedeliveryPremiumType` mandatory once that object is used. Omitting the detail while including the bare enum code is invalid — this is a structural validation gap we'd need to enforce client-side too.
- No account-provisioning check is exposed via the availability API itself (`accountNumber` is optional on that endpoint) — the one clear exception is Priority Alert, which is contract-only and not something the API can confirm dynamically.

### 3.4 Availability-check mechanism — the good one

FedEx has a **dedicated Service Availability API**, `POST /availability/v1/specialserviceoptions` (siblings: `/transittimes`, `/packageandserviceoptions`), base URLs `https://apis-sandbox.fedex.com` / `https://apis.fedex.com`, Bearer-token auth (same OAuth flow as the Rate/Ship APIs).

- **Request:** shipper/recipient as postal code + country code, optional `serviceType` filter (omit to get all service types), and arrays of shipment-level and package-level `specialServiceTypes` to check — **multiple special services can be checked in a single call**, no need for one request per service.
- **Response:** `serviceOptionsList[]`, one entry per applicable FedEx service type, each containing `packageSpecialServicesList[]` / `shipmentSpecialServicesList[]` / `returnShipmentList[]` / `batteryOptionList[]`. **Availability is signaled by presence in these arrays, not by a boolean** — a service simply doesn't appear if it's unavailable for that service type/lane. The one true boolean is `issEnabled` (international signature options only).
- **To check "is DANGEROUS_GOODS available for GROUND_HOME_DELIVERY between A and B":** call the endpoint with shipper/recipient postal+country, then scan the `GROUND_HOME_DELIVERY` entry in `serviceOptionsList[]` for `DANGEROUS_GOODS` in `packageSpecialServicesList`.

**Resolved — reliable, but sandbox-only-safe testing doesn't work; verified against production instead.** We confirmed the FedEx *Rate* API sandbox returns canned "Virtual Response" data regardless of input, so we tested `/availability/v1/specialserviceoptions` directly against **production** (briefly flipping the app's global `sandbox_mode` setting off, making two read-only availability calls only, then reverting — see §0 for how this was done safely). Result: for `PRIORITY_OVERNIGHT`, `SATURDAY_DELIVERY` was present in `shipmentSpecialServicesList` for a Friday ship date and absent for a Monday ship date — an exact match for our existing hardcoded day-map (`FedexAdapter::SATURDAY_DELIVERY_DAY_MAP`). **The endpoint is reliable and behaves exactly as documented against real data.** This is a genuinely usable precheck source, unlike the Rate API. Note for any future testing: this endpoint must be tested against production, not sandbox, since FedEx's sandbox doesn't model real eligibility logic for the Express/Rate product family (unclear if this generalizes to all FedEx sandbox endpoints or just Rate — treat sandbox results for any FedEx endpoint with suspicion until proven otherwise).

### 3.5 Error shape

Structured `errors[]: {code, message, parameterList: [{key, value}]}` with dot-separated codes (`PACKAGE.NULLOREMPTY.REQUIRED`, `NOT.AUTHORIZED.ERROR`, etc.) — but **no example anywhere in the spec of a populated `parameterList`**, and **no documented error code for "this special service isn't available here."** FedEx's model is availability-by-omission in a 200 response, not rejection-by-error — which matches how our current adapter already treats it (string-matching a successful-looking-but-actually-failed 400 response is a fallback for when the *shipment* endpoint rejects a combination outright, which is a separate code path from the availability API entirely).

---

## 4. Cross-carrier comparison

| | USPS | UPS | FedEx |
| --- | --- | --- | --- |
| Dedicated precheck endpoint | No — rating endpoint takes `extraServices` but **confirmed to give false positives** (doesn't enforce the same rules as label purchase) | No | **Yes** — `/availability/v1/specialserviceoptions`, **confirmed reliable against production** |
| Precheck signal shape | N/A — no safe precheck exists; must validate against `usps-stc-list.csv` client-side or purchase-time-only | `ServiceSummary.SaturdayDelivery` (0/1) for Saturday only; nothing general | Presence/absence in response arrays, multi-service per call |
| Purchase-time rejection clarity | **Best** — structured, single-request, `source.parameter` names the field | **Better than first thought** — real, distinct error codes per violation type; some name the specific accessorial (see §2.5) | Structured codes, but no accessorial-specific rejection code documented (relies on precheck instead) |
| Sandbox trustworthy for eligibility testing? | Yes (schema validation is real) | Yes — confirmed real (non-canned) rejection logic across several distinct scenarios | **No** — confirmed canned/virtual responses on Rate API, Service Availability API, Ship API, and most of the rest of the catalog (see §0.1); must use production for eligibility-sensitive endpoints, and label-purchase-time behavior can't be safely tested in either environment |

### 4.1 FedEx sandbox virtualization — full picture (from FedEx's own sandbox FAQ)

The user supplied FedEx's sandbox FAQ page directly. It confirms virtualization is much broader than just the Rate API — the following are **all virtualized** (return canned responses regardless of input) as of the FAQ's last update: Rate & Transit Times API, Basic Integrated Visibility, Freight LTL API (Rate & Pickup), Address Validation API, Postal Code Validation API, Pickup Request API, **Service Availability API**, Global Trade API, Ground End of Day Close API, Open Ship API, **Ship API**, FedEx Location Search API. Virtualized responses are identifiable by an `alerts[]` entry with `code: "VIRTUAL.RESPONSE"`.

This matters a lot for what we can and can't safely test:
- Our Service Availability API probe (§0, §3.4) **had to be run against production** — sandbox would have silently returned canned data, same as Rate. Good thing we did.
- **Ship API is also virtualized in sandbox.** This means we cannot reliably sandbox-test FedEx's actual label-purchase rejection behavior for special services at all — sandbox will lie, and production actually buys a real label (real cost, real tracking number, cannot be "probed" the way a rate/availability call can). This is a **permanent testing gap** for FedEx specifically, not something we can resolve with a cleverer probe. Any retry-resolver logic for FedEx's Ship endpoint will need to be validated carefully during initial real-world rollout rather than pre-verified, or built defensively (fall back to precheck-only, skip error-based retry) since we can't rehearse the rejection path safely.
- Address/Postal Code Validation, Pickup, Location Search, Global Trade, EOD Close are also virtualized — relevant if hold-at-location (needs Location Search) or address validation logic is ever tested against FedEx sandbox.

## 5. Open questions / follow-up probes — all resolved this round

1. ~~Does USPS's rating endpoint drop/flag a mail class for an incompatible `extraServices` code?~~ **Resolved: no.** It gives a false positive — returns 200, no warnings, and prices the incompatible combination as if valid. Confirmed unsafe as a precheck (§1.4).
2. ~~Is FedEx's `/availability/v1/specialserviceoptions` reliable?~~ **Resolved: yes, against production only** — it's virtualized in sandbox like almost everything else FedEx offers (§4.1). Verified Saturday-eligibility presence/absence exactly matches our existing day-map.
3. ~~UPS's actual accessorial-specific error codes are undocumented — are they ever more specific than "no rate available"?~~ **Resolved: yes, meaningfully so.** Three new sandbox probes each produced a distinct code (see §2.5 for the full table) — most notably, package-level COD to an invalid lane returned `110646: "Package Level COD is not valid for the shipment origin and/or destination"`, which **names the specific accessorial by name**. This upgrades UPS from "no viable retry signal" to "buildable, code-keyed retry map, needs to grow empirically over time."
4. ~~The USPS STC combination matrix isn't in any file we have.~~ **Resolved.** Sourced directly from postalpro.usps.com, parsed into `.scratch/special-services/usps-stc-list.csv` (346 rows), and used to correct the mail-class matrix in §1.1 — including catching that "FC" in the `Class of Mail` column is ambiguous (First-Class Mail vs. First-Class Package Service) and must be resolved via the description text, not the column alone.
5. ~~Is USPS code 985 (Hold for Pickup) actually reachable via the modern API?~~ **Resolved: no.** Directly probed — Labels v3 rejects it as an unknown enum value, identical to the earlier `999999` nonexistent-code test. It's valid per the official STC data but not exposed via the current API surface at all. Not implementable today regardless of what the STC list says.
6. ~~Does FedEx's sandbox unreliability generalize beyond the Rate API?~~ **Resolved via FedEx's own documentation** (§4.1) — yes, broadly, including Ship API. This also means FedEx's label-purchase-time rejection behavior is **untestable in any environment** (sandbox lies, production costs money) — flagged as a standing limitation, not a probe-able open question.
