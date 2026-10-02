# PolyBag: Cross-Carrier Special Services Report

> **Carriers covered:** USPS, UPS, FedEx  
> **Sources:** `usps-api-reference.md`, `ups-api-reference.md`, `fedex-api-reference.md`  
> **Purpose:** Identify which special service options to support in PolyBag and how to implement each per carrier.

---

## Table of Contents

- [Summary Matrix](#summary-matrix)
- [Signature / Delivery Confirmation](#signature--delivery-confirmation)
- [Adult Signature Required](#adult-signature-required)
- [Certified Mail](#certified-mail)
- [Registered Mail](#registered-mail)
- [Insurance / Declared Value](#insurance--declared-value)
- [Collect on Delivery (COD)](#collect-on-delivery-cod)
- [Saturday Delivery](#saturday-delivery)
- [Saturday Pickup](#saturday-pickup)
- [Hold at Location / Hold for Pickup](#hold-at-location--hold-for-pickup)
- [Carrier / Shipper Release](#carrier--shipper-release)
- [Return Receipt](#return-receipt)
- [Return Labels / Return Services](#return-labels--return-services)
- [Dry Ice](#dry-ice)
- [Hazardous Materials](#hazardous-materials)
- [Alcohol](#alcohol)
- [Battery / Lithium Battery](#battery--lithium-battery)
- [Residential Delivery](#residential-delivery)
- [Delivery Notifications / Event Notification](#delivery-notifications--event-notification)
- [Ancillary Service Endorsements (USPS only)](#ancillary-service-endorsements-usps-only)
- [Non-Delivery Options](#non-delivery-options)
- [Tracking Plus / Extended Tracking Retention (USPS only)](#tracking-plus--extended-tracking-retention-usps-only)
- [Priority Alert (FedEx only)](#priority-alert-fedex-only)
- [Protection from Freezing (FedEx only)](#protection-from-freezing-fedex-only)
- [Appointment / Home Delivery Options (FedEx only)](#appointment--home-delivery-options-fedex-only)
- [UPS Premier (UPS only)](#ups-premier-ups-only)
- [Import Control (UPS only)](#import-control-ups-only)

---

## Summary Matrix

| Service | USPS | UPS | FedEx |
| --- | --- | --- | --- |
| Signature Confirmation | ✅ | ✅ | ✅ |
| Adult Signature Required | ✅ | ✅ | ✅ |
| Restricted Delivery | ✅ | ✅ | ✅ |
| Certified Mail | ✅ USPS-specific | ❌ | ❌ |
| Registered Mail | ✅ USPS-specific | ❌ | ❌ |
| Insurance / Declared Value | ✅ | ✅ | ✅ |
| COD | ✅ | ✅ | ✅ |
| Saturday Delivery | ❌ | ✅ | ✅ |
| Saturday Pickup | ❌ | ✅ (intl fee) | ✅ |
| Hold at Location / Pickup | ✅ | ✅ | ✅ |
| Carrier / Shipper Release | ✅ (limited) | ✅ | ❌ |
| Return Receipt | ✅ | ❌ | ❌ |
| Return Labels / Return Services | ✅ | ✅ | ✅ |
| Dry Ice | ❌ | ✅ | ✅ |
| Hazardous Materials | ✅ | ✅ | ✅ |
| Alcohol | ❌ (domestic) | ✅ (intl only) | ✅ |
| Battery (standalone/special) | ✅ (HAZMAT codes) | ❌ explicit | ✅ |
| Residential Delivery | ✅ (implicit) | ✅ (explicit code) | ✅ (implicit) |
| Delivery Notifications | ❌ | ✅ | ✅ |
| Ancillary Service Endorsements | ✅ | ❌ | ❌ |
| Non-Delivery Option | ✅ | ❌ | ✅ (Broker Select) |
| Tracking Plus | ✅ | ❌ | ❌ |
| Priority Alert | ❌ | ❌ | ✅ |
| Protection from Freezing | ❌ | ❌ | ✅ |
| Appointment / Home Delivery | ❌ | ❌ | ✅ |
| UPS Premier | ❌ | ✅ | ❌ |
| Import Control | ❌ | ✅ | ❌ |

---

## Signature / Delivery Confirmation

Confirms delivery and may require a recipient signature.

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 920 | USPS Tracking | Basic scan confirmation, no signature |
| 921 | Signature Confirmation | Recipient must sign. Requires `physicalSignatureRequired` field. |
| 924 | Signature Confirmation Restricted Delivery | Only the named addressee may sign. Requires `physicalSignatureRequired`. |
| 981 | Signature Requested | `PRIORITY_MAIL_EXPRESS` only. Requires `physicalSignatureRequired`. |

`physicalSignatureRequired` (boolean) controls whether a wet/physical signature is required vs. allowing USPS Electronic Signature Online (eSOL). Default is `SIGNATURE_REQUIRED` (allows either) for all mail classes except PME.

### UPS

- **Accessorial code 120:** `DELIVERY CONFIRMATION`
- **Accessorial code 121:** `SHIP DELIVERY CONFIRMATION`
- Subtypes (via `DeliveryConfirmation` element): `Signature Required`, `Adult Signature Required`
- **Roadie subtype:** `Delivery Confirmation Signature Required`, `Delivery Confirmation Adult Signature Required`
- API field: `/Shipment/Package/PackageServiceOptions/DeliveryConfirmation`

### FedEx

- **Enumeration:** `SIGNATURE_OPTION` (package-level special service)
- Signature option values: `NO_SIGNATURE_REQUIRED`, `INDIRECT`, `DIRECT`, `ADULT`
- API field: `requestedShipment.requestedPackageLineItems[].specialServicesRequested.signatureOptionType`

---

## Adult Signature Required

Restricts delivery to a recipient who can prove they are 21 or older (or 18+ in some regions).

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 922 | Adult Signature Required (21 or Over) | Requires `physicalSignatureRequired` field |
| 923 | Adult Signature Restricted Delivery (21 or Over) | Named addressee only, must be 21+. Requires `physicalSignatureRequired`. |

### UPS

- Delivered via the `DeliveryConfirmation` element subtype `Adult Signature Required` (package level) or `Adult Signature Required` at shipment level.
- Accessorial subtype: `Adult Signature Required`

### FedEx

- Use `SIGNATURE_OPTION` with value `ADULT`
- API field: `requestedShipment.requestedPackageLineItems[].specialServicesRequested.signatureOptionType = ADULT`

---

## Certified Mail

A USPS-specific service that provides proof of mailing and proof of delivery. Primarily used for legal and official correspondence.

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 910 | Certified Mail | Requires `physicalSignatureRequired` |
| 911 | Certified Mail Restricted Delivery | Only named addressee may receive. Requires `physicalSignatureRequired`. |
| 912 | Certified Mail Adult Signature Required | Requires `physicalSignatureRequired` |
| 913 | Certified Mail Adult Signature Restricted Delivery | Requires `physicalSignatureRequired` |

Valid mail classes: primarily `FIRST-CLASS_PACKAGE_SERVICE` (→ Ground Advantage) and `PRIORITY_MAIL`. Commonly combined with 955 (Return Receipt) or 957 (Return Receipt Electronic).

### UPS / FedEx

No direct equivalent.

---

## Registered Mail

The most secure USPS domestic service, with chain-of-custody tracking at every handling point. Not supported via eVS (high-volume API).

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 940 | Registered Mail | CS API only (not eVS) |
| 941 | Registered Mail Restricted Delivery | CS API only (not eVS) |

Often combined with COD (915), Return Receipt (955), or Signature Confirmation (921). See STC list for valid combinations.

### UPS / FedEx

No direct equivalent.

---

## Insurance / Declared Value

Provides compensation for lost or damaged shipments up to the declared value.

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 930 | Insurance ≤ $500 | Auto-upgraded to 931 if package value > $500. Requires `packageValue` field. |
| 931 | Insurance > $500 | Requires `physicalSignatureRequired`. Requires `packageValue` field. |
| 934 | Insurance Restricted Delivery | Only named addressee may receive insured package. Requires `physicalSignatureRequired`. |
| 925 | Priority Mail Express Merchandise Insurance | `PRIORITY_MAIL_EXPRESS` only |

- `packageValue` field is required whenever code 930 or 934 is used.
- Code 930 automatically becomes 931 if value exceeds $500.

### UPS

- **Accessorial code 400:** `INSURANCE`
- Accessorial subtype: `BPI`, `DVS`, `EVS`, `TNT`, `EPI`
- API field: `/Shipment/Package/PackageServiceOptions/DeclaredValue` with `CurrencyCode` and `MonetaryValue`

### FedEx

- Declared value is not a special service type — it is specified directly on the shipment/package.
- API field: `requestedShipment.requestedPackageLineItems[].declaredValue` with `amount` and `currency`
- No separate special service enumeration needed; FedEx calculates coverage automatically.

---

## Collect on Delivery (COD)

Shipper collects payment from the recipient at the time of delivery.

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 915 | COD | |
| 917 | COD Restricted Delivery | Only named addressee may pay and receive |

Commonly combined with Signature Confirmation (921) or Return Receipt (955). See STC list for valid combinations.

### UPS

- **Accessorial code 110:** `COD` (package-level)
- **Accessorial code 500:** `SHIPMENT COD`
- API field: `/Shipment/PaymentInformation/ShipmentCharge` + `/Shipment/Package/PackageServiceOptions/COD`

### FedEx

- **Enumeration:** `COD` (both shipment-level and package-level special service)
- API field: `requestedShipment.specialServicesRequested.specialServiceTypes[] = COD`
- COD detail specified in `requestedShipment.specialServicesRequested.codDetail`

---

## Saturday Delivery

Guarantees delivery on Saturday (where available).

### USPS

USPS delivers Priority Mail Express 7 days a week by default; Saturday delivery for other classes is not a separately requestable extra service — it depends on the mail class and origin/destination.

### UPS

- **Accessorial code 300:** `SATURDAY DELIVERY`
- API field: `/Shipment/ShipmentServiceOptions/SaturdayDeliveryIndicator` (empty tag to enable)
- Only available for select services and origin/destination pairs.

### FedEx

- **Enumeration:** `SATURDAY_DELIVERY` (shipment-level special service)
- API field: `requestedShipment.specialServicesRequested.specialServiceTypes[] = SATURDAY_DELIVERY`

---

## Saturday Pickup

Requests a Saturday pickup from the shipper's location.

### USPS

Not available as a selectable extra service.

### UPS

- **Accessorial code 310:** `SATURDAY INTERNATIONAL PROCESSING FEE`
- Saturday pickup availability depends on service and location.

### FedEx

- **Shipment-level enumeration:** `SATURDAY_PICKUP`
- **Package-level enumeration:** `SATURDAY_PICKUP`

---

## Hold at Location / Hold for Pickup

Package is held at a carrier facility or access point for the recipient to retrieve rather than being delivered to the address.

### USPS

- **Extra Service Code 985:** `Hold For Pickup`
- Field: included in `extraServices` array
- Constraints: incompatible with `carrierRelease: true`; incompatible with `ancillaryServiceEndorsements`

### UPS

- **Accessorial code 220:** `HOLD FOR PICKUP`
- **Accessorial code 512:** `DROP OFF AT UPS FACILITY`
- **Accessorial code 544:** `RETAIL ACCESS POINT`
- API field: `/Shipment/ShipmentServiceOptions/HoldForPickupIndicator`

### FedEx

- **Enumeration:** `HOLD_AT_LOCATION` (shipment-level special service)
- API field: `requestedShipment.specialServicesRequested.specialServiceTypes[] = HOLD_AT_LOCATION`
- Location details specified in `requestedShipment.specialServicesRequested.holdAtLocationDetail`

---

## Carrier / Shipper Release

Authorizes the carrier to leave the package without a recipient present (no signature collected).

### USPS

- Field: `carrierRelease` (boolean, separate from `extraServices`)
- **Constraints (USPS):** Not supported with `holdForPickup: true`; not supported for `PRIORITY_MAIL`, `PRIORITY_MAIL_EXPRESS`, or `USPS_GROUND_ADVANTAGE`; incompatible with signature/certified/insurance extra service codes (910-913, 921-925, 930-931, 934, 955, 981)

### UPS

- **Accessorial code 402:** `SHIPPER RELEASE`
- API field: `/Shipment/Package/PackageServiceOptions/ShipperReleaseIndicator`

### FedEx

No direct equivalent as a named special service type. FedEx's equivalent behavior is configured through `signatureOptionType = NO_SIGNATURE_REQUIRED` on the signature option.

---

## Return Receipt

Provides physical or electronic confirmation that the package was delivered, returned to the sender.

### USPS

| Extra Service Code | Name | Notes |
| --- | --- | --- |
| 955 | Return Receipt | Physical green card mailed back to sender. Requires `physicalSignatureRequired`. |
| 957 | Return Receipt Electronic | Electronic delivery confirmation (email). |

Typically combined with Certified Mail (910/911), COD (915), or Insurance (931). See STC list for valid combinations.

### UPS / FedEx

No direct equivalent. Delivery confirmation is handled through tracking and notification services.

---

## Return Labels / Return Services

Generates a label or service for returning a package to the shipper.

### USPS

- Field: `returnLabel` (boolean on the domestic label request)
- When `true`, a return label (USPS Ground Advantage or Priority Mail Return) is generated alongside the outbound label.
- Constraints: not available with `imageType: LABEL_BROKER`, `USPS_CONNECT_MAIL`, customs forms, or extra service code 415.

### UPS

| Accessorial Code | Name |
| --- | --- |
| 250 | PRINT RETURN LABEL |
| 260 | PRINT N MAIL |
| 280 | RETURN SERVICE 1ATTEMPT |
| 290 | RETURN SERVICE 3ATTEMPT |
| 350 | ELECTRONIC RETURN LABEL |
| 410 | RETURN OF DOCUMENT |
| 464 | EXCHANGE PRINT RETURN LABEL |
| 465 | EXCHANGE FORWARD |

### FedEx

- **Enumeration:** `RETURN_SHIPMENT` (shipment-level special service)
- Return type specified in `requestedShipment.specialServicesRequested.returnShipmentDetail.returnType`
- Also: `RETURNS_CLEARANCE` for international return clearance

---

## Dry Ice

Used when shipping with dry ice as a refrigerant; triggers carrier hazmat/regulatory handling.

### USPS

Not available as a named extra service for standard parcel endpoints. Dry ice shipments fall under HAZMAT rules (code 819: `HAZMAT Class 9 – Dry Ice Package`).

### UPS

- **Accessorial code 200:** `DRY ICE`
- API field: `/Shipment/Package/PackageServiceOptions/DryIce` with `RegulationSet` and `DryIceWeight`

### FedEx

- **Enumeration:** `DRY_ICE` (shipment-level special service)
- API field: `requestedShipment.specialServicesRequested.specialServiceTypes[] = DRY_ICE`
- Detail in `requestedShipment.specialServicesRequested.shipmentDryIceDetail` (weight and unit of measure)

---

## Hazardous Materials

Identifies a shipment containing regulated hazardous or dangerous goods.

### USPS

Extra service codes submitted in the `extraServices` array. Code 857 is the general HAZMAT flag; specific class codes 810–832 identify the type of hazmat.

| Code | Description |
| --- | --- |
| 857 | Hazardous Material (general) |
| 810–832 | Specific HAZMAT class/division codes (see usps-api-reference.md for full list) |

Also set `contentType: HAZMAT` on the package.

**Key constraint:** `CHANGE_SERVICE_REQUESTED` ancillary endorsement cannot be used with HAZMAT shipments.

### UPS

- **Accessorial code 199:** `HAZ MAT`
- API field: `/Shipment/Package/PackageServiceOptions/HazMat` — requires detailed commodity information per UN/NA regulations

### FedEx

- **Enumeration:** `DANGEROUS_GOODS` (freight-level and package-level special service)
- Multiple sub-types available: `BATTERY`, `BIOLOGICAL_SUBSTANCES_CATEGORY_B`, `DANGEROUS_GOODS_IN_EXCEPTED_QUANTITIES`, `GENETICALLY_MODIFIED_ORGANISMS_AND_MICROORGANISMS`, `RADIOACTIVE_EXCEPTED_PACKAGE`, `STANDALONE_BATTERY`
- API field: `requestedShipment.requestedPackageLineItems[].specialServicesRequested.specialServiceTypes[] = DANGEROUS_GOODS`
- Dangerous goods detail in `requestedShipment.requestedPackageLineItems[].specialServicesRequested.dangerousGoodsDetail`

---

## Alcohol

Special handling for shipments containing alcoholic beverages.

### USPS

No domestic extra service for alcohol. International shipments via UPS Mail Innovations may apply ISC codes.

### UPS

- **Accessorial code 205:** `ISC ALCOHOLIC BEVERAGES` — international shipments only

### FedEx

- **Enumeration:** `ALCOHOL` (package-level special service)
- API field: `requestedShipment.requestedPackageLineItems[].specialServicesRequested.specialServiceTypes[] = ALCOHOL`
- Detail in `requestedShipment.requestedPackageLineItems[].specialServicesRequested.alcoholDetail`

---

## Battery / Lithium Battery

Special handling for shipments containing lithium batteries (standalone or inside equipment).

### USPS

Handled via HAZMAT extra service codes:

| Code | Description |
| --- | --- |
| 816 | HAZMAT Class 9 – Lithium Battery Marked – Ground Only Package |
| 817 | HAZMAT Class 9 – Lithium Battery – Returns Package |
| 818 | HAZMAT Class 9 – Lithium Batteries, Marked Package |
| 820 | HAZMAT Class 9 – Lithium Batteries, Unmarked Package |

Code 820 is also valid for international labels.

### UPS

No standalone "battery" accessorial code for domestic parcels — handled as part of HAZMAT (code 199) with appropriate UN number.

### FedEx

- **Enumeration:** `BATTERY` (package-level special service)
- **Enumeration:** `STANDALONE_BATTERY` (package-level special service)
- Applied via `requestedShipment.requestedPackageLineItems[].specialServicesRequested.specialServiceTypes[]`

---

## Residential Delivery

Flags a shipment as being delivered to a residential address, which typically incurs a surcharge.

### USPS

Implicit — USPS does not charge a separate residential surcharge; it handles residential delivery natively.

### UPS

- **Accessorial code 270:** `RESIDENTIAL ADDRESS`
- API field: `/Shipment/ShipTo/Address/ResidentialAddressIndicator` (empty tag)
- UPS applies this surcharge when the destination is a residential address; it can also be explicitly declared.

### FedEx

Implicit — FedEx detects residential addresses automatically via address validation and applies the surcharge. No special service enumeration required for standard parcels.

---

## Delivery Notifications / Event Notification

Sends proactive shipment status notifications to the shipper and/or recipient.

### USPS

No equivalent selectable extra service in the label API.

### UPS

Multiple accessorial codes for email and notification services:

| Code | Description |
| --- | --- |
| 153–158 | Package-level email notifications (ship, return, exception, delivery) |
| 173–179 | Shipment-level email notifications |
| 372 | Quantum View Notify Delivery |
| 442 | Package QV In-Transit Notification |
| 443 | Shipment QV In-Transit Notification |
| 466 | Ship Prealert Notification |

### FedEx

- **Enumeration:** `EVENT_NOTIFICATION` (shipment-level special service)
- API field: `requestedShipment.specialServicesRequested.specialServiceTypes[] = EVENT_NOTIFICATION`
- Notification preferences in `requestedShipment.specialServicesRequested.eventNotificationDetail`

---

## Ancillary Service Endorsements (USPS only)

Instructs USPS how to handle undeliverable packages or address changes. Printed on the label as an endorsement line.

### USPS

Field: `packageOptions.ancillaryServiceEndorsements` (string enum)

| Value | Behavior |
| --- | --- |
| `CHANGE_SERVICE_REQUESTED` | Forward mail; send sender new address |
| `ADDRESS_SERVICE_REQUESTED` | Forward if possible; return if undeliverable |
| `ELECTRONIC_SERVICE_REQUESTED` | Electronic notification only, no physical forwarding |
| `RETURN_SERVICE_REQUESTED` | Return to sender if undeliverable |
| `TEMP_RETURN_SERVICE_REQUESTED` | Temporarily return to sender |
| `FORWARDING_SERVICE_REQUESTED` | Forward to new address; no notice to sender |

**Constraints:**
- Only valid for `PARCEL_SELECT`, `USPS_GROUND_ADVANTAGE`, `PRIORITY_MAIL`, `PRIORITY_MAIL_EXPRESS`
- Not supported with `holdForPickup: true`
- `CHANGE_SERVICE_REQUESTED` cannot be used with HAZMAT shipments
- `CHANGE_SERVICE_REQUESTED` + `PRIORITY_MAIL` requires `contentType: PERISHABLE`
- `CHANGE_SERVICE_REQUESTED` with `PARCEL_SELECT`, `USPS_GROUND_ADVANTAGE`, or `PRIORITY_MAIL` can only be combined with extra service codes 920 or 921

### UPS / FedEx

No direct equivalent. Address correction behavior is handled automatically by carrier systems.

---

## Non-Delivery Options

Specifies what the carrier should do if a package cannot be delivered.

### USPS

Field: `packageOptions.nonDeliveryOption` (string enum)

| Value | Behavior |
| --- | --- |
| `RETURN` | Return to `fromAddress` |
| `REDIRECT` | Send to `redirectAddress` |
| `ABANDON` | Dispose of package |

Note: `REDIRECT` and `ABANDON` do not apply to domestic USPS shipments — use `ancillaryServiceEndorsements` instead for domestic. This field is most relevant for international labels.

### UPS

No direct equivalent special service.

### FedEx

- **Enumeration:** `BROKER_SELECT_OPTION` (shipment-level special service) — allows a broker to be named for customs clearance on international shipments, which influences handling on non-delivery.
- Standard non-delivery behavior is configured in FedEx account settings rather than per-shipment.

---

## Tracking Plus / Extended Tracking Retention (USPS only)

Extends the period for which USPS retains detailed tracking event data, beyond the default 120 days.

### USPS

Available on both domestic and international labels. Submitted as extra service codes in the `extraServices` array.

| Code | Retention Period | Signature Copy |
| --- | --- | --- |
| 480 | 6 Months | No |
| 481 | 1 Year | No |
| 482 | 3 Years | No |
| 483 | 5 Years | No |
| 484 | 7 Years | No |
| 485 | 10 Years (domestic only) | No |
| 486 | 3 Years | Yes |
| 487 | 5 Years | Yes |
| 488 | 7 Years | Yes |
| 489 | 10 Years (domestic only) | Yes |

Only one Tracking Plus service may be selected per package. Signature retention codes (486–489) require a signature service to also be requested.

### UPS / FedEx

No direct equivalent.

---

## Priority Alert (FedEx only)

Proactive monitoring of a shipment with FedEx intervention if a delay or exception is detected.

### FedEx

- **Enumeration:** `PRIORITY_ALERT` (package-level special service)
- **Enumeration:** `PRIORITY_ALERT_PLUS` (enhanced version with additional intervention options)
- API field: `requestedShipment.requestedPackageLineItems[].specialServicesRequested.specialServiceTypes[]`

### USPS / UPS

No direct equivalent.

---

## Protection from Freezing (FedEx only)

Instructs FedEx to protect the shipment from freezing temperatures during transit.

### FedEx

- **Enumeration:** `PROTECTION_FROM_FREEZING` (shipment-level special service)
- API field: `requestedShipment.specialServicesRequested.specialServiceTypes[] = PROTECTION_FROM_FREEZING`

### USPS / UPS

No direct equivalent. UPS offers `REFRIGERATION` (code 452) for temperature-controlled shipping.

---

## Appointment / Home Delivery Options (FedEx only)

FedEx-specific home delivery scheduling options, applicable to `GROUND_HOME_DELIVERY` service.

### FedEx

| Enumeration | Level | Description |
| --- | --- | --- |
| `APPOINTMENT` | Shipment & Package | FedEx Appointment Home Delivery® — recipient schedules a delivery window |
| `DATE_CERTAIN` | Package | FedEx Date Certain Home Delivery® — delivery on a specific date |
| `EVENING` | Package | FedEx Evening Home Delivery® |
| `CUSTOM_DELIVERY_WINDOW` | Shipment | Customer-requested delivery window |
| `CALL_BEFORE_DELIVERY` | Shipment | Carrier calls recipient before attempting delivery |
| `HOME_DELIVERY_PREMIUM` | Shipment | Premium Home Delivery |

### USPS / UPS

No direct equivalents for scheduled home delivery windows. UPS offers `COMMITTED DELIVERY WINDOW` (code 470) as a related option.

---

## UPS Premier (UPS only)

Enhanced monitoring and proactive intervention service for high-value or time-sensitive shipments.

### UPS

| Accessorial Code | Name |
| --- | --- |
| 555 | UPS PREMIER GOLD |
| 556 | UPS PREMIER SILVER |
| 557 | UPS PREMIER PLATINUM |
| 515 | UPS PREMIUM CARE |

Special handling instruction codes are also available for UPS Premier shipments (see UPS API reference appendix).

### USPS / FedEx

FedEx Priority Alert (see above) is a rough equivalent to UPS Premier.

---

## Import Control (UPS only)

Allows the shipper to create return/import shipments for packages coming back from international destinations.

### UPS

| Accessorial Code | Name |
| --- | --- |
| 444 | IMPORT CONTROL |
| 446 | IMPORT CONTROL ELECTRONIC LABEL |
| 447 | IMPORT CONTROL PRINT LABEL |
| 448 | IMPORT CONTROL PRINT AND MAIL LABEL |
| 449 | IMPORT CONTROL ONE PICK UP ATTEMPT LABEL |
| 450 | IMPORT CONTROL THREE PICK UP ATTEMPT LABEL |

### USPS / FedEx

No direct equivalent.

---

## Implementation Notes for PolyBag

**Naming conventions across carriers:** The same concept goes by different names — "Delivery Confirmation" (UPS), "Signature Option" (FedEx), "Signature Confirmation" (USPS). PolyBag should use normalized internal names (e.g., `SIGNATURE_REQUIRED`, `ADULT_SIGNATURE_REQUIRED`) and map them to carrier-specific fields at the API layer.

**Services that are carrier-specific:** Certified Mail, Registered Mail, Return Receipt, Tracking Plus, and Ancillary Service Endorsements are USPS-only. Priority Alert and home delivery scheduling options are FedEx-only. Import Control and UPS Premier are UPS-only. These should be modeled as carrier-specific options rather than universal ones.

**USPS extra service combinations:** USPS services must be combined correctly per the STC list — not all combinations are valid. The label API will reject invalid combinations. Key combinable services are signature + insurance, COD + return receipt, certified mail + return receipt, etc.

**Surcharges vs. selectable services:** Some items in the UPS accessorial code list (fuel surcharge, delivery area surcharge, peak season surcharge, large package surcharge) are automatically applied by the carrier and are not selectable by the shipper. PolyBag should distinguish between actively selectable services and passively applied surcharges that appear in rate/invoice responses.

**Domestic vs. international differences (USPS):** USPS international labels use a different endpoint and support a narrower set of extra services (max 3 codes, no COD, no certified/registered mail, no hold for pickup). PolyBag should apply the domestic vs. international constraint at the service selection layer.
