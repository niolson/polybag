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

Revised 2026-10-06: the explicit "look up as external identifier" mode on Scan & Pack
(`scan-codes/01`) was dropped.

Revised again 2026-10-06: the prefix is now the fixed character `%`, not a configurable
`SCAN_CODE_PREFIX` defaulting to `PB`. Earlier revisions allowed only `A`–`Z` and `0`–`9`
because "any other character arrives wrong on a host set to another layout". That is
true only when the scanner's keyboard country does not match the host's layout. In that
case letters and digits break too: Y and Z swap on German QWERTZ, and the top row of
French AZERTY types `&é"'(` instead of digits. No review checked the claim against letters,
so a letter prefix was chosen to avoid a failure it does not avoid. Checked against 22
layouts, `%` fails only where the digits fail (decision 1). A prefix that no identifier
begins with removes the collisions that the configurable prefix and the planned
collision message (`scan-codes/03`) existed to manage. The previous
shape is kept as option H.

## Context

A station has one barcode scanner. It is a USB device in keyboard mode: it types what it
reads by sending key positions for the keyboard country it is set to, and presses Enter.
The host turns those positions into characters using its own layout. PolyBag knows a scan
only from the characters that arrive, so every kind of code a packer might scan must be
told apart by its shape.

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

### 1. A PolyBag code is `%`, a type token and a body

`%` + `S` + `216` = `%S216`, Shipment 216.

- **Prefix.** The single character `%`, the same on every install. Order references,
  SKUs, UPCs and box codes practically never begin with it: Shopify references begin with
  `#`, Amazon order IDs with digits, and UPCs are all digits.
- **Type token.** Taken from a fixed registry (below), never inferred from the shape of the
  body. The tokens are prefix-free, so no token is the start of another and a code parses
  one way only.
- **Body.** Fixed by the type: digits for a record ID, letters for a command name.

Apart from the prefix, only `A`–`Z` and `0`–`9` appear.

**Station requirement: the scanner's keyboard country matches the host's layout.** A
scanner left on its default (US) and plugged into a host with another layout sends the
right keys for the wrong layout. Every barcode a station scans is then at risk, not only
PolyBag's: SKUs with Y or Z on QWERTZ, every UPC on AZERTY. Scanners have a programming
barcode for the keyboard country, and station setup must include it. PolyBag cannot
detect or correct a mismatch from the characters that arrive.

**Why `%`.** The prefix should still survive a station that skipped that step wherever
the codes' own digits do. Each candidate character's US key position was compared against
22 other layouts (UK, German, Swiss, French, Belgian, Spanish, Italian, Portuguese,
Swedish, Norwegian, Danish, Finnish, Czech, Slovak, Polish, Hungarian, Turkish,
Brazilian, Latin American, Dutch, Japanese, Russian), using the layout definitions
shipped with X11 (xkb):

| Character | Layouts that receive something else |
|---|---|
| The digit `5` (for comparison) | 4: French, Belgian, Czech, Slovak |
| `%` | 4: the same four |
| `.` `,` | 4, a different set including Turkish and Russian |
| `!` | 6: adds Swiss and Hungarian |
| The letters `Y`, `Z` | 6 and 8 |
| `$`, `#` | 12 each |
| `-` `/` `&` `*` `+` `=` `_` `?` `@`, brackets and quotes | 17 to 21 |

