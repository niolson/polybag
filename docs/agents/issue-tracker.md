# Issue tracker: local Markdown, with GitHub Issues as the front door

There are two things here and they are not the same thing.

**GitHub Issues is the front door.** It is where reports and requests arrive from
outside, using the templates under `.github/ISSUE_TEMPLATE/`. Nobody can reasonably file
a Markdown-file issue by pull request, so the intake has to be GitHub.

**`docs/issues/` is the tracker of record.** Planned work lives here as Markdown
committed to the repo, so that history, comments, and triage state survive machine loss,
are visible to an agent working in a fresh clone or worktree, and — the point — change in
the *same commit and the same pull request* as the code they describe.

The traffic runs one direction. A GitHub issue that survives triage becomes a Markdown
issue here; the GitHub issue is then closed with a link to the file. **Never sync the two.**
A GitHub issue mirrored as a Markdown file, or the reverse, gives an agent two places to
look and two answers to believe.

`.scratch/` is gitignored and is **not** the issue tracker. It holds throwaway working
artifacts: scan output, security evidence, vendor spec dumps, binaries, and raw API
captures that carry customer addresses.

## Conventions

- One feature per directory: `docs/issues/<feature-slug>/`
- The PRD is `docs/issues/<feature-slug>/PRD.md`
- Implementation issues are `docs/issues/<feature-slug>/issues/<NN>-<slug>.md`, numbered from `01`
- Triage state is recorded as a `Status:` line near the top of each issue file (see `triage-labels.md` for the role strings)
- Comments and conversation history append to the bottom of the file under a `## Comments` heading
- **A long comment history may be compressed**, and `shopify-shipping-carrier` has been.
  Findings that still constrain future work move up into the body — where the current
  state of a thing belongs — and the comments collapse to dated entries of a line or
  three. A self-correction folds into the corrected statement rather than surviving as
  both halves of an argument. What must not be lost is a reason a decision went the way
  it did, a rejected option and why, or a defect that a later change could reintroduce
- A file that is getting hard to read from the top is the signal to compress it. An issue
  is read by someone deciding what to do next, and a narrative of how the answer was
  reached buries the answer
- **Resolved issues stay in place.** Set `Status: done` and leave the file where it is —
  the implementation record under `## Comments` is why the file is kept. Nothing is
  deleted or moved to an archive; `README.md` in `docs/issues/` is the index of what is
  still open, and the `Status:` grep there is how you get that view

## This is a public repository

Everything written here is published, and stays published in git history even if a later
commit deletes it. Before committing an issue file — **or a test fixture, a factory
default, a seeder, or an example in a doc**, which is where real values actually tend to
reach a public repo — check it does not carry:

- Hostnames, IP addresses, container names, or tenant names from a deployment
- Absolute paths from anyone's machine
- Real order IDs, tracking numbers, customer addresses, or account identifiers
- Names of private repositories, or file-level links into them
- Tenant IDs, client IDs, or any other credential-adjacent identifier

Say the general thing instead — "our OAuth broker", "a development store", "the seller
account". Where the specific value genuinely matters to the work, put the capture in
`.scratch/` and reference it by path.

In a fixture, use an obviously synthetic value. Real ones get pasted in during debugging
because they are what was to hand, and then nothing ever prompts anyone to take them out
— the test passes either way. A tenant ID reached `main` exactly like that and lived
there from #92 until #174.

Two things that make the substitution less trivial than it looks, both learned from that
one:

- **Search case-insensitively.** GUIDs and codes get written in both cases, and a test
  that exercises canonicalization will contain both spellings of the same value on
  purpose. Replacing only the lower-case one leaves the value in the file.
- **Keep the shape of the thing you replaced.** A canonicalization test feeding an
  upper- and lower-case spelling still passes if you substitute a digits-only GUID, but
  it is no longer testing anything, because both spellings became the same string.

## When a skill says "publish to the issue tracker"

Create a new file under `docs/issues/<feature-slug>/` (creating the directory if needed).
Not `gh issue create` — that is the intake, not the tracker.

## When a skill says "fetch the relevant ticket"

Read the file at the referenced path. The user will normally pass the path or the issue
number directly. If they pass a GitHub issue number, read that with `gh issue view` — it
will be an untriaged inbound report.

## Relationship to ROADMAP.md

`ROADMAP.md` holds durable strategic intent — what we plan to build, in what order, and
why, including explicitly deferred work and architectural guardrails. Issues hold the
how: specific work sliced into independently grabbable tickets, with test notes and
implementation history.

Don't duplicate between them. A roadmap entry may reference an issue directory once work
on it starts.

## Work that is not here

Deployment and hosting work for the instances we operate is tracked in a private
repository alongside that tooling. Two directories here are the app half of a cluster
whose other half is private; each says so where it matters. Nothing open in
`docs/issues/README.md` is blocked on it, and you should never need to reach for it.
