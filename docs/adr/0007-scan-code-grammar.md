# ADR-0007: One grammar for everything a station scans

## Status

Proposed — 2026-09-29.

Revised the same day after review, before anything was committed or printed. The first
draft gave each record type a bare letter (`S216`), kept `*` for commands, let a
command's meaning depend on the page, and looked a record code up as an order reference
too. The review showed four problems with that:

- Every letter allocated later would compete with existing box codes and SKUs.
- A deleted Shipment's slip could open whichever Shipment had `S216` as its reference.
- A command sheet carried to another page could change meaning.
- Scans that name a record were ignored while packing.

The rejected shapes are kept under "Options considered".

Revised again the same day after a second review:

- A Package code resumes exactly that Package or refuses; Scan & Pack resumes a Shipment's
  oldest draft, which need not be the one scanned.
- Box Sizes get codes, since a box code that is also a product barcode was resolved by
  guessing.
- The last-label commands track the Label, not the Package.
- The prefix must start with a letter.
- The parser's boundaries are written down.
- The restore limitation is stated without an absolute guarantee.

## Context

A station has one barcode scanner. It is a USB device in keyboard mode: it types what it
reads, as a US keyboard would, and presses Enter. PolyBag knows a scan only from the
characters that arrive, so every kind of code a packer might scan must be told apart by
its shape.

Today several kinds arrive in the same Scan & Pack input:

- **Command barcodes**: `*1` to `*4` and `*0`, printed from Print Command Barcodes. Only
  the Pack page acts on them.
- **Order references**: `shipment_reference` (`#1247`, an Amazon order ID, whatever a
  database connection imports). This is what the pick-batch pack slip encoded, and what
  Scan & Pack looks up when no Shipment is loaded.
- **Box codes**: free text chosen by the operator. The seeded ones are `01` to `21`.
- **Product barcodes**: UPC/EAN (all digits) and SKUs (free text).

The order reference is not unique. Shipments are unique on (`data_source_id`,
`source_record_id`), so two Shopify stores can both have an order `#1001`, and a
manual-ship Shipment has no connection at all. Scan & Pack took the first match, so in
multi-client (3PL) mode a packer scanning a slip could open another client's Shipment
without any warning.

Something unique has to go on the slip, and it should also work in global search: a user
should be able to click into search, scan an invoice and land on the Shipment. This is the
first time PolyBag prints its own identifiers for scanning. Navigation barcodes and
operator-defined actions are expected later, so this ADR settles the grammar as a whole
rather than one code at a time.

## Decision

### 1. A PolyBag code is a reserved prefix, a type token and a body

`PB` + `S` + `216` = `PBS216`, Shipment 216.

- **Prefix.** Set per install as `SCAN_CODE_PREFIX` in `.env`, defaulting to `PB`: a
  letter, then up to three letters or digits. It starts with a letter because a leading
  digit would claim a whole range of numeric UPCs. No prefix can guarantee that no SKU or
  order reference begins with it. It makes a collision unlikely, and decision 2 decides
  who wins when one happens. Changing the prefix invalidates every code already printed.
- **Type token.** Taken from a fixed registry (below), never inferred from the shape of the
  body. The tokens are prefix-free, so no token is the start of another and a code parses
  one way only.
- **Body.** Fixed by the type: digits for a record ID, letters for a command name.

Only `A`–`Z` and `0`–`9` appear. A keyboard-mode scanner sends every character by its US
key position, so any other character arrives wrong on a host set to another layout: `*`
becomes `(` and `-` becomes `ß` on German QWERTZ. Control characters and FNC codes are
excluded for the same reason, and because some control characters arrive as browser
shortcuts a page cannot block (Ctrl+W). PolyBag codes match case-insensitively. External
identifiers are compared as they always have been.

| Token | Body | Names | Status |
|---|---|---|---|
| `S` | digits | a Shipment, by `shipments.id` | Implemented |
| `P` | digits | a Package, by `packages.id` | Implemented |
| `B` | digits | a Box Size, by `box_sizes.id` | Implemented on the Pack page and box barcode sheet |
| `C` | letters | a built-in command (decision 4) | Implemented for the Pack page |
| `M` | digits | an operator-defined action, by the ID of its record | Reserved |

A new type gets a new token by amending this table. Tokens are assigned to things
operators identify and handle — pick batches, totes, bins, staging positions — as
something starts printing them, not to every table.

A Box Size's code identifies the warehouse's own box record; it is not a standard box.
The operator's short code (`01`, `A1`) stays as an alias for typing. Only the alias can
collide with a product barcode, and only the alias is subject to the Pack page's
older rule for that collision (box while no dimensions are set, product after). A
Product keeps its UPC and SKU; a Product token can follow if PolyBag ever prints its own
product labels. Tracking numbers, UPCs, SKUs and order references stay external
identifiers attached to records. They are never PolyBag's own identity.

**Boundaries.** Surrounding whitespace is ignored. The prefix and token match in any case.
A record body is 1 to 18 digits naming a positive ID, and leading zeros are ignored
(`PBS0216` is `PBS216`, and `PBS0` is unrecognized). A command body is 1 to 32 letters. The
prefix alone, an unknown token, or a body of the wrong kind is an unrecognized PolyBag
code.

