# Roadmap

Planned features and improvements for Filament Shipping.

## Shipping

- [x] **Delivery date estimates** — Get estimated delivery dates from carrier APIs and filter out shipping methods that won't arrive by the shipment's deliver-by date. Show all options with a warning if nothing meets the deadline.
- [x] **Filter rates by carrier services** — On the ship page, only show rates for carrier services that are configured for the shipment's shipping method.

## Pack Page

- [x] **Loading state** — Show a spinner and disable form inputs while waiting for auto-ship response or ship page navigation, preventing duplicate submissions.

## Import Sources

- [x] **Shopify GraphQL** — Import orders and export fulfillments via Shopify Admin API.
- [ ] **Amazon SP-API** — Import orders via Amazon Selling Partner API.

## Carriers

- [x] **UPS** — Rate quotes, label generation, tracking.
- [ ] **DHL** — International shipping focus.

## WMS (Warehouse Management)

Future direction — may or may not be in scope depending on product direction.

- [ ] **Inventory tracking** — Track stock levels by product/SKU, receive inventory, adjust quantities.
- [ ] **Pick list generation** — Generate pick lists from pending shipments, optimized by warehouse location.
- [ ] **Pack/ship workflow improvements** — Tie inventory into the existing pack flow, decrement stock on ship.
