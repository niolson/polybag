# Label history on the Package, and a tracking lookup that finds voided labels

Status: done

Repo: `polybag`

## Problem

The user-facing payoff. After `02` the data exists; nothing shows it, and global search
still matches only `packages.tracking_number`, which a voided label no longer appears
in.

## What to build

### `ViewPackage`

A **Labels** section listing every `PackageLabel` for the package, newest first: purchased
when and by whom, carrier of record and service, tracking number, cost, whether and when
it last printed, and for voided rows the void time, reason and who. The active row is visually the active one.
This is a section on the Package, not a resource of its own — see the PRD's
non-goals.

Filament's `RelationManager` is the natural fit if the list wants actions later; a
read-only `RepeatableEntry` is enough if it does not. Choose the one that reads best
beside the existing sections on that page and say which in the PR.

### Tracking lookup

**The premise "add a searchable attribute" is wrong for this resource.** `PackageResource`
uses `InteractsWithScoutSearch`, which ignores `getGloballySearchableAttributes()` and
builds `LIKE` clauses from `Package::toSearchableArray()` against columns of the
`packages` table only. A relationship attribute would not be searched. And Filament's
result-title callback receives the Package but not the search term, so it cannot say
*which* label matched — the active one or a voided one.

So this is a custom `getGlobalSearchResults(string $search)` on `PackageResource` (or an
equivalent override of the concern's constraint method plus a title builder that is
handed the term), which:

- keeps today's behaviour for `packages.tracking_number` — same word-splitting, same
  prefix rules;
- additionally matches `package_labels.tracking_number` via a join or `whereHas`, and
  carries the matched label back to the result so the title can be built from it:
  `Package #123 — label voided 2026-09-12` for a voided match, today's title for an
  active one;
- de-duplicates a package that matches more than once. A direct match on the Package's
  active tracking number wins; otherwise the newest matching voided Label supplies the
  title. This also settles a Package that reused one tracking number across purchases.

The scan-a-stray-parcel scenario is the acceptance test: paste a voided label's tracking
number into global search and land on the package that explains it.

## Acceptance criteria

- [x] A package with one active and two voided labels shows all three on `ViewPackage`
      with the active one distinguished
- [x] Global search for a voided label's tracking number returns the package, labelled as
      voided — through the custom results method, with a test that the default
      `toSearchableArray()` path alone would fail
- [x] Global search for an active label's tracking number is unchanged, and a package
      whose active number also appears on a voided label appears once with the active
      result title; when several voided labels match and none is active, the newest one
      supplies the title
- [x] Screenshots in the PR — this is a Filament UI change
- [x] Livewire tests cover the section rendering and the search result

## Blocked by

`02`, which supplies everything this shows. If `03` or `04` is ever built, the section
gains a column; nothing here waits for either.

## Comments

- **2026-09-15** — Done, on `feat/package-label-history-view`. The section is a
  `RepeatableEntry` in table layout inside the `ViewPackage` infolist, not a
  `RelationManager`: `ViewPackage` renders `{{ $this->infolist }}` from its own Blade
  view, so relation managers never appear there (`PackageItemsRelationManager` shows on
  the edit page only), and switching the view to `{{ $this->content }}` turned the two
  managers into tabs that hid the labels behind a click. Nothing here wants actions.
  `VoidReason` gained `HasLabel` for the void column. The lookup is a full
  `getGlobalSearchResults()` override; `InteractsWithScoutSearch` now exposes its
  term-splitting and per-column matching (`applyGlobalSearchTerms()`) so the
  `package_labels` side uses the same rules as `packages.tracking_number`. Matching labels
  are eager-loaded on the result query, ordered newest purchase first; a package whose
  matched labels include the active one — or that matched only on its own column — keeps
  today's title, otherwise the first (newest) voided match supplies it. The voided title
  formats the date as `M j, Y` in the location's timezone, like every other date in the
  UI, rather than the ISO date the text above sketched. Result details for a voided match
  show the voided label's number and carrier, since the package's own may differ.