### 2. A PolyBag code names its record exactly, or nothing

| Scan | Result |
|---|---|
| A PolyBag code whose record exists | That record, and only that record |
| A PolyBag code whose record does not exist | "Not found". No other lookup is tried |
| The prefix, then an unknown token or a malformed body | "Unrecognized PolyBag code". No other lookup is tried |
| Anything else | An external identifier, looked up in the current context |

A code with PolyBag's prefix is never looked up as an order reference, box code or product.
So a slip whose Shipment was deleted says "not found" rather than opening another
Shipment that happens to have the code as its reference. It also means a registry entry
added later cannot change what an existing code resolves to, since the whole prefix
namespace was reserved from the start.

The price is that an external identifier which begins with the prefix cannot be scanned.
The intended way out is an explicit "look up as external identifier" entry on Scan & Pack,
not a change of prefix (`docs/issues/scan-codes/issues/01`). **Until it ships, this is an
interim limitation:** an order reference beginning with the prefix cannot be opened by
scanning or typing it into Scan & Pack; open it from the Shipments list, whose search is
not intercepted. A product whose only identifier is a SKU beginning with the prefix cannot
be packed by scan and must be packed some other way. Global search has the same limitation;
the list pages' own searches are its way out.

External identifiers can be ambiguous. When an order reference matches several Shipments,
Scan & Pack lists them with client, connection, status and recipient, and the packer
chooses. It never takes the first match.

### 3. Codes are recognized everywhere; what happens depends on the page

A scan passes through three steps: parse the code, resolve the record, then let the
current page decide what to do with it. The parser and the record lookups are shared; the
action belongs to the page.

In this first implementation:

| Where | Scan | Does |
|---|---|---|
| Scan & Pack, nothing loaded | Shipment | Opens it |
| | Package, the draft Scan & Pack resumes for its Shipment | Opens its Shipment, resuming that draft |
| | Package, any other unshipped one | Opens View Package, saying which draft Scan & Pack would resume |
| | Package, shipped | Opens View Package |
| | Box Size | Says to scan a pack slip first |
| | Order reference | Opens the single match, or lists several to choose from |
| Scan & Pack, packing | The same Shipment, or the draft being packed | Says it is already open |
| | Any other Shipment or Package | Names it and says to clear the current Shipment first |
| | Box Size | Applies that box |
| | Box alias, product | As before |
| Global search | Shipment or Package code | That record alone; Enter opens it |
| | Any other PolyBag code, including commands | Nothing |
| | Anything without the prefix | Filament's search. Enter opens the result when there is exactly one |

A Package code acts on that Package and no other. Scan & Pack resumes a Shipment's oldest
unshipped Package, so a Package code opens Scan & Pack only when the scanned Package is
that one. Otherwise it opens the Package's own page, where its identity is exact.

Switching Shipments mid-pack is deliberately not automatic yet. Packing progress saves on
a debounce, so switching must first wait for the save to finish.

### 4. A command means the same thing on every page

A command sheet moves around the warehouse, so a command's meaning must not depend on
which page is open. A command is named for what it does and what it acts on, and a page
where it does not apply rejects it. It is never reinterpreted.

| Code (default prefix) | Does | Acts on |
|---|---|---|
| `PBCSHIP` | Buy and print a label | The Package being packed (same as F12) |
| `PBCREPRINTLAST` | Reprint | The Label this browser session last bought |
| `PBCVOIDLAST` | Void, if this user bought it | The Label this browser session last bought |
| `PBCZEROSCALE` | Zero the scale | This workstation's scale |
| `PBCCLEARSHIPMENT` | Clear the loaded Shipment | This page |

The last Label is remembered by its own ID, not its Package's. If it has since been voided,
even if the Package has been bought again, `REPRINTLAST` and `VOIDLAST` refuse rather than
act on the replacement. "Reprint the current Package's active Label" is a different
command from `REPRINTLAST` and would get its own name. The old `*1`–`*4` and `*0` stop
working; stations reprint their sheets.

Operator-defined actions (`M`) will invoke the same application actions, with the same
permission checks, and a repeated scan must not duplicate a purchase. That rule already
holds for `SHIP`, whose purchase runs through an Offer.

### 5. Codes last for one install's database history

A code names a database ID. It stays valid for as long as that install's database
continues without interruption, and an ID is never deliberately reassigned. Two cases
break it:

- **Restoring an older backup.** Records created after the restore can be given IDs that
  codes printed before it already carry, so an old slip or Package tag can then open the
  *wrong* record, not merely "not found". Reprinting open slips narrows this but cannot
  recall paper already on shelves. The order reference printed beside a Shipment code lets
  a packer see a mismatch. This is an accepted limitation. The stronger guarantee, an
  independently generated scan identifier per record, was considered and deferred (see
  Foreseen).
- **Moving records to another install.** Codes do not carry across installs.

Every other guarantee in this ADR ("never a different Shipment") holds within that
unbroken history.

