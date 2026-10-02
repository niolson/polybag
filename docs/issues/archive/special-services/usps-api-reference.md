# USPS Shipping API Reference Guide

> **Sources:**
> - Domestic Labels API spec: `labels_7_0.yaml`
> - International Labels API spec: `international-labels_15.yaml`
> - IMPB Service Type Code List: `Service_Type_Codes_Appendix_I_01272026(STC List).csv`
> **Purpose:** Reference data for integrating with the USPS Labels API (extra service codes, mail classes, package options, constraints, etc.)

---

## Table of Contents

- [Domestic Mail Classes](#domestic-mail-classes)
- [International Mail Classes](#international-mail-classes)
- [Domestic Extra Services](#domestic-extra-services)
- [International Extra Services](#international-extra-services)
- [Other Package Options (Domestic)](#other-package-options-domestic)
- [Service Type Codes (STC)](#service-type-codes-stc)

---

## Domestic Mail Classes

Field: `packageDescription.mailClass`

| Enum Value | Notes |
| --- | --- |
| `USPS_GROUND_ADVANTAGE` | Primary ground parcel service |
| `PRIORITY_MAIL` | 1–3 day priority service |
| `PRIORITY_MAIL_EXPRESS` | Overnight/express, guaranteed |
| `PARCEL_SELECT` | Destination entry; converts to `USPS_GROUND_ADVANTAGE` when `destinationEntryFacilityType` is `NONE` |
| `PARCEL_SELECT_LIGHTWEIGHT` | Deprecated; converts to `PARCEL_SELECT` |
| `FIRST-CLASS_PACKAGE_SERVICE` | Deprecated; converts to `USPS_GROUND_ADVANTAGE` |
| `USPS_CONNECT_LOCAL` | Local same-day/next-day connect service |
| `USPS_CONNECT_REGIONAL` | Regional connect service |
| `USPS_CONNECT_MAIL` | Connect mail (requires `processingCategory` of `FLATS`) |
| `LIBRARY_MAIL` | Library mail class |
| `MEDIA_MAIL` | Media mail class |
| `BOUND_PRINTED_MATTER` | Bound printed matter class |

---

## International Mail Classes

Field: `packageDescription.mailClass` (international endpoint)

| Enum Value |
| --- |
| `FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE` |
| `PRIORITY_MAIL_INTERNATIONAL` |
| `PRIORITY_MAIL_EXPRESS_INTERNATIONAL` |
| `GLOBAL_EXPRESS_GUARANTEED` |

---

## Domestic Extra Services

Field: `packageDescription.extraServices` (array of integer codes, max 5 items)

The following codes are submitted as integer values in the `extraServices` array on the domestic label request.

### Label & Delivery Options

| Code | Name | Notes |
| --- | --- | --- |
| 415 | USPS Label Delivery | Label is printed and mailed to `fromAddress` instead of being returned in the API response. No label image returned. Not available at all locations. Incompatible with `returnLabel: true`. |
| 365 | Global Direct Entry | |
| 991 | Sunday Delivery | |
| 986 | PO to Addressee | `PRIORITY_MAIL_EXPRESS` only |
| 985 | Hold For Pickup | Package held at Post Office for recipient pickup |

### Tracking Plus (Extended Retention)

| Code | Name |
| --- | --- |
| 480 | Tracking Plus – 6 Months |
| 481 | Tracking Plus – 1 Year |
| 482 | Tracking Plus – 3 Years |
| 483 | Tracking Plus – 5 Years |
| 484 | Tracking Plus – 7 Years |
| 485 | Tracking Plus – 10 Years |
| 486 | Tracking Plus Signature – 3 Years |
| 487 | Tracking Plus Signature – 5 Years |
| 488 | Tracking Plus Signature – 7 Years |
| 489 | Tracking Plus Signature – 10 Years |

### Tracking & Signature

| Code | Name | Notes |
| --- | --- | --- |
| 920 | USPS Tracking | Standard tracking |
| 921 | Signature Confirmation | Requires `physicalSignatureRequired` field |
| 924 | Signature Confirmation Restricted Delivery | Requires `physicalSignatureRequired` field |
| 981 | Signature Requested | `PRIORITY_MAIL_EXPRESS` only. Requires `physicalSignatureRequired` field. |

### Adult Signature

| Code | Name | Notes |
| --- | --- | --- |
| 922 | Adult Signature Required (21 or Over) | Requires `physicalSignatureRequired` field |
| 923 | Adult Signature Restricted Delivery (21 or Over) | Requires `physicalSignatureRequired` field |

### Certified Mail

| Code | Name | Notes |
| --- | --- | --- |
| 910 | Certified Mail | Requires `physicalSignatureRequired` field |
| 911 | Certified Mail Restricted Delivery | Requires `physicalSignatureRequired` field |
| 912 | Certified Mail Adult Signature Required | Requires `physicalSignatureRequired` field |
| 913 | Certified Mail Adult Signature Restricted Delivery | Requires `physicalSignatureRequired` field |

### Registered Mail

| Code | Name | Notes |
| --- | --- | --- |
| 940 | Registered Mail | eVS not supported for all combinations; check STC list |
| 941 | Registered Mail Restricted Delivery | |

### Insurance

| Code | Name | Notes |
| --- | --- | --- |
| 930 | Insurance ≤ $500 | If package value exceeds $500, automatically upgraded to code 931. Requires `physicalSignatureRequired` when value > $500. Requires `packageValue` field. |
| 931 | Insurance > $500 | Requires `physicalSignatureRequired` field. Requires `packageValue` field. |
| 925 | Priority Mail Express Merchandise Insurance | `PRIORITY_MAIL_EXPRESS` only |
| 934 | Insurance Restricted Delivery | Requires `physicalSignatureRequired` field |

### Return Receipt

| Code | Name | Notes |
| --- | --- | --- |
| 955 | Return Receipt | Physical return receipt. Requires `physicalSignatureRequired` field. |
| 957 | Return Receipt Electronic | Electronic return receipt |

### COD (Collect on Delivery)

| Code | Name |
| --- | --- |
| 915 | COD |
| 917 | COD Restricted Delivery |

### Hazardous Materials

| Code | Name |
| --- | --- |
| 857 | Hazardous Material (general flag) |
| 810 | HAZMAT Air Eligible Ethanol Package |
| 811 | HAZMAT Class 1 – Toy Propellant/Safety Fuse Package |
| 812 | HAZMAT Class 3 – Flammable Liquid Package |
| 813 | HAZMAT Class 7 – Radioactive Materials Package |
| 814 | HAZMAT Class 8 – Corrosive Materials Package |
| 815 | HAZMAT Class 8 – Nonspillable Wet Battery Package |
| 816 | HAZMAT Class 9 – Lithium Battery Marked – Ground Only Package |
| 817 | HAZMAT Class 9 – Lithium Battery – Returns Package |
| 818 | HAZMAT Class 9 – Lithium Batteries, Marked Package |
| 819 | HAZMAT Class 9 – Dry Ice Package |
| 820 | HAZMAT Class 9 – Lithium Batteries, Unmarked Package |
| 821 | HAZMAT Class 9 – Magnetized Materials Package |
| 822 | HAZMAT Division 4.1 – Flammable Solids or Safety Matches Package |
| 823 | HAZMAT Division 5.1 – Oxidizers Package |
| 824 | HAZMAT Division 5.2 – Organic Peroxides Package |
| 825 | HAZMAT Division 6.1 – Toxic Materials Package |
| 826 | HAZMAT Division 6.2 – Infectious Substances Package |
| 827 | HAZMAT Excepted Quantity Provision Package |
| 828 | HAZMAT Ground Only |
| 829 | HAZMAT ID8000 Consumer Commodity Package |
| 830 | HAZMAT Lighters Package |
| 831 | HAZMAT LTD QTY Ground Package |
| 832 | HAZMAT Small Quantity Provision Package |

### Special Content

| Code | Name |
| --- | --- |
| 858 | Cremated Remains |
| 430 | Open and Distribute (PMOD) |
| 452 | Return Service |

### Key Constraints

- `physicalSignatureRequired` is **required** when using any of the following codes: 910, 911, 912, 913, 921, 922, 923, 924, 925, 931, 934, 955, 981, or code 930 when `packageValue > 500`.
- `carrierRelease` is **incompatible** with codes: 910, 911, 912, 913, 921, 922, 923, 924, 925, 930, 931, 934, 955, 981.
- Maximum of **5 extra service codes** per domestic label request.
- Multiple codes can and often must be combined (e.g. COD + Return Receipt = codes 915 + 955). For all valid combinations, refer to the [IMPB Service Type Codes list](https://postalpro.usps.com/IMPB_Service_Type_Codes).

---

## International Extra Services

Field: `packageDescription.extraServices` (array of integer codes, max 3 items)

Submitted as integer values in the `extraServices` array on the international label request. The `ExtraService` schema for international labels defines the following valid codes:

### Tracking Plus (Extended Retention)

| Code | Name |
| --- | --- |
| 480 | Tracking Plus – 6 Months |
| 481 | Tracking Plus – 1 Year |
| 482 | Tracking Plus – 3 Years |
| 483 | Tracking Plus – 5 Years |
| 484 | Tracking Plus – 7 Years |
| 486 | Tracking Plus Signature – 3 Years |
| 487 | Tracking Plus Signature – 5 Years |
| 488 | Tracking Plus Signature – 7 Years |

> Note: 485 (10 Years) and 489 (Signature 10 Years) are available domestically but not listed in the international schema.

### Insurance

| Code | Name |
| --- | --- |
| 930 | Insurance ≤ $500 |
| 931 | Insurance > $500 |

### Hazardous Materials

| Code | Name |
| --- | --- |
| 857 | Hazardous Material |
| 813 | HAZMAT Class 7 – Radioactive Materials Package |
| 820 | HAZMAT Class 9 – Lithium Batteries, Unmarked Package |
| 826 | HAZMAT Division 6.2 – Infectious Substances Package |

### Return Receipt

| Code | Name | Notes |
| --- | --- | --- |
| 955 | Return Receipt | Unsupported as of 01/19/2025 |

### Key Constraints

- Only one Tracking Plus service and one Insurance service may be selected per package.
- Maximum of **3 extra service codes** per international label request.

---

## Other Package Options (Domestic)

These are separate boolean/string fields on the label request, not extra service codes, but function as additional service options.

### `carrierRelease` (boolean)

Authorizes the carrier to leave the shipment without a recipient present.

**Incompatible with:**
- `holdForPickup: true`
- Mail classes: `PRIORITY_MAIL`, `PRIORITY_MAIL_EXPRESS`, `USPS_GROUND_ADVANTAGE`
- Extra service codes: 910, 911, 912, 913, 921, 922, 923, 924, 925, 930, 931, 934, 955, 981

### `physicalSignatureRequired` (boolean)

Controls whether a physical (wet) signature is required at delivery vs. allowing USPS Electronic Signature Online (eSOL).

- Default for all mail classes except PME: `SIGNATURE_REQUIRED` (allows electronic or physical)
- `SIGNATURE_WAIVED` is only valid for `PRIORITY_MAIL_EXPRESS`
- **Required** when using signature/certified/insurance extra service codes (see constraints above)

### `ancillaryServiceEndorsements` (string enum)

Instructs USPS what to do if delivery fails and how to handle address changes.

| Value | Description |
| --- | --- |
| `CHANGE_SERVICE_REQUESTED` | Forward mail and notify sender of new address |
| `ADDRESS_SERVICE_REQUESTED` | Forward mail; return if undeliverable |
| `ELECTRONIC_SERVICE_REQUESTED` | Electronic notification of address change or non-delivery |
| `RETURN_SERVICE_REQUESTED` | Return to sender |
| `TEMP_RETURN_SERVICE_REQUESTED` | Temporary return to sender |
| `FORWARDING_SERVICE_REQUESTED` | Forward to new address |

**Constraints:**
- Not supported when `holdForPickup: true`
- Only valid for mail classes: `PARCEL_SELECT`, `USPS_GROUND_ADVANTAGE`, `PRIORITY_MAIL`, `PRIORITY_MAIL_EXPRESS`
- `CHANGE_SERVICE_REQUESTED` with `PRIORITY_MAIL` only supports `contentType` of `PERISHABLE`
- `CHANGE_SERVICE_REQUESTED` cannot be used with HAZMAT shipments
- `CHANGE_SERVICE_REQUESTED` with `PARCEL_SELECT`, `USPS_GROUND_ADVANTAGE`, or `PRIORITY_MAIL` can only be combined with extra service codes 920 or 921

### `nonDeliveryOption` (string enum)

Action to take when a package is undeliverable. Primarily relevant for international; for domestic, use `ancillaryServiceEndorsements` instead.

| Value | Description |
| --- | --- |
| `RETURN` | Return package to `fromAddress` |
| `REDIRECT` | Send to address specified in `redirectAddress` |
| `ABANDON` | Dispose of the package |

### `contentType` (string enum)

Declares the nature of the package contents. Relevant for special handling/routing.

| Value |
| --- |
| `HAZMAT` |
| `CREMATED_REMAINS` |
| `BEES` |
| `DAY_OLD_POULTRY` |
| `PERISHABLE` |
| `LIVE_ANIMALS` |

### `returnLabel` (boolean)

When `true`, a return label is generated alongside the outbound label. Constraints:
- Not supported with `imageType: LABEL_BROKER`
- Not supported for `USPS_CONNECT_MAIL`
- Not supported when generating customs forms
- Not available with extra service code 415 (USPS Label Delivery)

---

## Service Type Codes (STC)

Source: `Service_Type_Codes_Appendix_I_01272026(STC List).csv`

The IMPB Service Type Code (STC) is a 3-digit code printed on USPS labels that encodes the combination of mail class and extra services applied to a shipment. The STC is returned in the label API response (`serviceTypeCode` field) and appears on the physical label.

STCs are not submitted by the client — they are determined by USPS based on the `mailClass` and `extraServices` requested. However, the STC list is useful for:
- Validating which extra service combinations are supported for a given mail class
- Understanding which combinations are supported in eVS (`eVS Y/N` column) vs. the standard label API (`CS Y/N` column)
- Cross-referencing banner text that will appear on printed labels

**CSV columns:**
- `STC` — 3-digit service type code
- `Full Description` — Human-readable description of the mail class + extra service combination
- `Class of Mail` — Abbreviated mail class (PM = Priority Mail, FC = First Class/Ground Advantage, EX = Priority Mail Express, PS = Parcel Select, BB = Bound Printed Matter)
- `Extra Service Code 1–5` — The extra service codes that make up this combination
- `CS Y/N` — Supported in the standard label API (Click-N-Ship)
- `eVS Y/N` — Supported via eVS (Electronic Verification System) / high-volume mailer API
