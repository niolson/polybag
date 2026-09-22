# Find out how an Amazon Shipping account with no Seller Central account connects

Status: needs-info

Repo: `polybag`

Type: HITL. Needs an answer from Amazon, and ideally an Amazon Shipping-only account to
try it with.

## What to build

The 3PL case in ADR-0002's 2026-09-22 amendment assumes the 3PL connects its own Amazon
Shipping account as an Amazon connection with import off. That account may not be a Seller
Central account. According to Amazon's integration guide for shippers who only ship
off-Amazon, *"You are not required to create a Seller account to call the Shipping
APIs"*. Those shippers authorize through a "website workflow" whose sign-in and redirect
URIs are arranged with an Amazon Shipping Solutions Architect.

What we know about our side:

- The app registration already selects the *Shipping* business entity with its
  *Direct-to-Consumer Shipping* role, along with the *Sellers* roles. Amazon's docs
  describe an "Amazon Logistics" role for shippers who do not sell on Amazon. That is
  probably an older name for the same *Shipping* role, but it has not been confirmed.
- *Vendors* (Vendor Central) is deliberately left unselected. Vendor direct fulfillment
  runs through a separate set of APIs and is not Shipping v2.
- The Amazon connection's OAuth sends every account to Seller Central's consent page. An
  account with only Amazon Shipping may have nothing to sign in to there.

Questions to answer, most easily by writing to Amazon's Shipping integrations contact:

- Which consent URL does an account with only Amazon Shipping use? Is it the same as
  Seller Central's, or its own?
- Do the refresh token and the LWA exchange work the same as they do for a seller? Does
  the same SP-API host and `x-amzn-shipping-business-id: AmazonShipping_US` apply?
- Is the website workflow a one-time setup per app, or something each shipper does?
- Can one Amazon Shipping account ship from several sites, and does each site have to be
  onboarded? ADR-0002's amendment assumes it does not matter to routing. This would say
  whether `05` should expect site-level refusals.

If the answer is a different consent URL, the build is small: the connection form picks
Seller Central or Amazon Shipping and the OAuth broker follows. If it is a different
credential model altogether, this needs its own slice before a 3PL can use `04` onward.
The single-account seller does not depend on this.

## Acceptance criteria

- [ ] Each question above answered, or recorded as unanswerable, in `## Comments`
- [ ] If the connection flow must change, a follow-up issue is filed and linked from the
      PRD
- [ ] ADR-0002's open question is updated with the outcome

## Blocked by

None - can start immediately
