# Self-hosting PolyBag

PolyBag runs with no dependency on anything POLYBAG.APP LLC operates. Every
integration has a bring-your-own-credentials path, and this document is the map of
which credentials you need, where each one goes, and which parts of the `.env` and the
UI describe services you cannot use.

Read the [licence](../LICENSE) first. PolyBag is source-available under BSL 1.1, not
open source — running it in production for a commercial purpose is not permitted before
the change date. Self-hosting for personal, educational, evaluation, and development use
is.

## The parts that are ours, not yours

| Thing you will see referenced | What it actually is | What you do instead |
| --- | --- | --- |
| `OAUTH_BROKER_URL` / `OAUTH_BROKER_SECRET` / `OAUTH_INSTANCE_ID` | `connect.polybag.app` — a service we run that holds **our** carrier and IdP developer credentials | Leave all three empty. Register your own developer apps and enter the credentials in the app. |
| "Connect USPS" / "Connect UPS" / "Connect with OAuth" / "Connect Amazon" buttons | Broker-driven authorization-code flows | Disabled without a broker. Use the direct credential fields described below. |
| `noreply@updates.polybag.app` | Our Resend sending domain | Any Laravel mailer, sending from a domain you control. |
| `polybag-connect` | The broker's own repository. Not in this repo and not published. | — |
| `polybag-demo-data-tools`, `demo:reset` | Internal demo tooling | Ignore. See [Internal tooling](#internal-tooling). |
| Tenant provisioning / deploy / backup scripts | Multi-tenant control plane for our shared server. Not in this repo. | Use `scripts/install-onprem.sh`. |

### Why you cannot point at the broker

The broker exists so that hosted tenants do not each have to register a developer app
with FedEx, UPS, Shopify, Amazon, Google, and Microsoft. It holds those developer
credentials and signs every call with a shared secret tied to a registered instance ID.
Those are our credentials under our agreements with those vendors, so we do not issue
`OAUTH_BROKER_SECRET` to third parties, and there is no self-hostable build of the
broker. Please do not open an issue asking for one.

**Leaving the three `OAUTH_BROKER_*` keys empty is a supported configuration, not a
broken one.** The app checks for them and takes the direct path when they are absent.
`OAUTH_BYPASS_BROKER` only matters when `OAUTH_BROKER_URL` *is* set — it is a hosted
escape hatch for SSO, and self-hosters can ignore it entirely.

## Carriers

All carrier credentials live on **Carrier Account** records (Shipping Config → Carrier
Accounts), not in `.env`. Each account can be scoped to a location and/or client. The
fields you want are in the collapsed **Advanced / API App Credentials** section — its
description says most installations leave it empty and use the shared OAuth connection,
which is true of *hosted* installations. Self-hosted, that section is the main event.

### USPS

1. Register at [developer.usps.com](https://developer.usps.com/), create an app, and
   note the **Consumer Key** and **Consumer Secret**.
2. You also need a **CRID** (Customer Registration ID), a **MID** (Mailer ID, 9 digits),
   and an **EPS account** to pay for postage. USPS issues these through the Business
   Customer Gateway; the developer app alone does not buy labels.
3. Create a Carrier Account with carrier USPS. Put the consumer key and secret into
   **Advanced / API App Credentials** → Client ID / Client Secret. Put CRID, MID, and
   EPS Account into **USPS Credentials**. Leave EPS blank to auto-populate it from the
   token.
4. Use the **Test USPS Connection** action. It confirms the credentials work and reports
   whether the account gets `CONTRACT` (negotiated) or `RETAIL` pricing.

The app authenticates with the client-credentials grant. **Connect USPS** is the broker
flow and stays disabled.

### FedEx

1. Register at [developer.fedex.com](https://developer.fedex.com/), create a project,
   and generate both **test** and **production** API keys. Each gives an API Key and a
   Secret Key.
2. Create a Carrier Account with carrier FedEx. Put the production pair and the sandbox
   pair into **Advanced / API App Credentials**, and your FedEx shipping account number
   into **FedEx Account** → Account Number.

Skip the **Connect FedEx Account** wizard. It drives FedEx's Account Registration API to
provision *child* credentials under a parent CSP (Compatible Solution Provider) key.
Without a broker the app will call FedEx directly for that, but the flow still requires a
CSP parent account, which is a commercial arrangement with FedEx that a self-hoster
almost certainly does not have. Your own developer app plus an account number is the
straightforward path and supports rating, labels, tracking, and close-out.

`sandbox_mode` in App Settings picks which key pair is used. It is a single global toggle
across USPS, FedEx, UPS, and Amazon — flipping it moves all four at once.

### UPS

1. Register at [developer.ups.com](https://developer.ups.com/), create an app, and note
   the **Client ID** and **Client Secret**.
2. Create a Carrier Account with carrier UPS. Credentials go in **Advanced / API App
   Credentials**; your UPS account number goes in **UPS Account** → Account Number.

Client credentials cover rating, labels, tracking, and void. **Connect UPS** is the
broker flow and stays disabled.

One gotcha worth knowing before you debug it: Ground Saver / SurePost service codes are
seeded in the app, but UPS will not return rates for them until UPS support enables those
services on your account. This is an account entitlement, not a configuration error, and
it is missing in sandbox by default too.

### Verifying: your first rate quote

This is the end-to-end check that self-hosting actually works.

1. Set your ship-from company address in **App Settings**.
2. Create a FedEx or UPS Carrier Account as above, with real credentials, and leave
   **Sandbox mode** on in App Settings while you are testing.
3. Create a shipment. **Manual Ship** (`/manual-ship`) is the fastest route — no import
   source needed. Enter a deliverable destination address.
4. Pack it: scan or pick a box size, add the item, enter a weight. A USB scale is
   optional; the weight field accepts typed input.
5. On the Ship page, request rates. You should get a service list back with prices and
   delivery estimates from the carrier you configured.

If you want to exercise the packing and shipping UI before you have any carrier
credentials at all, set `FAKE_CARRIERS=true` in `.env`. That swaps in fake carrier
adapters and a fake address validator, so the whole flow runs without touching a carrier
API — or spending money on one.

## Address validation

Two validators run in a chain: `UspsAddressValidator` gets first attempt at US addresses,
and `GoogleAddressValidator` is the universal fallback — it handles everything USPS could
not attempt and is the only validator for non-US addresses.

The Google validator prefers the broker when `OAUTH_BROKER_URL` is set, and otherwise
calls Google directly with `GOOGLE_ADDRESS_VALIDATION_API_KEY`. `.env.example` used to
describe that key as "local dev only". **For a self-hosted install there is no broker, so
the direct key is your production path.** Set it and treat it as production configuration.

1. In Google Cloud, enable the **Address Validation API** on a project with billing.
2. Create an API key and restrict it to that API.
3. Set `GOOGLE_ADDRESS_VALIDATION_API_KEY` in `.env`.

Google bills per request. Leaving the key unset is also fine — the validator simply does
not attempt validation, and shipments stay in the "Not Checked" state.

## Mail

MFA login codes are delivered by email. If you turn on **Require MFA** in App Settings,
mail stops being optional plumbing and becomes part of your login path — configure and
test it first.

Any Laravel mailer works: `smtp`, `ses`, `postmark`, `resend`, `sendmail`, or `log` for
local development. We use Resend on the hosted service, which is why `RESEND_API_KEY`
appears in `.env.example`; it is only read when `MAIL_MAILER=resend` and is not a
requirement.

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="noreply@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Send from a domain you control and have authenticated with your provider (SPF, DKIM,
DMARC). MFA codes that land in spam are indistinguishable from a broken login.

## Single sign-on

SSO is optional and off by default; enable it in **App Settings → Authentication**. With
`OAUTH_BROKER_URL` empty, both providers go straight through Socialite using the
credentials in your `.env` — no `OAUTH_BYPASS_BROKER` needed.

Note that PolyBag does not create accounts from an SSO login. The user must already exist
and be active, matched by email address.

### Google

Create an OAuth 2.0 client in the Google Cloud console with the redirect URI
`https://your-host/auth/google/callback`.

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

### Microsoft Entra

Register an application in Entra ID with the redirect URI
`https://your-host/auth/azure/callback`.

```env
AZURE_CLIENT_ID=
AZURE_CLIENT_SECRET=
AZURE_TENANT_ID=common
```

Set `AZURE_TENANT_ID` to your directory's tenant ID to restrict sign-in to your
organisation; `common` allows any Microsoft account.

## Data sources and marketplace postage

Import and export sources are **Connection** records (Integrations → Connections), each
with its own encrypted credentials, schedule, and optional client assignment. Nothing
here needs `.env`. **Import Orders** on a connection is on by default; turn it off for a
connection that should only receive tracking or sell postage. A Shopify or Amazon source can also be the postage source for a Label;
that is separate from importing the Shipment, even though marketplace postage is bound to
the originating account because the order identity belongs to it.

### Database

No third-party registration at all — point it at a MySQL, PostgreSQL, SQL Server, or
SQLite database and give it your own queries. If you want a source that never touches an
external vendor, this is it.

### Shopify

1. Create an app for the store. A **custom app** in the store's own admin is the simplest
   route; a Dev Dashboard app also works. Either gives you a **Client ID** and **Client
   Secret** — note that neither hands you an access token directly.
2. Declare the scopes on the app. The importer reads `fulfillmentOrders`, order name and
   email, destination addresses, locations, and line item variants, and the export path
   calls `fulfillmentCreate` — so it needs read and write access to both
   merchant-managed and assigned fulfilment orders, plus orders, locations, and products.
   Getting this wrong fails quietly: an app scoped only for *assigned* fulfilment orders
   returns an empty list rather than an error.
3. Shopify gates customer PII (the email and destination address the importer needs)
   behind protected customer data access. Request it if your app type requires it.
4. Create a Shopify Connection with the store's `.myshopify.com` **Shop Domain**, and
   put the client ID and secret into **App Client ID** / **App Client Secret**.

Without a broker the source exchanges those credentials for a token directly against the
store using the client-credentials grant. **Connect with OAuth** is the broker flow and
stays disabled. The field help text calls these an override of "tenant-level"
credentials — on a self-hosted install there are no tenant-level credentials, so these
are simply the credentials.

Scopes are declared on the app itself, so the authorization request's scope parameter is
inert. Change the scopes on the app and reinstall it if imports come back empty.

To buy Shopify Shipping Labels, the app additionally needs `write_orders`,
`write_merchant_managed_fulfillment_orders`, and `read_products`; the Shopify staff user
must also have the **Buy shipping labels** permission. Enable **Allow blind purchase** on
the Client only after accepting Shopify's behavior: a packer sees no price, service, or
carrier before purchase, and Shopify never reports the final price or service. This path is
attended only—shipping rules, auto-ship, and batch shipping cannot choose it.

Shopify Shipping Labels cannot be voided through the API. Void one in the Shopify admin;
the scheduled `packages:sync-shopify-fulfillments` poll detects it, preserves the voided
Label as history, and returns the Package to unshipped. Tracking also comes through the
Shopify fulfillment rather than through one of your direct carrier accounts.

### Amazon SP-API

1. Register as a developer in Seller Central / Amazon Developer Central and create an
   SP-API application. You will get an **LWA client ID** and **client secret**.
2. Self-authorize the application against your own seller account to get a **refresh
   token**.
3. The importer uses the Orders and Catalog Items APIs, and it reads buyer shipping
   addresses — so the application needs the roles that cover personally identifiable
   information. Amazon approves those separately.
4. Create an Amazon Connection and fill in **Refresh Token**, **App Client ID**, **App
   Client Secret**, and the **Marketplace**. Marketplace discovery only runs after an
   OAuth connection, so pick from the listed North American marketplaces manually.

The app refuses to activate an Amazon source until **Require MFA** is enabled in App
Settings. That is deliberate — these sources expose customer PII — and it is another
reason to get mail working first.

Amazon Buy Shipping uses the same seller account and additionally requires access to the
Shipping v2 operations for rates, purchase, tracking, and cancellation. Eligible offers
appear only on Packages belonging to Shipments imported from that Amazon source. Amazon's
service catalog is discovered from live rate responses rather than maintained as a fixed
list: an operator may select a new service manually, but unattended flows require an
administrator to map it under **Integrations → Map Carrier Services** and approve it for
the Client. Sandbox and production approvals are intentionally separate.

That is the on-Amazon (`channelType: AMAZON`) workflow. Although Shipping v2 also accepts
off-Amazon orders (`channelType: EXTERNAL`) and can sell Amazon Shipping labels for them,
PolyBag does not implement that workflow yet. Connecting an Amazon account and completing
Amazon Shipping onboarding therefore does not currently make Amazon Shipping appear for
Shopify, database-imported, manual, or other non-Amazon Shipments.

## Upgrading

The `app` container's entrypoint runs `php artisan migrate` before it starts php-fpm,
and the `queue`, `import-queue` and `scheduler` containers wait for `app` to report
healthy — which is after the migration — before they start. What that does not cover is
a worker from the *previous* image that is still running when the migration starts: a
label purchase it finishes after a data migration has swept the table is invisible to
that migration.

So a release is three steps, not one:

```bash
COMPOSE="docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml"

$COMPOSE stop queue import-queue scheduler
$COMPOSE up -d --build app            # migrates, then reports healthy
$COMPOSE up -d --build                # the workers start against the migrated schema
```

If a purchase did slip through, `php artisan app:verify-label-integrity` names it, and
`--repair` records the label row it is missing from the package's own columns. The
report runs nightly on the scheduler; the repair is yours to run.

## Internal tooling

Some things in this repo exist for our own deployment and will not be useful to you.
They are not broken; they are just not aimed at you.

- **`php artisan demo:reset` and the demo banner widget.** These reset a demo
  environment: they wipe transactional tables, re-seed shipment history from an active
  **Database** data source, and fabricate shipped packages so the dashboard has a
  believable shape. The command is gated to `APP_ENV` of `demo`, `local`, or `testing`,
  and the wrapper that fills our demo import database to the current date lives in a
  private repo. There is no schema documentation for that import database because it is
  simply whatever your Database data source's queries return — but the intended audience
  is our sales demo, and you should assume it is internal.
- **Tenant provisioning, deploy, backup, and secret-rotation scripts.** These assumed
  our `/opt/tenants` multi-tenant server layout and are no longer in this repo.
  `scripts/install-onprem.sh` is the self-host installer and is not going anywhere.
- **The shared MySQL/Redis stack**, likewise. The `.cnf` files under `infra/shared/` are
  *not* internal and stay — `docker-compose.yml` mounts all three into the standalone
  MySQL service and they are required together. See [`infra/README.md`](../infra/README.md).

## Related documents

- [`README.md`](../README.md) — features, hardware integration, setup, and the licence.
- [`CONTRIBUTING.md`](../CONTRIBUTING.md) — development loop and the contributor agreement.
- [`SECURITY.md`](../SECURITY.md) — private vulnerability reporting.
- [`docs/qz-tray-provisioning.md`](qz-tray-provisioning.md) — suppressing the QZ Tray
  trust prompt on workstations.