Every printed code also appears as text beside the familiar identifier (the order
reference on a slip), so a person can type it.

### 6. Operator-chosen codes may not use the prefix

A box alias beginning with the install's prefix would be read as a PolyBag code and could
never be scanned as a box. Box size validation rejects it (`scan-codes/02`; until then an
operator must avoid such an alias, and none of the seeded aliases begins with a letter).
Products are not validated: a SKU is imported, not chosen here, and a product without a
UPC may have nothing else to scan. Such collisions are handled by the explicit external
lookup (decision 2, with its interim limitation), and an install chooses its prefix after
checking what its existing identifiers begin with (`scan-codes/03`).

## Foreseen, not decided

- **Page-wide scanning** (navigation barcodes scanned without clicking into an input). A
  focused scan input stays the reliable path. Recognizing a scan by typing speed is a
  heuristic, since scanners' keystroke delays are configurable, and it acts too late when
  the characters have already gone into a weight or address field. The stronger form is a
  scanner configured to send a prefix (such as the AIM identifier `]C0` for Code 128) that
  PolyBag recognizes; it needs setting up at each station.
- **Operator-defined actions**: what one may do, and whether it is scoped to a Client or
  Location.
- **Switching Shipments by scan** mid-pack, once the pending save can be awaited.
- **Tokens for pick batches, totes, bins and staging positions**, when their workflows
  print codes.
- **Scanning a tracking number** to find the Package and Label, including voided history,
  as global search already does.
- **A code that survives restores and moves**: an independently generated immutable scan
  identifier per record, which removes the restore limitation (decision 5). A cheaper
  partial measure — two check characters derived from the record's creation time
  (`PBS216K7`) — would only reduce the risk: 1,296 combinations means a reused ID still
  matches about once in 1,296. Either is adopted only if the limitation ever bites.
- **Scan & Pack editing a chosen draft** rather than the Shipment's oldest, so any draft's
  code could resume it there.

## Options considered

**A. Bare numeric IDs (`216`).** Rejected. A database connection can import purely numeric
references, and the seeded box codes are `01` to `21`.

**B. One letter per record type, no prefix (`S216`).** The first draft. Rejected: each
letter allocated later could invalidate existing box codes and SKUs of that shape, and
nothing reserved the namespace in advance.

**C. Separators (`S-216`) or `*` as the prefix.** Rejected. Both are punctuation, which a
non-US layout mangles (decision 1).

**D. Look a PolyBag code up as a reference too, and choose between the matches.** The
first draft. Rejected: a code for a deleted record would then open whatever other record
happened to share its text.

**E. Numbered commands whose meaning belongs to the page (`*1`).** Rejected: a printed
sheet would change meaning from page to page.

**F. Operator actions named by word (`PBMRESTOCK`).** Rejected: renaming an action would
invalidate its printed barcodes. They are addressed by ID.

**G. Make `shipment_reference` unique.** Rejected. It is the channel's number, and two
channels may legitimately share one.

**H. Fix the prefix in code.** Rejected at the maintainer's direction: whether it clashes
depends on each install's SKUs, so the install chooses.

## Trade-off

Codes are longer than the first draft's (`PBS216` against `S216`), and a PolyBag code is
not something people recognize the way they recognize an order number. The slip keeps the
reference in large print for people, with the code beside it in small type. Code 128
encodes the digit run compactly, so the barcode grows by little. A deleted-and-reimported
Shipment gets a new ID, and its old slip then finds nothing. That is shown as "not found",
never as a different Shipment, except after a restore (decision 5).

## Consequences

- The pick-batch pack slip's barcode changes from the order reference to `PBS<id>`. Slips
  printed with a reference keep working through the reference lookup, unless the
  reference begins with the prefix.
- Scan & Pack no longer opens the first of several Shipments sharing a reference.
- Global search resolves PolyBag codes exactly, and opens a single result on Enter.
- Print Command Barcodes prints the new commands. Old `*` sheets must be reprinted.
- Print Box Size Barcodes prints `PBB<id>`, with the alias beside it. Box sheets printed
  with aliases keep working, unless the alias begins with the prefix; such an alias must
  be renamed (decision 6).
- Box size validation gains a rule. An existing box alias beginning with the prefix has to
  be renamed.
- Every new scannable code is placed in this grammar by amending this ADR.

## Implementation

1. `SCAN_CODE_PREFIX` in config, and a parser returning the type and body, or "unrecognized".
2. The pack slip encodes `PBS<id>`, with the code printed beside the reference.
3. Scan & Pack routes PolyBag codes to the parser and external strings to the reference
   lookup, which lists several matches to choose from.
4. A global search provider resolves PolyBag codes exactly and defers everything else to
   Filament. Enter opens a single result.
5. Commands renamed, and Print Command Barcodes reprinted. The last Label is remembered
   by Label ID.
6. Box Size codes on the Pack page and the box barcode sheet.
7. Tracked in `docs/issues/scan-codes/`: the explicit external lookup (`01`), box alias
   validation (`02`), the prefix check (`03`), and Box Size codes in global search (`04`).