`%` is the only character outside `A`–`Z` and `0`–`9` that fails only where the digits
do, so it adds no failure of its own. It is also an ordinary key on US-International,
where `'`, `"`, `` ` ``, `~` and `^` are dead keys that wait for the next keystroke.
Control characters and FNC codes remain excluded, since some control characters arrive
as browser shortcuts a page cannot block (Ctrl+W).

**Symbology and transport rules.** PolyBag codes are printed as Code 128 (or a 2D
symbology), never as Code 39: in Code 39 Full ASCII `%` is a shift character, and `%S`
decodes as `~`. A code that ever has to travel in a URL, such as a QR code a phone opens,
puts the code without its prefix in the path (`/scan/S216`), because `%` is the URL
escape character. A lookup that reaches SQL treats `%` as a literal, never a `LIKE`
wildcard.

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

**Boundaries.** Surrounding whitespace is ignored. The token matches in any case. A
record body is 1 to 18 digits naming a positive ID, and leading zeros are ignored
(`%S0216` is `%S216`, and `%S0` is unrecognized). A command body is 1 to 32 letters. `%`
alone, an unknown token, or a body of the wrong kind is an unrecognized PolyBag code.

### 2. A PolyBag code names its record exactly, or nothing

| Scan | Result |
|---|---|
| A PolyBag code whose record exists | That record, and only that record |
| A PolyBag code whose record does not exist | "Not found". No other lookup is tried |
| `%`, then an unknown token or a malformed body | "Unrecognized PolyBag code". No other lookup is tried |
| Anything else | An external identifier, looked up in the current context |

A code beginning with `%` is never looked up as an order reference, box code or product.
So a slip whose Shipment was deleted says "not found" rather than opening another
Shipment that happens to have the code as its reference. It also means a registry entry
added later cannot change what an existing code resolves to, since the whole `%`
namespace was reserved from the start.

The price is that an external identifier beginning with `%` cannot be scanned. That is
expected to be vanishingly rare, so there is no escape mode for it. A one-shot "look up
as external identifier" mode on Scan & Pack was considered and not built
(`scan-codes/01`). An order reference beginning with `%` is opened from the Shipments
list, whose search is not intercepted. Global search has the same limitation, and the
list pages' own searches are its way out.

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
| | Anything not beginning with `%` | Filament's search. Enter opens the result when there is exactly one |

A Package code acts on that Package and no other. Scan & Pack resumes a Shipment's oldest
unshipped Package, so a Package code opens Scan & Pack only when the scanned Package is
that one. Otherwise it opens the Package's own page, where its identity is exact.

Switching Shipments mid-pack is deliberately not automatic yet. Packing progress saves on
a debounce, so switching must first wait for the save to finish.

### 4. A command means the same thing on every page

A command sheet moves around the warehouse, so a command's meaning must not depend on
which page is open. A command is named for what it does and what it acts on, and a page
where it does not apply rejects it. It is never reinterpreted.

| Code | Does | Acts on |
|---|---|---|
| `%CSHIP` | Buy and print a label | The Package being packed (same as F12) |
| `%CREPRINTLAST` | Reprint | The Label this browser session last bought |
| `%CVOIDLAST` | Void, if this user bought it | The Label this browser session last bought |
| `%CZEROSCALE` | Zero the scale | This workstation's scale |
| `%CCLEARSHIPMENT` | Clear the loaded Shipment | This page |

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
- **Moving records to another install.** Codes do not carry across installs. With one
  prefix everywhere, a slip from another install parses and looks up a record here by
  ID; the order reference beside it is how a person notices.

Every other guarantee in this ADR ("never a different Shipment") holds within that
unbroken history.

Every printed code also appears as text beside the familiar identifier (the order
reference on a slip), so a person can type it.

### 6. Operator-chosen codes may not begin with `%`

A box alias beginning with `%` would be read as a PolyBag code and could never be scanned
as a box. Box size validation rejects it, on the Box Size form and the Setup Wizard's box
step (`scan-codes/02`). None of the seeded aliases does. Products are not validated: a
SKU is imported, not chosen here, and one beginning with `%` is accepted as data even
though it cannot be scanned (decision 2).

## Foreseen, not decided

- **Page-wide scanning** (navigation barcodes scanned without clicking into an input). A
  focused scan input stays the reliable path. Recognizing a scan by typing speed is a
  heuristic, since scanners' keystroke delays are configurable, and it acts too late when
  the characters have already gone into a weight or address field. The stronger form is a
  scanner configured to send a prefix of its own before every scan. The AIM symbology
  identifier (`]C0` for Code 128, ISO/IEC 15424) says which symbology was read, not
  whose code it is, and `]` itself arrives wrong on 20 of the 22 layouts above. A
  function key sent as a prefix is layout-independent and may suit better. Either needs
  setting up at each station, and PolyBag would strip it from every scan.
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
  (`%S216K7`) — would only reduce the risk: 1,296 combinations means a reused ID still
  matches about once in 1,296. Either is adopted only if the limitation ever bites.
- **Scan & Pack editing a chosen draft** rather than the Shipment's oldest, so any draft's
  code could resume it there.

## Options considered

**A. Bare numeric IDs (`216`).** Rejected. A database connection can import purely numeric
references, and the seeded box codes are `01` to `21`.

**B. One letter per record type, no prefix (`S216`).** The first draft. Rejected: each
letter allocated later could invalidate existing box codes and SKUs of that shape, and
nothing reserved the namespace in advance.

**C. `*` as the prefix, or separators (`S-216`).** Rejected. `*` arrives as something
else on 18 of the 22 layouts (as `(` on German), and `-` on 17 (as `ß`). `*` is also
Code 39's start and stop character.

**D. Look a PolyBag code up as a reference too, and choose between the matches.** The
first draft. Rejected: a code for a deleted record would then open whatever other record
happened to share its text.

**E. Numbered commands whose meaning belongs to the page (`*1`).** Rejected: a printed
sheet would change meaning from page to page.

**F. Operator actions named by word (`%MRESTOCK`).** Rejected: renaming an action would
invalidate its printed barcodes. They are addressed by ID.

**G. Make `shipment_reference` unique.** Rejected. It is the channel's number, and two
channels may legitimately share one.

**H. A configurable letter prefix (`SCAN_CODE_PREFIX`, default `PB`).** Adopted by the
second revision and superseded on 2026-10-06. It rested on the belief that letters and
digits survive a layout mismatch and punctuation does not, which the layout comparison
in decision 1 disproves. A letter prefix collides with real identifiers (SKUs such as
`PBJ100`), so it needed per-install configuration, a validation rule, a collision check
and a reprint procedure for changing it. `%` needs none of these.

**I. Reserve only the registered tokens (`PBS`, `PBP`, …) under a letter prefix.**
Rejected. Each token added later would take over existing SKUs and references of that
shape on every install, on upgrade and without warning.

**J. Another punctuation prefix.** `!` fails on Swiss and Hungarian layouts as well and
reads as `1` or `l` in small print. `$` fails on 12 layouts, including all four Nordic
ones, and looks like a price on a slip. `#` fails on 12 and begins every Shopify order
reference. `.` and `,` fail on as few layouts as `%` but on a set the digits survive
(Turkish, Russian), and are easily missed in print. The rest fail on 17 or more.

## Trade-off

Codes are one character longer than the first draft's (`%S216` against `S216`), and a
PolyBag code is not something people recognize the way they recognize an order number.
The slip keeps the reference in large print for people, with the code beside it in small
type. A `%` on a slip does not say "PolyBag" the way `PB` did. Code 128 encodes the digit
run compactly, so the barcode grows by little. A deleted-and-reimported Shipment gets a
new ID, and its old slip then finds nothing. That is shown as "not found", never as a
different Shipment, except after a restore (decision 5).

## Consequences

- The pick-batch pack slip's barcode changes from the order reference to `%S<id>`. Slips
  printed with a reference keep working through the reference lookup.
- Scan & Pack no longer opens the first of several Shipments sharing a reference.
- Global search resolves PolyBag codes exactly, and opens a single result on Enter.
- Print Command Barcodes prints the new commands. Old `*` sheets must be reprinted.
- Print Box Size Barcodes prints `%B<id>`, with the alias beside it. Box sheets printed
  with aliases keep working.
- Box size validation rejects aliases beginning with `%`.
- Station setup includes setting the scanner's keyboard country to the host's layout,
  and the self-hosting guide says so.
- `SCAN_CODE_PREFIX` is removed, along with the self-hosting guide's instructions for
  choosing and changing it. Anything printed with a `PB` prefix during development must
  be reprinted.
- Every new scannable code is placed in this grammar by amending this ADR.

## Implementation

1. A fixed `%` prefix, and a parser returning the type and body, or "unrecognized".
2. The pack slip encodes `%S<id>`, with the code printed beside the reference.
3. Scan & Pack routes PolyBag codes to the parser and external strings to the reference
   lookup, which lists several matches to choose from.
4. A global search provider resolves PolyBag codes exactly and defers everything else to
   Filament. Enter opens a single result.
5. Commands renamed, and Print Command Barcodes reprinted. The last Label is remembered
   by Label ID.
6. Box Size codes on the Pack page and the box barcode sheet.
7. Box alias validation (`scan-codes/02`).
8. Tracked in `docs/issues/archive/scan-codes/`: Box Size codes in global search (`04`)
   and the explicit external lookup (`01`) are `wontfix`; the collision message on failed
   scans (`03`) is superseded by the fixed prefix.
