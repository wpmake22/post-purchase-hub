# Post-Purchase Hub for WooCommerce — Product & Engineering Specification

**Document status:** Phase 0 deliverable (pre-implementation)
**Working plugin name:** Post-Purchase Hub for WooCommerce
**Slug:** `post-purchase-hub` · **Text domain:** `post-purchase-hub` · **Prefix:** `pph_` · **Namespace:** `PostPurchaseHub\`
**Target:** WordPress 6.5+ · PHP 8.1+ · WooCommerce (latest − 2) · HPOS-first

> Naming note: WP.org and Woo trademark policy forbids leading with "WooCommerce". "Post-Purchase Hub for WooCommerce" is compliant; "WooCommerce Post-Purchase Hub" is not.

---

# PHASE 1 — PRODUCT ANALYSIS

## 1. Core problem

Two problems are being conflated in the brief, and they are not equally unsolved.

**Problem A — status opacity (WISMO).** The customer cannot tell where their order is, so they email the store. This is the problem the brief's evidence base measures.

**Problem B — action helplessness.** The customer *knows* where the order is but cannot *do* anything: cancel it, change it, return it, get an invoice, buy it again. Every one of those becomes a human email thread with a merchant.

The brief builds its business case on A and its feature list mostly on B. That mismatch matters, because **A is substantially solved already in the free WooCommerce ecosystem and B is not.** See Phase 2.

**Recommended core problem statement:** *WooCommerce gives customers a read-only order page. Every post-purchase intent — cancel, return, reorder, invoice, get help — is a support ticket. This plugin converts read-only order history into a self-service action surface.*

## 2. Target user

Primary: a WooCommerce store owner or manager doing 100–5,000 orders/month, on a stock or lightly customised theme, with 1–3 people handling support, no dedicated developer. Physical goods, mixed one-off and repeat purchase, single vendor, single currency.

Secondary: agencies/freelancers building client stores who want one plugin instead of five.

**Explicitly not the target:** sub-50-orders/month hobby stores (no pain — three emails a week is not a workflow problem) and 20k+/month operations (already on Gorgias/Richpanel/AfterShip Pro, and will want carrier webhooks and SLA reporting you are not building).

This is worth stating plainly because **WP.org's free funnel skews heavily toward the group with no pain.** Install count and revenue will decouple more than the brief assumes.

## 3. Primary use case

A logged-in or guest customer opens the order page (from an email link or their account), sees an unambiguous progress state with a date expectation, and completes an intent — cancel, return, reorder, download invoice, ask for help *with order context already attached* — without emailing anyone.

## 4. Secondary use cases

1. Merchant triages cancellation and return requests from one queue instead of an inbox.
2. Guest customer retrieves an order without creating an account.
3. Merchant standardises the post-purchase experience across a portfolio of client stores.
4. Support agent sends a customer a single signed link that shows exactly what the customer sees.
5. Repeat-purchase stores (coffee, supplements, consumables) drive reorder revenue from the order page.

## 5. Why someone installs this

Not "because my orders page is ugly." They install it because a specific recurring email is annoying them. In descending order of likely trigger:

1. "Can you cancel my order?" — recurring, urgent, no native self-service at all.
2. "Where is my order?" — high volume, partly already solved.
3. "I want to return this" — lower volume, high emotional cost, no free solution.
4. "Can I get an invoice?" — low volume, trivially annoying.

**The install trigger is #1, not #2.** Marketing copy should lead there.

## 6. What WordPress / WooCommerce already solves

| Capability | Native status |
| --- | --- |
| Order history list | ✅ `myaccount/orders.php`, paginated |
| Order detail view | ✅ `order-details.php` + customer details |
| Guest order retrieval | ⚠️ Partial — `[woocommerce_order_tracking]` shortcode does order-ID + email lookup already. Ugly, status-only, no actions, but it exists and it is core. |
| Customer-initiated cancellation | ⚠️ Only for `pending`/`failed` via the order-pay cancel link, with `order_key` in the URL |
| Reorder | ⚠️ `order_again` exists natively but is exposed only for `completed` orders and only via `woocommerce_valid_order_statuses_for_order_again` |
| Order status transitions + notes | ✅ `woocommerce_order_status_changed`, order notes with timestamps |
| Downloadable invoice | ❌ |
| Returns / RMA | ❌ |
| Delivery date estimate | ❌ |
| Structured status timeline | ❌ |
| Order actions API on My Account | ✅ `woocommerce_my_account_my_orders_actions` filter — the correct extension point, already there |

**Consequence:** reorder and basic guest lookup are not net-new engineering, they are *re-presentation* of existing core primitives. Budget them as small. Do not price them as differentiators.

## 7. What should NOT be built

Ranked by how much money and reputation each would burn.

1. **Carrier API integrations.** Correct call in the brief; hold the line permanently. 350+ carriers, rate limits, per-carrier auth, breaking changes. AfterShip exists. Read tracking data, never fetch it.
2. **A PDF invoice generator.** Looks like a two-hour job, is not. dompdf/mPDF adds ~4MB of vendor code, and legally compliant invoicing is jurisdiction-specific (EU sequential numbering, VAT/GST fields, credit notes, e-invoicing mandates). Free tier should link to the existing print view and detect installed invoice plugins; Pro should integrate with them, not replace them. If a built-in generator ever ships, ship it as a separate add-on with its own support burden.
3. **Automatic gateway refunds on cancellation.** See Phase 8. One wrong refund is a 1-star review with a euro figure in it.
4. **Store credit / wallet.** A ledger is a product, not a feature. Money-holding features attract fraud, accounting disputes and regulatory questions.
5. **Exchange flows.** Return + new order + price-difference reconciliation + inventory reservation. This is 3× the returns workflow.
6. **Live chat / ticketing.** "Get Help" is a form that hands off with context. It is not a helpdesk.
7. **Photo uploads on return requests in v1.** Every uploaded-file feature is an RCE and storage-abuse surface. Post-MVP, with a hard whitelist.
8. **SMS.** Twilio credentials, opt-in compliance (TCPA/GDPR), per-country sender rules.
9. **AI anything in v1.**
10. **A page-builder / drag-drop layout editor for the orders page.** Wpmet, Iconic, Divi and others already own account-page *design*. Competing on design is competing with builders. Compete on *behaviour*.

## 8. Potentially unnecessary features (from the brief)

- **Buy Again as a headline feature.** Amazon's Buy Again works because Amazon's catalogue is consumables. A typical Woo store selling a $60 one-off product gets near-zero engagement. Build it (it is ~8h, it wraps `order_again`) but do not lead with it or price against it.
- **Custom order statuses (Pro).** Sounds cheap, is not: every custom status has to be mapped into the timeline, into email triggers, into cancel/return eligibility rules, and into every third-party plugin's assumptions. Defer to 1.2 and only ship it with a mapping UI.
- **White-label.** Real demand comes from agencies, who are not your first 100 customers. Cheap to add later; adds a settings surface now.
- **Multi-vendor.** A separate product.

## 9. Technical risks

| # | Risk | Severity | Mitigation |
| --- | --- | --- | --- |
| T1 | **My Account template overrides.** Every theme (Astra, Kadence, Blocksy, Divi, Flatsome, Woodmart) and every builder add-on (Elementor Pro, JetWooBuilder, Wpmet, Iconic) styles or replaces `myaccount/orders.php` and `view-order.php`. Full template replacement guarantees visual breakage across a long tail you cannot test. | **Critical** | Do not replace templates by default. Ship additive mode as the default (Phase 6). Replacement is opt-in with an automated conflict pre-check. |
| T2 | **Missing timeline timestamps.** Woo persists only `date_created`, `date_paid`, `date_completed`, `date_modified`. There is no per-status transition history, so a "real timeline" cannot be rendered for orders that predate the plugin. | High | Record forward transitions ourselves in bounded order meta; degrade gracefully for historical orders (stage shown, timestamp omitted). Never parse localised order notes as a data source. |
| T3 | **Tracking-number dependency.** The single biggest WISMO lever is delegated to a plugin you do not control and to merchant data-entry discipline. If tracking is absent, your timeline says "Shipped" with no date — the exact vague-window failure the brief cites Salesforce on. | **Critical** | Ship estimated delivery dates computed from handling time + shipping method as an MVP feature (not v1.1). This is the only part of the WISMO promise you can keep unilaterally. |
| T4 | HPOS vs legacy post storage, and stores mid-migration | High | CRUD API only. `wc_get_orders()`. `OrderUtil::custom_orders_table_usage_is_enabled()`. Zero direct `postmeta` reads. Declare `custom_order_tables` compatibility. Test both modes in CI. |
| T5 | Subscriptions / Bookings / Deposits / Memberships order types, where "Cancel" and "Buy Again" have different or dangerous semantics | High | Whitelist order types and product types. Hard-exclude subscription parent/renewal orders and bookable products from cancel and reorder in v1; make the exclusion filterable. |
| T6 | Caching layers (WP Rocket, LiteSpeed, Cloudflare APO) caching a guest order page | High | `DONOTCACHEPAGE`, `Cache-Control: private, no-store`, nocache headers on all order views. Never expose order data in a GET query string that could be cached or logged. |
| T7 | Gateway heterogeneity on cancellation and restock | Medium | Never touch the gateway in v1. Status change + optional restock only. |
| T8 | Translation plugins (WPML/Polylang) — endpoint slugs, email language, signed links across language prefixes | Medium | Use Woo endpoints rather than custom rewrites where possible; resolve customer locale from the order, not the request. |
| T9 | Email deliverability of signed links (link scanners pre-fetching one-time tokens) | Medium | Tokens must be idempotent and multi-use within their TTL. Never one-time-burn a token on GET. |
| T10 | Block-based ("Customer Account" block) vs classic My Account | Medium | Provide a block + shortcode as first-class render paths, not afterthoughts. |

## 10. Security risks

Full treatment in Phase 8. Headline items:

- **IDOR on order IDs.** Woo order IDs are sequential and guessable. Every read and every action must verify ownership, never infer it.
- **Order-number enumeration via guest lookup.** The classic Woo vulnerability class. Requires rate limiting, uniform error responses, and no existence oracle.
- **Signed-link scope creep.** A link that logs the user in is an account-takeover primitive. Tokens grant order-scoped read + action capability only, never a WP session.
- **Unauthenticated state mutation.** Cancel and return endpoints are the highest-value targets in the plugin: an attacker who can guess an order can cancel a stranger's order.
- **Stored XSS via return reasons and help messages**, rendered in wp-admin and in emails.
- **Privilege boundary on the admin queue** — approving a refund-adjacent action must require `edit_shop_orders`, not `manage_options` and not `read`.

## 11. Performance risks

- Orders-list rendering must not add a query per row. Timeline data must be read from order meta already loaded by the CRUD object, or batch-primed.
- Reorder stock/variation validation must be bounded — cap items validated per reorder attempt.
- The admin queue must be a real paginated `WP_List_Table` over an indexed custom table, never `SELECT *` into PHP.
- Assets must be conditionally enqueued: only on My Account/order endpoints, the lookup page, and the plugin's own admin screens. No global frontend CSS.
- Rate-limit counters must not be `wp_options` rows (autoload bloat, no expiry). Use transients/object cache with a documented fallback.

## 12. WordPress compatibility risks

WP 6.5→latest, PHP 8.1/8.2/8.3/8.4, Woo L-2, HPOS on/off, classic vs block My Account, the theme long tail (T1), page builders, WPML/Polylang, AST (70k+ installs), the official Shipment Tracking extension, WooCommerce Subscriptions, PDF invoice plugins, and multisite.

## 13. UX problems

1. **Two orders pages.** If the plugin adds a hub alongside the native list, customers see duplicate order UIs. Additive mode must *enhance in place*, not add a parallel tab.
2. **Timeline dishonesty.** A five-stage timeline on a store that only ever uses Processing → Completed will show three permanently-empty stages, which reads as broken. Stage set must adapt to the statuses the store actually uses (detectable from history at setup).
3. **Cancel ≠ cancelled.** Customers read "Cancel order" as "it is cancelled." Request-based flows must say "Request cancellation" and show a pending state with an explicit expectation ("we'll respond within X hours").
4. **Reorder surprises.** Prices change, variations get deleted, stock runs out. Silent partial cart adds destroy trust; show an explicit reconciliation summary before the cart.
5. **Guest-lookup dead ends.** Wrong-email failures must be helpful without being an oracle. Best pattern: on failure, always say "if that order exists, we've emailed a secure link to the address on file" and actually send it — deflects the ticket *and* removes the oracle.
6. **Merchant-side ambiguity.** A cancellation request the merchant does not see for six hours is worse than an email. Admin notification + a counter bubble in the menu are not optional polish.

## 14. Abuse / misuse scenarios

| Scenario | Control |
| --- | --- |
| Enumerate order numbers to harvest customer names/addresses | Rate limit per IP + per email, uniform responses, mandatory email match, optional CAPTCHA hook, structured failure logging |
| Cancel a stranger's order | Ownership verification on every mutation; signed token bound to order + order_key; request-based approval for anything with financial impact |
| Return-request spam / free-goods fraud | Per-order request caps, cooldown, eligibility windows, merchant approval required, no automatic refund |
| Mail-bomb a third party via "email me a link" | Rate limit by email hash; send only to the address on file for the order, never to a submitted address |
| Reorder-endpoint cart abuse | Nonce + rate limit + item cap |
| Malicious payload in return reason → admin XSS | Strip tags, length cap, sanitize on write, escape on output, escape in email context |
| Signed link forwarded / leaked | Short TTL, no session creation, order-scoped, revocable by rotating order key |
| Timing attack on lookup | Constant-ish response path; do the email comparison with `hash_equals` after normalisation |

## 15. Monetisation

Free (WP.org) → Pro add-on plugin, mirroring the existing WPEverest pattern (free base on WP.org, Pro as a dependent plugin with house licensing). Do not add Freemius — the revenue share and the data-collection prompts are not worth it when in-house licensing infrastructure already exists.

Split rationale in Phase 4. Principle: **free is complete for a small store; Pro monetises operational volume and workflow, not basic dignity.**

---

## Critical assumptions — challenged

### A1. "The timeline + guest lookup is the wedge." — **Wrong, and this is the most important correction in this document.**

Advanced Shipment Tracking is free, has 70,000+ active installs, and already ships a customisable tracking widget on both My Account orders history and View Order, plus a Shipped/Partially Shipped/Delivered fulfilment workflow. Paid competitors (Shipment and Order Tracking on the Woo marketplace) already advertise a *fully customisable order timeline with labels, icons and timestamps per status update*, plus a guest tracking page with email + order ID and reCAPTCHA. WooCommerce core also already ships `[woocommerce_order_tracking]`, and native Order Fulfillments has been moving through beta.

So: timeline = contested. Guest status lookup = contested, and partly in core. **The uncontested territory is the action layer** — cancel, return, reorder, invoice, contextual help, unified in one place with a merchant-side approval queue. Nobody has assembled that, and the tracking incumbents have no reason to.

Repositioning consequence: build the timeline because the action buttons need a container, but sell the actions.

### A2. "WISMO is 25–40% of support volume, so the pain is worth $99/yr." — Directionally true, wrongly scoped.

Those benchmarks come from enterprise/DTC contexts and vendor blogs. They are true of stores that already pay for post-purchase tooling. The WP.org free funnel delivers mostly stores where 15% of six emails a week is one email. Do not build a business model that assumes the median installer feels the benchmark's pain. Assume a **1–2% free→paid conversion at best**, and design the free tier to be worth shipping on its own.

### A3. "Self-service tracking cuts WISMO 50–70%." — True, but not achievable by this plugin as scoped.

That reduction comes from *precision* — a specific arrival date. This plugin explicitly does not integrate carriers, so its shipped-state precision is entirely inherited from a third-party plugin plus merchant data entry. **Estimated delivery dates from handling time + shipping method must move into the MVP.** It is roughly 8 hours of work and it is the only part of the WISMO claim you can deliver without a dependency.

### A4. "The moat is that tracking vendors won't build cancellation." — Weaker than stated.

AST has already built a fulfilment workflow and a customer-facing timeline; it is drifting toward the same territory from the other direction, with a 70k-install head start. The durable moat is not category boundaries, it is **being the store's canonical order-page template plus holding the request data** — once cancellations and returns live in your tables and your merchant's workflow, removal is a migration, not an uninstall.

### A5. "OrderBoss at 0 reviews means distribution failure." — Partly. There is a better explanation.

Shopify's native customer accounts already cover order history, status and reorder reasonably well, so the gap OrderBoss addressed was small on that platform. In WooCommerce the same gap is genuinely large. This *strengthens* the Woo case, but it also means Shopify is a poor validation proxy — and it undercuts the brief's suggested Shopify-as-distribution path for *this specific* product.

### A6. "MVP is 4–6 weeks for one senior dev." — Only if returns move out of the MVP.

The brief's MVP list includes item-level return requests, an admin queue for both request types, invoices, and guest lookup. Realistic estimate is ~250h for the free scope alone (Phase 10). Returns adds ~90h. 4–6 weeks (≈240h) buys the free tier and nothing else.

### A7. Unstated assumption: "merchants want to hand cancellation control to customers."

Many will not — cancellation touches revenue and a chunk of "cancel" requests are salvageable with a discount. If the plugin's default is customer-initiated auto-cancel, a segment will uninstall on principle. **Default must be request-and-approve, with auto-approve as an explicit opt-in** limited to unpaid/pending orders.

---

## Feature classification

### Must Have — MVP (free)

1. Order timeline (derived stages + recorded transition timestamps, adaptive to statuses in use)
2. **Estimated delivery date range** (handling time + shipping method) — *added to MVP; see A3*
3. Additive rendering into native orders list + view-order (default), with opt-in full replacement, shortcode, and block
4. Contextual action buttons per status
5. Cancellation **requests** (rules: allowed statuses, time window, payment-method exclusions) + merchant approval
6. Reorder with explicit reconciliation summary
7. Guest order lookup (order number + email) + signed order links in transactional emails
8. Tracking display read from AST / official Shipment Tracking / Woo native fulfilment fields via an adapter layer
9. "Get Help" form pre-filled with order context
10. Invoice access via existing print view / detected invoice plugin
11. Admin request queue for cancellations (list table, approve/decline, notes)
12. Transactional emails both directions
13. Settings + guided first-run setup
14. Uninstall/data-retention handling

### Should Have — Post-MVP (v1.1, Pro)

15. Return / RMA request workflow (item-level, quantities, reason codes, windows, restock on approval)
16. Item-level cancellation (cancel part of an order)
17. Built-in PDF invoice (as an *integration* first; own generator only if forced)
18. Rules engine (per-category/per-product/per-shipping-class eligibility)
19. Bulk actions + saved views in the admin queue
20. Return shipping instructions + merchant-supplied label upload
21. Request analytics (rates, reasons, top returned products)

### Could Have — Future (v1.2+)

22. Custom order status mapping UI
23. Photo/file upload on returns (hardened)
24. Review-request prompt on delivered orders
25. Store-credit-as-outcome via integration with an existing wallet plugin
26. REST API for headless / mobile
27. Webhooks on request lifecycle events
28. White-label + agency multisite licensing
29. Multi-vendor awareness (Dokan/WCFM)
30. Customer-facing "where's my refund" state

### Avoid

Carrier APIs · own PDF engine as v1 scope · automatic gateway refunds · wallet/ledger · exchanges · live chat/helpdesk · SMS · AI · drag-drop layout builder · replacing invoice or tracking plugins · subscription management

---

# PHASE 2 — COMPETITIVE / PATTERN ANALYSIS

## Competitive map (verified)

| Player | Covers | Does not cover | Threat |
| --- | --- | --- | --- |
| **Advanced Shipment Tracking (free, 70k+ installs)** | Tracking display on My Account orders list + view order, customisable widget, Shipped/Partially Shipped/Delivered workflow, 1000+ carriers, bulk CSV, REST push, marketplace support | Cancellation, returns, reorder, invoices, guest lookup with actions, request queue | **High.** Closest adjacent incumbent with the largest install base. Treat as an integration partner in v1 and assume it may compete by v2. |
| Official WooCommerce Shipment Tracking | Tracking on order page + emails, REST, HPOS | Everything else | Low; strategically useful as a data source |
| Shipment and Order Tracking (paid, Woo marketplace) | Customisable timeline, guest tracking page w/ reCAPTCHA, 350+ carriers | Actions, requests, returns | Medium — already sells "timeline" |
| WooCommerce core | Order history, `[woocommerce_order_tracking]`, `order_again`, pending-order cancel link, order actions filter | Structured timeline, returns, request workflow, ETA | Medium — core absorbs features over time; Order Fulfillments signals movement here |
| RMA plugins (WooCommerce Returns/Warranty, ARMS, etc.) | Returns workflow | Timeline, cancellation, reorder, ETA, unified page | Medium |
| Cancellation plugins (Order Cancellation & Returns, WC Cancel Order, official Customer Order Cancellation) | Thin single-purpose cancel | Everything else | Low |
| Account-page builders (Wpmet ElementsKit, Iconic Account Pages, Divi/Elementor) | Layout, tabs, endpoints, dashboards | Behaviour, requests, workflow | Low — but they own "design", so do not compete there |

**Net read on positioning:** the differentiated claim is *"the only WooCommerce plugin where your customers can cancel, return, reorder and get help themselves — and you approve it all from one queue."* Tracking and timeline are supporting cast.

**Revised score: 7/10** (down from 8/10). The demand evidence is still the best in the idea set; the greenfield is narrower than the brief assumes, and the free tier overlaps a 70k-install incumbent.

## Pattern-by-pattern assessment

| Pattern | User problem | Proposed solution | Complexity | Business value | MVP? |
| --- | --- | --- | --- | --- | --- |
| **Onboarding** | Plugin activates and nothing visibly changes; merchant can't tell if it worked | 4-step guided setup: detect statuses in use → map to stages → set handling time → choose additive vs replacement with live preview | Medium | **High** — directly drives activation and reduces "not working" support | ✅ |
| **Settings** | Five plugins meant five screens; don't recreate that | Single tabbed screen under WooCommerce → Post-Purchase: General, Timeline, Actions, Guest Access, Emails, Advanced | Low | High | ✅ |
| **Admin dashboard** | Merchant needs to know what needs action | Deliberately *not* a dashboard in v1. A request queue with a pending count is the dashboard. | Low | Medium | ✅ (queue only) |
| **Request queue (list table)** | Requests buried in email | `WP_List_Table` over indexed custom table: filters by type/status/date, row actions, order deep links, menu bubble | Medium | **High** — this is the merchant-side product | ✅ |
| **Notifications (admin)** | Silent requests are worse than emails | Email on new request + admin bar/menu counter | Low | High | ✅ |
| **Notifications (customer)** | "Did they get my request?" | Woo-native `WC_Email` classes: request received, approved, declined, timeline advanced | Low–Medium | High | ✅ |
| **Search / filtering (customer)** | Long order histories | Native pagination is adequate for the median store | Low | Low | ❌ v1.1 |
| **Automation** | Manual approval doesn't scale at volume | Auto-approve rules (status/time/amount thresholds), opt-in, never default | Medium | Medium | ❌ v1.1 |
| **Integrations** | Tracking data lives in other plugins' meta | Adapter interface + concrete adapters (AST, official, native fulfilment, generic filter) | Medium | **High** — "integrate, don't compete" made real | ✅ |
| **Analytics** | Which products get returned and why | Aggregate reporting over request tables | Medium | Medium (Pro hook) | ❌ v1.1/Pro |
| **Roles & permissions** | Support staff shouldn't need full shop-manager | Map to existing `edit_shop_orders`; no custom caps in v1 | Low | Low | ✅ (existing caps) |
| **Import/export** | Migrating off a competitor | Not a real v1 need — no incumbent to migrate from | Low | Low | ❌ |
| **REST API** | Internal frontend needs endpoints anyway | `pph/v1` namespace for the plugin's own actions, documented but not marketed | Medium | Medium | ✅ (internal) |
| **Webhooks** | Ops teams want lifecycle events | Woo webhook topics on request events | Low | Low | ❌ v1.2 |
| **Background processing** | Bulk operations, retention cleanup | One daily WP-Cron cleanup job. Action Scheduler only if bulk ops ship. | Low | Low | ✅ (cron only) |
| **Logs** | "The customer says they cancelled and we never saw it" | Immutable request event log rows + Woo order notes for anything order-affecting | Low | **High** — halves support-diagnosis time | ✅ |
| **Licensing/billing** | Pro distribution | Reuse existing in-house licensing/updater | Low | High | ✅ (Pro only) |
| **Extensibility** | Agencies and Pro need to change behaviour | Documented filters on stage map, eligibility, ETA calc, adapters, templates | Low | High | ✅ |

---

# PHASE 3 — PRODUCT SCOPE (feature map)

## Core Product

- **Timeline engine**
  - Stage definitions (filterable, default: Placed → Confirmed → Packed → Shipped → Out for delivery → Delivered)
  - Status→stage mapping (auto-detected at setup, editable)
  - Transition recorder (forward-only, bounded, order meta)
  - Historical-order degradation (stage without timestamp)
  - Terminal/branch states: Cancelled, Refunded, Failed, On hold, Return in progress
- **Estimated delivery**
  - Handling-time config (global + per shipping method)
  - Business-day calculation with weekend/holiday exclusions
  - Range output ("arrives Tue 24 – Thu 26 Aug"), suppressed once real tracking exists
- **Action engine**
  - Eligibility resolver (status, age window, payment method, product/order type, per-order request caps)
  - Registered actions: Cancel, Return (Pro), Reorder, Invoice, Get Help, Track
  - Request lifecycle: `pending → approved | declined | cancelled_by_customer`
- **Request store** — persistence, event log, retention

## Admin

- Request queue list table (filters, row actions, bulk in Pro)
- Request detail panel (items, reason, history, internal note)
- Order-edit-screen metabox showing linked requests
- Settings (6 tabs)
- Guided setup wizard
- Menu counter for pending requests
- Status/health panel: detected tracking plugin, detected invoice plugin, template conflict warnings

## Frontend

- Enhanced orders list (status pill, timeline mini-state, contextual actions)
- Enhanced order detail (full timeline, ETA, tracking block, action buttons, request state)
- Guest order lookup form (shortcode + block)
- Signed-link landing view (same UI, no session)
- Cancellation request modal (reason select + optional note)
- Reorder reconciliation screen
- Get Help form
- Full-replacement templates (opt-in) + additive hooks (default)

## Integrations

- Tracking adapters: AST, official Shipment Tracking, Woo native fulfilment, generic `pph_tracking_data` filter
- Invoice detection: common PDF invoice plugins → surface their download URL; else Woo print view
- Compatibility guards: Subscriptions, Bookings, Deposits, Memberships, WPML/Polylang, caching plugins, HPOS
- CAPTCHA hook point (reCAPTCHA/Turnstile) on guest lookup

## Notifications

- Customer: request received, approved, declined, order shipped w/ tracking (only if store lacks one), signed-link email
- Admin: new request, daily digest (opt-in)
- All as `WC_Email` subclasses so they inherit Woo's template, customiser and the new email editor

## Developer / API

- `pph/v1` REST: `POST /requests`, `GET /orders/{id}/timeline`, `POST /lookup`, `POST /reorder`
- Filters: `pph_timeline_stages`, `pph_status_stage_map`, `pph_action_eligibility`, `pph_estimated_delivery`, `pph_tracking_adapters`, `pph_request_reasons`, `pph_locate_template`
- Actions: `pph_request_created`, `pph_request_approved`, `pph_request_declined`
- Template overrides via theme directory (`yourtheme/post-purchase-hub/`)
- WP-CLI: `wp pph backfill-timeline`, `wp pph cleanup`

## Security

- Ownership resolver (single choke point for logged-in + token auth)
- HMAC signed tokens bound to order ID + order key + expiry
- Rate limiter (IP + email-hash, transient/object-cache backed)
- Uniform lookup responses (no existence oracle)
- Nonce + capability + REST permission callbacks on every endpoint
- Input sanitisation/validation layer; output escaping audit
- Structured security event log (failed lookups, rejected tokens)
- Nocache headers on all order-bearing responses

## Analytics (Pro, post-MVP)

- Cancellation rate, return rate, reason distribution, top returned products, approval turnaround, deflection proxy (self-service actions completed)

---

# PHASE 4 — MVP DEFINITION

## MVP feature table

| Feature | User value | Tech complexity | Dependencies | Priority |
| --- | --- | --- | --- | --- |
| Plugin foundation (bootstrap, autoload, activation, HPOS declaration) | — | Low | — | P0 |
| Data layer: `pph_requests` (+ items), migrations, retention | — | Medium | Foundation | P0 |
| Timeline engine + transition recorder | High — answers "where is it" | Medium | Foundation | P0 |
| Additive rendering layer (orders list + view order) | High — the surface everything lives on | **High** (theme long tail) | Timeline | P0 |
| Estimated delivery range | **High** — the only unilateral WISMO lever | Medium | Timeline | P0 |
| Tracking adapters (AST, official, native, filter) | High | Medium | Timeline | P0 |
| Ownership resolver + signed tokens | — (enables everything) | Medium | Foundation | P0 |
| Cancellation requests (customer side) | **Highest** — the install trigger | Medium | Action engine, Data layer | P0 |
| Admin request queue | High — merchant-side product | Medium | Data layer | P0 |
| Customer + admin emails | High | Low–Medium | Requests | P0 |
| Guest lookup + signed links in emails | High | **High** (security) | Ownership resolver, Rate limiter | P0 |
| Reorder w/ reconciliation | Medium | Low–Medium | Action engine | P1 |
| Get Help (context-prefilled) | Medium | Low | Action engine | P1 |
| Invoice access (link/detect, no generator) | Medium | Low | Integrations | P1 |
| Settings + setup wizard | High — activation quality | Medium | All | P1 |
| Opt-in full template replacement + conflict pre-check | Medium | Medium | Rendering layer | P1 |
| Shortcode + block render paths | Medium | Low | Rendering layer | P1 |
| Security hardening pass + audit | — | Medium | All | P0 |
| Test suite + compat matrix | — | High | All | P0 |
| i18n, readme, docs, uninstall | — | Low | All | P0 |

## MVP Release (1.0.0, free on WP.org)

Everything in the table above. **Returns are not in 1.0.** Explicitly excluded from 1.0: returns/RMA, item-level actions, PDF generation, rules engine, bulk actions, custom statuses, analytics, white-label, webhooks, uploads, SMS, AI, carrier APIs, multi-vendor, subscriptions.

## Version 1.1 (Pro 1.0 ships here)

Returns/RMA workflow (item-level, quantities, reason codes, windows, restock on approval) · item-level cancellation · eligibility rules engine · admin bulk actions and saved views · invoice-plugin integrations · request analytics v1 · customer-side order search/filter.

## Version 1.2

Custom order status mapping UI · auto-approve automation rules · return shipping instructions + merchant label upload · review-request prompt on delivered orders · webhooks · hardened file uploads.

## Long-term

Public REST API for headless/mobile · white-label + agency multisite licensing · multi-vendor (Dokan/WCFM) · store-credit outcomes via wallet integration · native fulfilment-driven precision ETAs if Woo's fulfilment data model stabilises.

---

# PHASE 5 — TECHNICAL ARCHITECTURE

```
post-purchase-hub/
├── post-purchase-hub.php          # Header, PHP/WP/Woo guards, HPOS declaration, bootstrap only
├── uninstall.php                  # Retention-aware teardown
├── readme.txt                     # WP.org
├── composer.json                  # Dev-only deps (PHPCS, PHPStan, stubs, PHPUnit). No runtime vendor.
├── src/                           # PSR-4: PostPurchaseHub\
│   ├── Plugin.php                 # Container + hook registration. No business logic.
│   ├── Install/                   # Activator, Migrator, schema versioning
│   ├── Timeline/                  # StageMap, TimelineBuilder, TransitionRecorder, EstimatedDelivery
│   ├── Actions/                   # ActionRegistry, EligibilityResolver, Cancel, Reorder, Invoice, Help
│   ├── Requests/                  # RequestRepository, Request model, RequestService, EventLog
│   ├── Security/                  # OwnershipResolver, TokenService, RateLimiter, Sanitizer
│   ├── Integrations/Tracking/     # TrackingAdapterInterface + Ast, Official, NativeFulfilment, Filter
│   ├── Integrations/Invoices/     # Detector
│   ├── Integrations/Compat/       # Subscriptions, Bookings, WPML, Caching guards
│   ├── Rest/                      # Controllers, permission callbacks
│   ├── Emails/                    # WC_Email subclasses + Mailer
│   ├── Frontend/                  # Renderer, TemplateLoader, Shortcodes, Block registration, Assets
│   ├── Admin/                     # Menu, RequestListTable, RequestDetail, SettingsPage, Wizard, Notices, OrderMetabox
│   ├── Support/                   # Logger, Cache, Dates (business-day math)
│   └── CLI/                       # WP-CLI commands
├── templates/                     # Theme-overridable markup, logic-free
│   ├── myaccount/                 # orders.php, view-order.php (replacement mode only)
│   ├── partials/                  # timeline.php, actions.php, tracking.php, eta.php
│   ├── lookup/                    # form.php, result.php
│   └── emails/                    # plain + HTML
├── assets/
│   ├── src/                       # JS/SCSS sources
│   └── build/                     # Compiled, versioned (@wordpress/scripts)
├── languages/
└── tests/
    ├── unit/                      # No WP bootstrap
    ├── integration/               # WP + Woo test suite
    ├── e2e/                       # Playwright
    └── fixtures/
```

**Why each directory:**

- `src/` with PSR-4 and Composer's classmap — no bespoke autoloader. Composer is dev-only in the shipped artifact except for the autoloader itself (kept because a hand-rolled autoloader is a maintenance tax and a bug source).
- `Timeline/`, `Actions/`, `Requests/` are the three genuine domains. They talk through services, never reach into each other's storage.
- `Security/` is a separate directory because ownership and tokens must be a **single choke point**; scattering auth checks across controllers is how IDOR bugs ship.
- `Integrations/` isolates every third-party assumption so a competitor's breaking change is one file.
- `templates/` is logic-free and theme-overridable — mandatory for a plugin whose entire value is rendering into other people's themes.
- `Support/Dates` exists because business-day arithmetic with holidays is exactly the code that gets duplicated wrong in four places.
- `tests/` split three ways so unit tests stay fast enough to run on save.

**Deliberate non-choices:** no DI framework (a hand-rolled container with lazy factories is enough for ~40 classes) · no template engine · no React admin (`WP_List_Table` + vanilla JS/Settings API is faster to build, faster to load, and more maintainable by the next developer) · no runtime Composer packages.

---

# PHASE 6 — WORDPRESS ARCHITECTURE

| Mechanism | Used? | Reason |
| --- | --- | --- |
| **Custom Post Types** | ❌ | Requests are transactional records, not content. A CPT would inflate `wp_posts`, inherit revision/autosave/cron overhead, and expose requests to REST and search unless heavily suppressed. Rejected despite the free list table. |
| **Custom Taxonomies** | ❌ | Reason codes are a bounded, filterable config array, not user-managed terms. |
| **Custom Tables** | ✅ 2 tables | Justified in Phase 7. The admin queue needs indexed cross-order queries by status/type/date with sorting and pagination — the one workload meta storage handles badly. |
| **Options API** | ✅ | Settings as a single serialised array (`pph_settings`, `autoload=yes`, one row) + non-autoloaded `pph_schema_version`, `pph_token_secret`, `pph_setup_state`. No option-per-setting sprawl. |
| **Transients / Object Cache** | ✅ | Rate-limit counters, tracking-plugin detection, invoice-plugin detection, template-conflict scan results. Everything here is regenerable — never authoritative state. |
| **User Meta** | ❌ | Nothing is per-user rather than per-order. |
| **Post Meta / Order Meta (CRUD)** | ✅ | `_pph_timeline` (bounded array of forward status transitions, ~10 entries max), `_pph_eta` (cached computed range, invalidated on status/shipping change). Written via `$order->update_meta_data()` + `save()` so HPOS and legacy both work. Deliberately small and denormalised — this data is only ever read *with* its order. |
| **WP Cron** | ✅ 1 daily event | Retention cleanup (expired rate-limit rows, requests past retention, orphaned records). Single event, idempotent, bails fast. |
| **Action Scheduler** | ❌ v1 | No bulk or long-running work in 1.0. Adopt in 1.1 when bulk approvals and analytics aggregation arrive — Woo already bundles it, so the dependency is free when actually needed. |
| **REST API** | ✅ `pph/v1` | The frontend needs authenticated mutations for guests *and* logged-in users. REST gives proper permission callbacks, schema validation, and typed args — materially safer than `admin-ajax`. Guest auth via signed token in the request body, never the URL. |
| **admin-ajax** | ❌ | Nothing needs it; two request paths means two auth paths means two places to get auth wrong. |
| **Gutenberg / Blocks** | ✅ 2 blocks | `pph/order-lookup` and `pph/orders` (server-rendered, `render_callback`) so FSE and block-My-Account users are first-class. Registered via `block.json` + `@wordpress/scripts`. |
| **Shortcodes** | ✅ 2 | `[pph_order_lookup]`, `[pph_orders]` — still the pragmatic path for classic themes and page builders. |
| **Widgets** | ❌ | Legacy surface, no use case. |
| **WP-CLI** | ✅ 2 commands | `backfill-timeline` (large stores, must not run in a request) and `cleanup`. Cheap, and the first thing support will ask for. |
| **Settings API** | ✅ | Familiar, handles nonces and sanitisation callbacks correctly. Custom-rendered fields where needed, standard registration always. |
| **WC_Email** | ✅ | Subclassing means merchants get Woo's template, the customiser, and the block email editor for free, and translators get one system. |
| **Woo hooks over templates** | ✅ default | `woocommerce_my_account_my_orders_actions`, `woocommerce_my_account_my_orders_column_*`, `woocommerce_view_order`, `woocommerce_order_details_after_order_table`. This is the T1 mitigation and the most important architectural decision in the document. |

---

# PHASE 7 — DATABASE DESIGN

Two tables. Both prefixed `{$wpdb->prefix}pph_`.

### `pph_requests`

Purpose: one row per customer-initiated request (cancellation in 1.0, returns in 1.1).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `order_id` | `BIGINT UNSIGNED NOT NULL` | Logical FK to Woo orders (no DB constraint — HPOS/legacy dual storage, and Woo does not use FKs) |
| `customer_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | 0 for guests |
| `customer_email_hash` | `CHAR(64) NOT NULL` | SHA-256 of normalised email; enables guest rate limiting and lookup without a second PII copy |
| `type` | `VARCHAR(20) NOT NULL` | `cancellation` \| `return` \| `help` |
| `status` | `VARCHAR(20) NOT NULL` | `pending` \| `approved` \| `declined` \| `withdrawn` \| `completed` |
| `reason_code` | `VARCHAR(50) NULL` | From the filterable set |
| `customer_note` | `TEXT NULL` | Sanitised, length-capped at 2000 chars |
| `admin_note` | `TEXT NULL` | Internal |
| `amount` | `DECIMAL(19,4) NULL` | Informational only in 1.0 (no refunds executed) |
| `currency` | `CHAR(3) NULL` | |
| `source` | `VARCHAR(20) NOT NULL` | `account` \| `guest_token` \| `guest_lookup` \| `admin` — needed for abuse forensics |
| `created_at` / `updated_at` | `DATETIME NOT NULL` (UTC) | |
| `resolved_at` | `DATETIME NULL` | Enables turnaround metrics later |
| `resolved_by` | `BIGINT UNSIGNED NULL` | User ID |

Indexes: `PRIMARY(id)` · `KEY order_id (order_id)` · `KEY status_type_created (status, type, created_at)` (the queue's default query) · `KEY customer_id (customer_id)` · `KEY email_hash_created (customer_email_hash, created_at)` (per-customer rate limiting) · `KEY created_at (created_at)` (retention sweep).

### `pph_request_items`

Purpose: per-line-item detail for item-level requests.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `request_id` | `BIGINT UNSIGNED NOT NULL` | Logical FK, cascade handled in application code |
| `order_item_id` | `BIGINT UNSIGNED NOT NULL` | |
| `product_id` / `variation_id` | `BIGINT UNSIGNED` | Denormalised for analytics without joining order tables |
| `quantity` | `INT UNSIGNED NOT NULL` | |
| `reason_code` | `VARCHAR(50) NULL` | Per-item reason |
| `line_total` | `DECIMAL(19,4) NULL` | Snapshot |

Indexes: `PRIMARY(id)` · `KEY request_id (request_id)` · `KEY product_id (product_id)`.

**Why two tables now rather than a JSON column.** 1.0 only ever writes whole-order cancellations, so a JSON blob would suffice today. But 1.1's item-level returns and 1.1's "top returned products" report both need item-granular queries, and retrofitting normalisation onto shipped JSON is a migration with a data-quality tail. Creating the second table at install with zero rows in 1.0 costs nothing and removes a known future migration. This is the one place where anticipating a roadmap item is cheaper than deferring.

**Where the event log lives.** Not a third table. Request lifecycle events append to `pph_requests.admin_note`? No — that loses structure. They go to **Woo order notes** (merchant-visible, already searchable, already in the order's audit trail) plus, for security events only, the plugin's `Logger` writing to `WC_Logger` (`post-purchase-hub` source, respects Woo's log retention). Rationale: a bespoke log table would duplicate two systems the merchant already checks, and log tables are the #1 cause of runaway plugin table growth.

**Explicitly not stored anywhere:** signed tokens (HMAC-verified statelessly), timeline stages (derived), estimated delivery (cached in order meta, recomputable), tracking numbers (owned by other plugins), plaintext customer emails beyond what Woo already stores.

### Volume

Requests run roughly 2–8% of orders. A 5,000-orders/month store generates ~150–400 rows/month, ~5k/year — trivially small, which is exactly why an unindexed meta approach would have *worked* and an unbounded log table would have *hurt*. Even at 50k orders/month the tables stay in the low hundreds of thousands of rows with the queue's queries fully indexed.

### Migration strategy

- `pph_schema_version` option (non-autoloaded), integer. Checked on `plugins_loaded` at a cost of one cached option read; runs migrations only on mismatch.
- `dbDelta()` for additive schema changes; explicit migration classes in `Install/Migrations/` for data transforms, each idempotent and individually re-runnable.
- Migrations that could touch many rows run through WP-CLI or a batched cron task, never inline in an admin request.
- Timeline backfill is opt-in (`wp pph backfill-timeline`), batched, resumable, and never automatic — it touches every order.

### Cleanup / uninstall

`uninstall.php` respects a "Remove all data on uninstall" setting, default **off** (removing a merchant's return history by default is indefensible). When enabled: drop both tables, delete `pph_*` options, delete `_pph_*` order meta in batches via CRUD, clear transients and the cron event. Deactivation clears the cron event and transients only — never data.

---

# PHASE 8 — SECURITY DESIGN

Governing rule: **validate input → authorise action → sanitise data → perform operation → escape output.** Every endpoint passes through the same three classes so there is no second implementation to forget.

## Authentication

Three and only three identities can reach order data:

1. **Logged-in customer** — `get_current_user_id()` matched against `$order->get_customer_id()`.
2. **Signed token bearer** — HMAC token in an email link or a guest session, bound to a specific order.
3. **Shop manager / admin** — capability check.

Anything else is rejected before any order is loaded.

## Signed tokens

```
payload = order_id | order_key | expiry
token   = base64url(payload) . '.' . hash_hmac('sha256', payload, $secret)
$secret = get_option('pph_token_secret')   // 64 random bytes, generated at activation, non-autoloaded
```

Rules:
- Verified with `hash_equals`. No exceptions, no early-return string compare.
- Includes `order_key`, so rotating the order key invalidates every outstanding link — a real revocation path.
- Default TTL 14 days, configurable, hard-capped at 90.
- **Idempotent within TTL.** Not one-time-use: corporate mail scanners and link previewers pre-fetch URLs, and burning the token on first GET means legitimate customers get a dead link (risk T9).
- **Grants order-scoped read + action capability only. Never creates a WP session, never calls `wp_set_auth_cookie`.** Magic-login is an account-takeover primitive; this plugin will not have one.
- Token travels in the URL fragment-free query string only for the initial email link; the landing page immediately exchanges it for a short-lived cookie-bound context so it never reappears in subsequent request URLs, referrers, or server logs.

## Authorisation

One method — `OwnershipResolver::assertCanAccess( int $order_id, string $context ): WC_Order` — throws on failure. Every REST controller, every shortcode render, every template call goes through it. Zero inline ownership checks anywhere else in the codebase, enforced by a PHPCS sniff and a code-review rule.

Capabilities: `edit_shop_orders` for the queue and all approve/decline actions. `manage_woocommerce` for settings. No custom capabilities in 1.0 (they create migration and role-plugin friction for no user benefit).

## Guest lookup — the highest-risk surface

- Requires order number **and** billing email; both must match. Email compared after normalisation with `hash_equals`.
- **No existence oracle.** Success and failure return the same response and the same timing envelope. On any failure the page says a secure link has been sent to the address on file if the order exists — and if it exists, one actually is. This deflects the ticket and removes the oracle simultaneously.
- Rate limits: 5 attempts / 15 min per IP, 10 / hour per email hash, 100 / hour per site, all via `Cache`/transients with an object-cache-aware backend. Exceeded → generic throttle message, logged.
- Order number lookup uses `wc_get_order_id_by_order_number()`-equivalent logic through Woo APIs, never a raw ID guess path, so sequential-ID enumeration gains nothing without the email.
- Filter hook for reCAPTCHA/Turnstile; not bundled.
- Guest lookup can be disabled entirely, and is **off by default** until the wizard's guest-access step is completed — no store gets an unconfigured public order endpoint on activation.

## Per-threat controls

| Threat | Control |
| --- | --- |
| CSRF | REST nonce (`X-WP-Nonce`) for logged-in; token + cookie-bound context for guests; `wp_verify_nonce` on all admin POSTs; no state change on GET, ever |
| XSS (reflected) | All output escaped at print time: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` only where markup is genuinely needed |
| XSS (stored) | Reasons restricted to a server-side whitelist of codes; notes stripped of tags on write, length-capped, escaped on every render including wp-admin and email bodies |
| SQL injection | `$wpdb->prepare()` on every query; no string interpolation; ORDER BY / column names from a hardcoded whitelist, never from request input |
| IDOR | `OwnershipResolver` choke point; requests are looked up *by id then re-verified against their order's owner*, never trusted from `request_id` alone |
| Privilege escalation | Capability checks in REST `permission_callback` and at the top of every admin handler; approve/decline never reachable by `read` |
| SSRF | Zero outbound HTTP in 1.0. No telemetry, no update pings from the free plugin, no remote asset loads. Stated in readme — it makes the WP.org review trivial. |
| Path traversal | Template loader resolves against a fixed whitelist of template names; no request-derived paths |
| File uploads | None in 1.0 |
| Arbitrary execution | No `eval`, no `unserialize` on any external input, no dynamic callables from settings |
| Sensitive data exposure | Nocache headers on every order-bearing response; no PII in query strings; no order data in JS globals beyond what is already rendered; emails hashed in logs |
| REST permissions | Explicit `permission_callback` on every route (never `__return_true`); `args` schema with `validate_callback` and `sanitize_callback` per field |
| Rate limiting | Applied to lookup, link-request, cancel, reorder, help-submit — not just lookup |
| Enumeration via emails | Link emails go only to the address stored on the order, never to a submitted address |
| Abuse of requests | Per-order request caps, cooldown between requests, eligibility windows, merchant approval mandatory for anything financial |

## The refund decision

**1.0 executes no refunds.** Approving a cancellation sets the order to `cancelled`, optionally restocks, writes an order note, and emails both parties. The merchant refunds through Woo's existing refund UI, one click away via a deep link in the notification.

Reasoning: partial captures, authorisation-vs-capture differences, gateway fees, currency conversion, multi-gateway stores, and split payments all mean an automated refund path fails in ways that cost merchants real money. The failure mode is a public review containing a currency amount. Refund automation, if it ever ships, ships in Pro, off by default, per-gateway allow-listed, with an amount ceiling.

---

# PHASE 9 — UX / UI DESIGN

## First install

Activation adds an admin notice (dismissible, on Woo screens only): *"Post-Purchase Hub is installed. Take 2 minutes to set up your order timeline →"*. Nothing on the frontend changes until setup completes. This is deliberate: a plugin that silently rewrites the customer-facing order page on activation is a plugin that gets uninstalled at 2am.

## Onboarding — yes, necessary

Four steps, skippable, resumable, ~2 minutes:

1. **Detect statuses** — scan the last 200 orders for statuses actually used, propose a stage map, let the merchant edit it. This is the fix for UX problem #2 and it is the single highest-leverage screen in the plugin.
2. **Handling time** — "How long between an order arriving and you shipping it?" Global default + per-method override. Powers the ETA.
3. **Tracking source** — auto-detected (AST / official / native / none) and shown, with a link to install a free tracking plugin if none is found. Honest about the dependency instead of hiding it.
4. **Display mode** — additive (recommended, default) vs full replacement, with a side-by-side live preview and an automatic warning if the active theme already overrides `myaccount/orders.php` or `view-order.php`.

Final screen: which self-service actions to enable, with cancellation defaulting to **request-and-approve**.

## Admin navigation

`WooCommerce → Post-Purchase Hub`, two subpages: **Requests** (default landing) and **Settings**. Pending count as a menu bubble. No top-level menu — a top-level entry for a Woo extension is a land grab, and merchants resent it.

## Dashboard

None in 1.0. The Requests queue *is* the dashboard: pending first, age column, order deep link, one-click approve/decline. A chart-laden overview screen before there is data to chart is theatre.

## Settings organisation

Six tabs, ordered by how often they are touched: General · Timeline · Actions · Guest Access · Emails · Advanced. Every field has inline help. No settings without a user story.

## Empty states

- Requests queue: *"No requests yet. When a customer asks to cancel an order, it appears here."* + a link to the customer-facing preview so the merchant can see what their customers see.
- Customer orders list, no orders: Woo's native message, untouched.
- Timeline with no tracking on a shipped order: shows the estimated range and a plain line — *"Tracking details will appear here once your parcel is scanned."* Never a broken or empty widget.

## Loading states

Inline button spinners with `aria-busy`, buttons disabled during flight, optimistic UI only for reorder cart adds (reversible), never for cancel (irreversible in the customer's mental model). Skeleton rows in the admin queue during filter changes.

## Error states

Human, specific, actionable, and never leaking internals. *"We couldn't cancel this order because it's already shipped. Reply to this email and we'll help."* Validation errors render inline next to the field with `aria-describedby`. All errors carry a support reference ID that matches the log line.

## Success states

Cancel → the timeline immediately shows a `Cancellation requested` branch state with the expected response time. Reorder → cart page with a reconciliation banner listing what changed. Help → confirmation with the ticket context echoed back. Every success also sends an email, because customers do not trust on-screen confirmations for money-adjacent actions.

## Confirmations

Required for: cancellation request submission (modal, states it is a request, not a cancellation) · admin approve/decline (with an inline note field) · reorder when the cart is non-empty (merge vs replace) · enabling full template replacement · enabling "delete all data on uninstall".

## Accessibility

Timeline is an ordered list with text state labels, not colour-only. Contrast ≥ 4.5:1 against Woo's defaults. Modals trap focus and restore it on close. All actions keyboard-reachable. `aria-live` regions announce request state changes. Reduced-motion respected. Tested with keyboard-only and NVDA/VoiceOver passes before release, because "5 plugins, no coherent UI" is the pitch and inaccessible UI is not coherent.

---

# PHASE 10 — DEVELOPMENT TIMELINE

One senior developer, hours of focused work (not calendar days). Estimates include self-review and inline tests, exclude the dedicated testing phase.

### Phase 0 — Discovery
**16h** (this document) · Deps: none
Deliverables: product analysis, MVP definition, architecture, DB design, security design, UX spec.
Acceptance: scope frozen; returns confirmed out of 1.0; ETA confirmed in 1.0.

### Phase 1 — Foundation
**30h** · Deps: Phase 0
Deliverables: bootstrap with PHP/WP/Woo version guards, PSR-4 + container, activation/deactivation, HPOS declaration, schema installer + migrator, settings storage, logger, cache wrapper, WP-CLI shell, CI skeleton (PHPCS + PHPStan + PHPUnit + wp-env matrix).
Acceptance: activates and deactivates cleanly on WP 6.5→latest × PHP 8.1–8.4 × HPOS on/off with zero notices; tables created; `pph_schema_version` set; PHPCS and PHPStan clean.
Testing: activation/deactivation integration tests, migration idempotency test.

### Phase 2 — Core (timeline + rendering + ETA)
**86h** · Deps: Phase 1
Deliverables: stage map + auto-detection, transition recorder, timeline builder with historical degradation, business-day date engine, estimated-delivery calculator, additive renderer, opt-in replacement templates + conflict pre-check, shortcode + block, conditional asset loading.
Acceptance: timeline renders correctly for orders created before and after activation; ETA suppressed once tracking exists; visual pass on Storefront, Astra, Kadence, Blocksy, Divi, Elementor Hello; zero added queries per orders-list row (verified with Query Monitor).
Testing: unit tests on stage mapping and date math; integration tests on meta writes; Playwright visual checks per theme.

### Phase 3 — Actions & requests
**86h** · Deps: Phase 2
Deliverables: security layer (ownership resolver, token service, rate limiter, sanitiser), action registry + eligibility resolver, cancellation request flow, admin request queue + detail + order metabox, `WC_Email` classes both directions.
Acceptance: a customer can request cancellation within the configured rules and not outside them; merchant sees it within one page load; approve sets `cancelled`, optionally restocks, writes an order note, emails the customer; no refund is ever executed.
Testing: eligibility matrix unit tests, IDOR integration tests, email trigger tests, e2e happy path + every rejection path.

### Phase 4 — Guest access
**24h** · Deps: Phase 3
Deliverables: lookup form (shortcode + block), signed-link landing, token→context exchange, link-request email, rate limits, nocache headers, CAPTCHA filter hook.
Acceptance: valid order + email returns the order; wrong email returns the identical response and sends the link to the address on file; 6th attempt in 15 minutes is throttled; token expires; tampered token rejected; page not cached by WP Rocket or LiteSpeed.
Testing: security integration suite (enumeration, timing, tamper, replay), e2e guest flow, cache-plugin verification.

### Phase 5 — Secondary actions & integrations
**40h** · Deps: Phase 3
Deliverables: reorder with reconciliation, invoice access + invoice-plugin detection, Get Help form, tracking adapters (AST / official / native fulfilment / filter), compat guards (Subscriptions, Bookings, WPML/Polylang, caching).
Acceptance: reorder handles out-of-stock, deleted variation, price change and non-empty cart with an explicit summary; tracking renders identically regardless of source plugin; subscription and bookable orders are excluded from cancel and reorder.
Testing: adapter unit tests with fixtures per source, integration tests against AST and the official extension installed.

### Phase 6 — Settings & onboarding
**22h** · Deps: Phases 2–5
Deliverables: six-tab settings screen, four-step wizard, health panel, admin notices, empty states.
Acceptance: wizard completes in under 2 minutes on a fresh store; nothing renders on the frontend until it completes; guest access remains off until explicitly enabled.
Testing: e2e wizard flow, settings sanitisation tests, capability tests.

### Phase 7 — Security hardening
**16h** · Deps: Phases 1–6
Deliverables: full input/output audit, PHPCS security sniffs, adversarial self-review against the Phase 8 threat table, PHPStan level 6 clean, third-party review of the token and lookup code.
Acceptance: every REST route has a real `permission_callback`; every `$wpdb` call prepared; every echo escaped; zero unauthenticated state mutations; the entire Phase 8 table has a passing test or a documented mitigation.
Testing: the security test suite in Phase 15, run as a gate.

### Phase 8 — Testing & compatibility
**40h** · Deps: Phase 7
Deliverables: unit + integration coverage on business logic (target ≥70% on `Timeline/`, `Actions/`, `Requests/`, `Security/`), Playwright e2e suite, compatibility matrix execution, performance profiling.
Acceptance: full matrix green; no PHP notices, no JS console errors, no Query Monitor warnings; orders page adds < 50ms server time and < 15KB of assets.
Testing: this is the phase.

### Phase 9 — Release
**20h** · Deps: Phase 8
Deliverables: readme.txt, changelog, screenshots, docs, translation POT, uninstall verification, upgrade-path test, WP.org submission, support macros.
Acceptance: the Phase 18 checklist passes end to end on a clean install and on an upgrade from a pre-release build.

## Totals

| | Hours |
| --- | --- |
| Minimum realistic (experienced Woo dev, disciplined scope, some theme testing deferred) | **280h** |
| Comfortable / expected | **360h** |
| With 20% contingency | **430h** |

At 40h/week that is **7–9 weeks full-time** for the free 1.0 — not 4–6. Even after removing returns from the MVP, the brief's estimate is roughly 35% light, and the gap is almost entirely in the two places briefs always under-count: cross-theme rendering compatibility and the security surface of guest access.

Pro 1.0 / v1.1 (returns workflow, item-level actions, rules engine, bulk actions, analytics v1): **+100–120h**.

## Risk factors that extend the timeline

| Factor | Impact | Signal to watch |
| --- | --- | --- |
| Theme/builder rendering long tail (T1) | +20–60h | Every new theme tested that needs a CSS special case |
| Guest-access security rework after review | +10–30h | Any finding in the token or lookup path |
| Tracking adapter drift | +8–16h | AST or Woo changing meta keys or shipping native fulfilment as stable |
| Woo core shipping a competing My Account / fulfilment customer view | Scope change, not schedule | Woo release notes and the developer roadmap — check before each phase |
| Subscriptions/Bookings edge cases discovered late | +10–20h | Any order type where cancel semantics are ambiguous |
| WP.org review friction | +8h | Guest endpoints and DB tables both attract questions |
| Deciding mid-build to add returns | +100h and a slipped release | Treat any "while we're in here" returns work as a scope breach |

---

# PHASE 11 — MILESTONE BREAKDOWN

Seventeen milestones. Each is independently testable and small enough to review in one sitting.

---

## Milestone 01 — Plugin Foundation
**Goal:** an installable, standards-clean plugin that does nothing visible.
**Tasks:** 1) Main file with header, PHP/WP/Woo guards and graceful bail. 2) Composer PSR-4 autoload + dev deps (PHPCS/WPCS, PHPStan + Woo stubs, PHPUnit, wp-env). 3) `Plugin` container with lazy service factories. 4) Activator/Deactivator (no data loss on deactivate). 5) HPOS `custom_order_tables` declaration. 6) `Support\Logger` (WC_Logger) and `Support\Cache`. 7) CI workflow.
**Files:** `post-purchase-hub.php`, `composer.json`, `src/Plugin.php`, `src/Install/{Activator,Deactivator}.php`, `src/Support/{Logger,Cache}.php`, `.github/workflows/ci.yml`, `phpcs.xml.dist`, `phpstan.neon.dist`.
**Acceptance:** activates/deactivates with zero notices across the matrix; bails with an admin notice if Woo is absent; PHPCS + PHPStan clean.
**Tests:** activation integration test; "Woo missing" bail test.

---

## Milestone 02 — Data Layer & Migrations
**Goal:** schema exists, versioned and idempotent.
**Tasks:** 1) `Install\Schema` with `dbDelta()` for both tables. 2) `Install\Migrator` keyed on `pph_schema_version`. 3) `Requests\RequestRepository` (create/read/update/query/count, all prepared, whitelisted ORDER BY). 4) `Requests\Request` model. 5) `uninstall.php` honouring the retention setting. 6) `wp pph cleanup`.
**Files:** `src/Install/{Schema,Migrator}.php`, `src/Requests/{Request,RequestRepository}.php`, `uninstall.php`, `src/CLI/CleanupCommand.php`.
**Acceptance:** tables created with the specified indexes; migrator runs once and is safe to re-run; uninstall removes nothing when retention is on and everything when off.
**Tests:** schema assertions, repository CRUD, migration idempotency, uninstall both branches.

---

## Milestone 03 — Timeline Engine
**Goal:** any order can be described as an ordered set of stages with timestamps where known.
**Tasks:** 1) `Timeline\StageMap` with default stages + `pph_timeline_stages` / `pph_status_stage_map` filters. 2) Status auto-detection over recent orders (cached). 3) `Timeline\TransitionRecorder` on `woocommerce_order_status_changed`, writing a bounded `_pph_timeline` array via CRUD. 4) `Timeline\TimelineBuilder` producing a view model, including branch states (cancelled/refunded/failed/on-hold). 5) Graceful degradation for pre-activation orders. 6) `wp pph backfill-timeline` (batched, resumable).
**Files:** `src/Timeline/{StageMap,TransitionRecorder,TimelineBuilder}.php`, `src/CLI/BackfillCommand.php`.
**Acceptance:** timeline correct for a new order through the full lifecycle; correct-but-timestampless for historical orders; array never exceeds its cap; identical results with HPOS on and off; no order notes parsed.
**Tests:** unit tests per status path; integration test asserting meta writes; HPOS/legacy parity test.

---

## Milestone 04 — Rendering Layer
**Goal:** the timeline appears on the customer's order pages without breaking themes. *Highest-risk milestone.*
**Tasks:** 1) `Frontend\Renderer` hooking `woocommerce_view_order`, `woocommerce_order_details_after_order_table`, `woocommerce_my_account_my_orders_column_*` (additive default). 2) `Frontend\TemplateLoader` with theme override support and a name whitelist. 3) Replacement mode via `woocommerce_locate_template`, opt-in only. 4) `Admin\TemplateConflictScanner` detecting theme overrides of the two target templates. 5) `[pph_orders]` shortcode + `pph/orders` block. 6) `Frontend\Assets` with endpoint-scoped conditional enqueue and no global CSS. 7) Accessible timeline markup (ordered list, text labels, contrast).
**Files:** `src/Frontend/{Renderer,TemplateLoader,Shortcodes,Blocks,Assets}.php`, `templates/partials/timeline.php`, `templates/myaccount/{orders,view-order}.php`, `src/Admin/TemplateConflictScanner.php`, `assets/src/*`.
**Acceptance:** renders correctly on the six-theme test set in additive mode; replacement mode warns before enabling when a conflict is detected; zero extra queries per orders-list row; no assets on non-order pages.
**Tests:** Playwright screenshots per theme (desktop + mobile), query-count assertion, asset-scope assertion, keyboard-navigation pass.

---

## Milestone 05 — Estimated Delivery
**Goal:** a shipped-but-untracked order still gives the customer a date.
**Tasks:** 1) `Support\Dates` business-day arithmetic with weekend + holiday-list exclusion. 2) `Timeline\EstimatedDelivery` from handling time + shipping method transit config. 3) Range output, locale- and timezone-aware. 4) Cache in `_pph_eta`, invalidate on status or shipping change. 5) Suppress once real tracking data exists. 6) `pph_estimated_delivery` filter.
**Files:** `src/Support/Dates.php`, `src/Timeline/EstimatedDelivery.php`, `templates/partials/eta.php`.
**Acceptance:** correct across DST boundaries, year boundaries, and a store timezone different from UTC; never shows a past date; disappears when tracking appears.
**Tests:** unit tests over a date fixture table including DST and leap years.

---

## Milestone 06 — Security Layer
**Goal:** one place where access is decided.
**Tasks:** 1) `Security\OwnershipResolver::assertCanAccess()`. 2) `Security\TokenService` (generate/verify, `hash_equals`, order_key binding, TTL cap, secret generation at activation). 3) `Security\RateLimiter` (IP + email-hash + site, object-cache aware). 4) `Security\Sanitizer` (reason whitelist, note stripping + length cap, email normalisation). 5) Nocache header helper. 6) PHPCS sniff forbidding ownership checks outside the resolver.
**Files:** `src/Security/*.php`.
**Acceptance:** tampered, expired and cross-order tokens all rejected; resolver rejects a logged-in user requesting another customer's order; rate limiter survives object cache present and absent.
**Tests:** dedicated security unit + integration suite; token fuzz test.

---

## Milestone 07 — Action Engine
**Goal:** the right buttons on the right orders.
**Tasks:** 1) `Actions\ActionRegistry` with a registration API. 2) `Actions\EligibilityResolver` (status, age window, payment method, order/product type exclusions, per-order caps, cooldown) + `pph_action_eligibility` filter. 3) Hard exclusions for subscription and bookable orders. 4) Render actions in both list and detail contexts. 5) Reuse Woo's native `woocommerce_my_account_my_orders_actions` rather than duplicating it.
**Files:** `src/Actions/{ActionRegistry,EligibilityResolver}.php`, `templates/partials/actions.php`, `src/Integrations/Compat/*.php`.
**Acceptance:** eligibility matrix behaves exactly as specified across statuses × payment methods × order types; excluded types show no cancel or reorder button anywhere, including via direct REST call.
**Tests:** exhaustive eligibility matrix unit test; REST-level test that ineligibility is enforced server-side, not just hidden in the UI.

---

## Milestone 08 — Cancellation Requests (customer)
**Goal:** the install trigger works.
**Tasks:** 1) `Rest\RequestsController::create` with schema validation, nonce/token auth, rate limit, eligibility re-check. 2) Reason select + optional note UI in an accessible modal. 3) Confirmation copy that says *request*, not *cancelled*. 4) Pending branch state on the timeline with an expected response time. 5) Customer-initiated withdrawal of a pending request. 6) Woo order note on creation.
**Files:** `src/Rest/RequestsController.php`, `src/Actions/Cancel.php`, `src/Requests/RequestService.php`, `templates/partials/request-modal.php`, `assets/src/js/requests.js`.
**Acceptance:** eligible order → request created, order note written, both emails queued; ineligible order → 403 from REST even with a forged UI; duplicate request within cooldown → 429; guest with a valid token can request; guest without one cannot.
**Tests:** e2e happy path + every rejection path; IDOR test; CSRF test.

---

## Milestone 09 — Admin Request Queue
**Goal:** the merchant-side product.
**Tasks:** 1) Menu + subpages + pending-count bubble. 2) `Admin\RequestListTable` (`WP_List_Table`, filters by type/status/date, sortable whitelisted columns, paginated, indexed queries). 3) Request detail view with items, reason, history, internal note. 4) Approve/decline handlers: capability check, nonce, status transition, optional restock, order note, email. 5) Order-edit metabox showing linked requests. 6) Empty state.
**Files:** `src/Admin/{Menu,RequestListTable,RequestDetail,OrderMetabox}.php`.
**Acceptance:** approving sets `cancelled` and restocks per setting and **never issues a refund**; a user without `edit_shop_orders` gets 403 on every handler; queue query count is constant regardless of row count.
**Tests:** capability tests per handler, nonce-failure tests, restock assertion, query-count assertion.

---

## Milestone 10 — Emails
**Goal:** every state change is communicated.
**Tasks:** 1) `WC_Email` subclasses: request received (customer), request approved, request declined, new request (admin), secure order link (customer). 2) HTML + plain templates, theme-overridable. 3) Register with `woocommerce_email_classes`. 4) Locale resolved from the order, not the request. 5) Signed link injected into existing Woo transactional emails via a hook, opt-in per email type. 6) Optional admin daily digest.
**Files:** `src/Emails/*.php`, `templates/emails/*.php`.
**Acceptance:** all emails appear in WooCommerce → Settings → Emails and respect the customiser; plain-text variants contain no unescaped user input; correct language on a multilingual store; links resolve to the right order and expire.
**Tests:** email trigger integration tests, escaping assertions, multilingual locale test.

---

## Milestone 11 — Guest Lookup & Signed Links
**Goal:** no-login access without an enumeration oracle.
**Tasks:** 1) `[pph_order_lookup]` + `pph/order-lookup` block. 2) `Rest\LookupController` — order number + email, `hash_equals` comparison, uniform response, uniform timing, rate limited. 3) Send-secure-link-on-failure behaviour (to the address on file only). 4) Token→short-lived cookie context exchange so the token leaves the URL after landing. 5) Nocache headers. 6) CAPTCHA filter hook. 7) Off by default until enabled in the wizard.
**Files:** `src/Rest/LookupController.php`, `templates/lookup/{form,result}.php`, `src/Frontend/GuestContext.php`.
**Acceptance:** correct pair returns the order; incorrect email returns an indistinguishable response; throttle triggers at the configured threshold; sequential order-number probing yields no information; page is not cached by WP Rocket or LiteSpeed; token is absent from the URL after the first navigation.
**Tests:** enumeration test asserting response and timing equivalence; replay and tamper tests; cache-plugin verification; e2e guest journey.

---

## Milestone 12 — Reorder
**Goal:** repeat purchase without surprises.
**Tasks:** 1) `Actions\Reorder` wrapping Woo's `order_again` semantics. 2) Validate each line: exists, purchasable, in stock, variation resolvable, price delta. 3) Reconciliation summary before cart mutation. 4) Merge-vs-replace choice when the cart is non-empty. 5) Item cap + rate limit.
**Files:** `src/Actions/Reorder.php`, `templates/partials/reorder-summary.php`.
**Acceptance:** deleted product, out-of-stock item, deleted variation and changed price each produce an explicit, correct summary line; cart is never mutated before confirmation.
**Tests:** unit tests per failure mode; e2e with a deliberately broken historical order.

---

## Milestone 13 — Invoice Access & Get Help
**Goal:** two small tickets deflected cheaply.
**Tasks:** 1) `Integrations\Invoices\Detector` for common invoice plugins → surface their URL; fall back to Woo's print view. 2) Explicitly no PDF generation. 3) Get Help form pre-filled with order number, status, items and timeline state; submits to a configurable email or a `pph_help_submitted` action for helpdesk integrations. 4) Rate limit + sanitisation.
**Files:** `src/Integrations/Invoices/Detector.php`, `src/Actions/{Invoice,Help}.php`, `templates/partials/help-form.php`.
**Acceptance:** invoice button appears only when a source exists; help submission arrives with full context and no unescaped input; no PDF library is present in the codebase.
**Tests:** detector tests with each plugin fixture; escaping test on the help email.

---

## Milestone 14 — Settings & Onboarding
**Goal:** a merchant can configure this without reading docs.
**Tasks:** 1) Six-tab settings via the Settings API with per-field sanitisation callbacks. 2) Four-step wizard (status detection, handling time, tracking source, display mode with live preview). 3) Health panel (detected tracking plugin, detected invoice plugin, template conflicts, cron status). 4) Activation notice. 5) Confirmations on destructive toggles.
**Files:** `src/Admin/{SettingsPage,Wizard,HealthPanel,Notices}.php`.
**Acceptance:** frontend unchanged until the wizard completes; every setting round-trips and sanitises; guest access cannot be enabled without acknowledging the security note; wizard resumable after abandonment.
**Tests:** e2e wizard; sanitisation unit tests per field; capability test on settings save.

---

## Milestone 15 — Security Hardening & Audit
**Goal:** the Phase 8 table is fully discharged.
**Tasks:** 1) Line-by-line input/output audit. 2) PHPCS security sniffs at error level. 3) PHPStan level 6. 4) Adversarial self-review per threat row. 5) Independent review of `Security/` and `Rest/` by a second developer. 6) `SECURITY.md` + disclosure policy.
**Files:** cross-cutting; `SECURITY.md`.
**Acceptance:** every threat row has a passing test or a written mitigation with a rationale; zero `permission_callback => __return_true`; zero unprepared queries; zero unescaped echoes.
**Tests:** the security suite runs as a CI gate that blocks release.

---

## Milestone 16 — Test Suite & Compatibility Matrix
**Goal:** confidence to ship to thousands of stores.
**Tasks:** 1) Fill unit/integration coverage to target. 2) Complete the Playwright suite. 3) Execute the compatibility matrix. 4) Performance profile the orders pages. 5) Accessibility pass.
**Acceptance:** matrix green; no notices, console errors or Query Monitor warnings; orders page < 50ms added server time, < 15KB added assets; keyboard and screen-reader passes complete.

---

## Milestone 17 — Release
**Goal:** shippable.
**Tasks:** 1) `readme.txt`, changelog, screenshots, banner/icon. 2) POT generation + translator notes. 3) Docs: setup, guest access security, tracking integration, template overrides, filter reference. 4) Fresh-install and upgrade verification. 5) WP.org submission. 6) Support macros for the five predictable tickets (theme styling, no tracking showing, guest access off, request not received, timeline empty on old orders).
**Acceptance:** the Phase 18 checklist passes on a clean install and on an upgrade from a pre-release build.

---

# PHASE 12 — DEPENDENCY ORDER

```
M01 Foundation
 └─> M02 Data Layer
      ├─> M03 Timeline Engine ──> M04 Rendering Layer ──> M05 Estimated Delivery
      │                                   │
      │                                   └──────────────> M12 Reorder
      │                                   └──────────────> M13 Invoice & Help
      └─> M06 Security Layer ──> M07 Action Engine ──> M08 Cancellation Requests
                                       │                      │
                                       │                      ├─> M09 Admin Queue
                                       │                      └─> M10 Emails
                                       └─> M11 Guest Lookup  (also needs M06 + M10)

M14 Settings & Onboarding   <── needs M03–M13 feature-complete
M15 Security Hardening      <── needs everything
M16 Testing                 <── needs M15
M17 Release                 <── needs M16
```

**Critical path:** M01 → M02 → M03 → M04 → M07 → M08 → M09 → M14 → M15 → M16 → M17.

**Parallelisable / independently developable:**
- **M06 Security Layer** has no dependency beyond M01/M02 — build it early and in parallel with M03/M04. It is on the critical path for M08 and M11 and it is the milestone you least want rushed.
- **M05 (ETA)** depends only on M03; it can be built while M04's theme work is in progress.
- **M12 (Reorder)** and **M13 (Invoice/Help)** are fully independent of the request pipeline — good candidates for a second developer or for filling time while blocked on theme testing.
- **Tracking adapters** (part of M06-era Integrations work) depend only on M03's view model and can be developed against fixtures before any real plugin is installed.
- **M10 (Emails)** can be scaffolded against a fake request object before M08 lands.

**Never parallelise:** M04 with M14's display-mode preview (same rendering surface, guaranteed merge conflicts), and nothing at all with M15.

---

# PHASE 13 — CODING RULES

Accepted as given. Project-specific additions that the generic list does not cover:

21. **All order access via CRUD.** `wc_get_order()`, `wc_get_orders()`, `$order->get_meta()`, `$order->update_meta_data()` + `save()`. Zero direct reads of `postmeta` or `wc_orders_meta`. HPOS parity is not optional.
22. **Ownership is decided in exactly one place.** No inline `get_customer_id()` comparisons outside `OwnershipResolver`. Enforced by sniff and by review.
23. **Templates contain no business logic.** They receive a prepared view model and echo escaped values. No queries, no CRUD calls, no conditionals beyond presentation.
24. **Escape at the point of output, every time**, even when the value was sanitised on write. Sanitisation is not escaping.
25. **No outbound HTTP requests** anywhere in the free plugin.
26. **No new runtime Composer dependencies** without a written justification in the PR.
27. **Never write to another plugin's meta.** Tracking data is read-only. Adapters read; they do not repair.
28. **Filter before hardcode.** Any behavioural default a merchant might reasonably disagree with gets a filter, named and documented at introduction.
29. **Bounded growth.** Any array or table that grows with activity needs an explicit cap or retention rule at the moment it is introduced.
30. **Never change an order's status as a side effect** of a read path. Status transitions happen only in explicit, audited handlers.

---

# PHASE 14 — IMPLEMENTATION WORKFLOW

Acknowledged, with one addition. On `IMPLEMENT MILESTONE NN` the sequence is Inspect → Plan → Implement → Test → Review → Report, and then a full stop pending your approval.

Addition — **Step 0, Confirm Scope.** Before inspecting, restate the milestone's acceptance criteria and flag anything in it that has been invalidated by work done in earlier milestones. On a project where the plan is this detailed, the most likely failure is faithfully building a milestone that stopped making sense three milestones ago.

Reports will always include a **Deviations** section: anything built differently from this specification, with the reason. A spec that is silently diverged from is worse than no spec.

---

# PHASE 15 — TESTING STRATEGY

## Unit tests (no WP bootstrap, fast)
Stage mapping across every status permutation · business-day arithmetic (DST, year boundaries, leap years, holiday lists, non-UTC store timezones) · ETA calculation · eligibility resolver matrix · token generate/verify including tamper and expiry · rate-limiter windows · sanitiser (reason whitelist, note truncation, email normalisation) · reorder line-validation per failure mode · tracking adapter parsing per fixture.

## Integration tests (WP + Woo test suite, HPOS on and off)
Activation, deactivation, migration idempotency, uninstall both retention branches · transition recorder writing bounded meta · repository CRUD and query correctness · request lifecycle end to end · restock on approval · order notes written · email triggers firing with correct recipients and locale · settings round-trip · cron registration and cleanup · WP-CLI commands.

## Admin tests
Every handler rejects a user lacking `edit_shop_orders` with 403 · every POST rejects a missing or stale nonce · list table filters and whitelisted sorting produce correct SQL · pagination correct at boundaries · settings sanitisation rejects malformed input without fataling · approve/decline are idempotent (double-submit safe) · **assert no refund API is ever called**.

## Frontend tests
Shortcodes and blocks render for logged-in, logged-out and token contexts · action visibility matches eligibility in every context · request submission happy and unhappy paths · reorder reconciliation UI · guest lookup form · signed-link landing · logged-out user sees nothing privileged · assets absent on non-order pages.

## Security tests (CI gate — a failure blocks release)
Order-number enumeration: response body, headers and timing must be indistinguishable between existing and non-existing orders · IDOR: authenticated user requesting another customer's order, and request IDs belonging to another order · token tampering, truncation, expiry, cross-order replay, signature stripping · CSRF on every mutation · stored XSS via reason and note fields, asserted escaped in admin, in frontend and in both email formats · SQL injection through every filter, sort and search input · privilege escalation via direct REST calls to admin routes · rate-limit bypass via header spoofing and email casing/dot variants · mass-assignment through unexpected REST body fields · cache poisoning of a guest order page.

## Compatibility tests
WP 6.5 / 6.6 / 6.7 / latest · PHP 8.1 / 8.2 / 8.3 / 8.4 · Woo latest, −1, −2 · HPOS enabled, disabled, and mid-sync · classic My Account and the block-based Customer Account · themes: Storefront, Astra, Kadence, Blocksy, Divi, Flatsome, Elementor Hello + Elementor Pro Woo widgets · plugins: Advanced Shipment Tracking, official Shipment Tracking, a PDF invoice plugin, WooCommerce Subscriptions, WooCommerce Bookings, WPML, Polylang, WP Rocket, LiteSpeed Cache, Redis Object Cache · multisite.

## Browser tests (Playwright, via MCP)
Customer journeys: view order → read timeline → request cancellation → receive confirmation → see pending state. Guest journey: lookup → landing → action. Merchant journeys: wizard, queue triage, approve, decline. Error states: ineligible order, throttled, expired token, empty cart reorder. Responsive: 375px and 1440px. Visual regression screenshots per theme. Keyboard-only traversal of every interactive element.

## Coverage targets
≥70% line coverage on `Timeline/`, `Actions/`, `Requests/`, `Security/`, `Support/Dates`. No target on `Admin/` or `Frontend/` presentation code — chase e2e confidence there instead of coverage theatre.

---

# PHASE 16 — ACCEPTANCE CRITERIA

### Feature: Order timeline
**Given** an order created after activation that has moved Processing → Completed
**When** the customer views the order
**Then** stages up to Delivered show as complete with timestamps, and later stages show as pending
*Error:* corrupt `_pph_timeline` meta → the timeline renders from status alone and logs a warning; it never fatals
*Unauthorised:* a logged-out user without a token sees Woo's standard login prompt, no order data
*Empty:* an order created before activation shows stages without timestamps and no error
*Edge:* a cancelled order shows a terminal branch state, not a half-finished progress bar

### Feature: Estimated delivery
**Given** handling time of 2 business days and a shipping method with 3–5 day transit, order placed Friday 16:00 store time
**When** the customer views the order
**Then** a range is shown that excludes weekends and configured holidays and is never in the past
*Error:* unconfigured shipping method → no ETA shown, no placeholder, no error
*Edge:* real tracking data present → ETA is suppressed entirely in favour of the tracking block
*Edge:* store timezone ≠ UTC and the request crosses a DST boundary → date is still correct

### Feature: Cancellation request
**Given** a `processing` order 6 hours old, cancellation allowed within 24 hours, gateway not excluded
**When** the customer submits a request with a whitelisted reason
**Then** a `pending` row is created, an order note is written, the customer sees a pending branch state with an expected response time, and both emails send
*Error:* order is `completed` → REST returns 403 with a human message, even if the button was forged into the DOM
*Unauthorised:* another customer's order ID → 403, no information about whether the order exists
*Empty:* no reasons configured → a free-text note is accepted with the same sanitisation
*Edge:* second request within the cooldown → 429; a subscription or bookable order → no button and a 403 on direct call

### Feature: Admin approval
**Given** a pending cancellation request
**When** a user with `edit_shop_orders` approves it
**Then** the order becomes `cancelled`, stock is restocked if configured, an order note records who approved and when, the customer email sends, and **no refund is issued**
*Error:* order already `cancelled` by another route → request closes as `completed` with a note explaining the reconciliation; no duplicate transition
*Unauthorised:* `read`-only user → 403 on the handler, not merely a hidden button
*Edge:* double-submit → idempotent, one transition, one email

### Feature: Guest order lookup
**Given** guest access enabled
**When** a visitor submits a valid order number and the matching billing email
**Then** the order view renders with the eligible actions and nocache headers, and no token appears in any subsequent URL
*Error:* wrong email → an identical response, and a secure link is emailed to the address on file
*Unauthorised:* a valid order number with a wrong email must be indistinguishable from a non-existent order number in body, headers and response time
*Edge:* 6th attempt in 15 minutes → 429; expired or tampered token → generic failure with a re-request option; page must not be served from a page cache

### Feature: Reorder
**Given** a past order containing one in-stock item, one out-of-stock item and one deleted product
**When** the customer chooses Buy Again
**Then** a reconciliation summary lists all three outcomes explicitly before the cart is modified
*Error:* every line unavailable → clear message, cart untouched
*Edge:* non-empty cart → merge-or-replace choice; price changed since purchase → the delta is shown, not hidden

### Feature: Rendering mode
**Given** a theme that overrides `myaccount/orders.php`
**When** the merchant attempts to enable full replacement mode
**Then** a warning names the conflicting template and requires explicit confirmation
*Edge:* additive mode on the same theme must render without layout breakage on desktop and at 375px

---

# PHASE 17 — CODE REVIEW CHECKLIST (post-MVP)

**Architecture** — Is any abstraction serving only one implementation? Do `Timeline/`, `Actions/` and `Requests/` still communicate only through services? Has the container acquired logic? Is any class above ~300 lines or any method above ~50?

**Security** — Can an unprivileged user reach any privileged path? Does every REST route have a real permission callback? Is `assertCanAccess` the only ownership check in the codebase? Every `$wpdb` call prepared? Every echo escaped, including inside emails? Any state mutation on a GET? Any outbound HTTP that crept in?

**Performance** — Query count per orders-list render, independent of row count? Any query inside a loop? Any uncached repeated detection (tracking plugin, invoice plugin, template conflicts)? Admin assets absent from the frontend and vice versa? Any autoloaded option that shouldn't be?

**WordPress standards** — Hooks named and prefixed consistently? Existing capabilities used rather than invented? Settings API used properly with sanitisation callbacks? Nonces everywhere? All strings translatable with correct text domain and translator context on ambiguous ones? Uninstall covers everything installed and nothing more?

**Maintainability** — Would a competent Woo developer with no context find the cancellation flow in under five minutes? Is there duplicated eligibility or date logic? Are the filters documented in one reference? Is every non-obvious decision commented with a *why*, not a *what*?

---

# PHASE 18 — RELEASE PREPARATION

## Release checklist

**Install & lifecycle:** clean activation on the full matrix · deactivation preserves data and clears cron/transients · uninstall honours retention in both branches · migration from pre-release build · fresh install and upgrade both verified · plugin bails gracefully with Woo absent, deactivated mid-session, and on an unsupported PHP version.

**Runtime:** no PHP fatals, warnings or notices with `WP_DEBUG` on · no JS console errors on any surface · no Query Monitor warnings, no slow queries · no duplicate hook registration.

**Security:** the Phase 15 security suite green · no `__return_true` permission callbacks · secret generated at activation and not autoloaded · guest access off by default · nocache headers verified against WP Rocket and LiteSpeed.

**Data:** tables created with the specified indexes · retention cron scheduled and firing · no unbounded array or table.

**Frontend:** renders on all six test themes, additive and replacement, desktop and 375px · assets only on relevant endpoints · logged-in, logged-out and token contexts all correct · empty, loading and error states present everywhere · keyboard and screen-reader passes complete.

**Admin:** queue, detail, metabox, settings, wizard all functional · capability enforcement verified per handler · empty states present.

**i18n:** POT generated · no concatenated strings · translator context on ambiguous strings · locale from the order in emails · RTL layout check.

**Docs & support:** readme.txt with an honest tracking-dependency statement · setup guide · guest-access security page · template override guide · filter reference · five support macros ready.

## Version number

**1.0.0.** Not 0.x — WP.org users treat 0.x as beta and a large fraction will not install it. Semantic versioning thereafter; the schema version is tracked separately from the plugin version.

## Changelog (1.0.0)

Order timeline with automatic status detection · estimated delivery dates · self-service cancellation requests with merchant approval · guest order lookup and secure order links · reorder with change reconciliation · invoice access · contextual help form · tracking display integrated with Advanced Shipment Tracking, the official Shipment Tracking extension and native fulfilment data · admin request queue · transactional emails for all request states · HPOS compatible · translation ready.

## readme.txt requirements

Description leading with the deflected ticket, not the feature list · **an explicit "What this plugin does not do" section** naming the tracking dependency and the absence of carrier integration and PDF generation — this converts your worst 1-star review category into a pre-purchase expectation · FAQ covering theme styling, guest-access security, tracking sources, and template overrides · screenshots of the customer order page (both modes), the queue, and the wizard · privacy statement (no data leaves the site) · Tested up to, Requires PHP 8.1, Requires Plugins: woocommerce.

## Upgrade notes

1.0.0 is the initial release. Merchants upgrading from a pre-release must run the wizard again if the stage map changed. `wp pph backfill-timeline` is recommended, never automatic, and documented as safe to interrupt.

## Known limitations (state these publicly)

1. Tracking display requires a tracking plugin; without one, only estimated dates are shown.
2. No carrier API integration — no live in-transit scan events.
3. No PDF invoice generation; integrates with existing invoice plugins or falls back to the print view.
4. Cancellation approval does not issue refunds; refunds remain a deliberate merchant action.
5. Returns are not in 1.0.
6. Full replacement mode may require CSS adjustment on heavily customised themes; additive mode is the supported default.
7. Timeline timestamps are unavailable for orders created before activation unless backfilled.
8. Subscription and bookable orders are excluded from cancel and reorder.
9. Single-vendor only.

## Roadmap (publish it — it sells the free tier)

**1.1** Returns/RMA with item-level requests, eligibility rules engine, bulk queue actions, invoice-plugin integrations, request analytics.
**1.2** Custom status mapping, auto-approve automation, return shipping instructions and label upload, review prompts, webhooks, hardened uploads.
**Later** Public REST API, white-label and agency licensing, multi-vendor, store-credit outcomes via wallet integration.

---

# GO / NO-GO RECOMMENDATION

**Proceed — with the repositioning in A1 and the validation change below.**

The brief's validation plan asks the wrong question. "What percentage of your customer emails are just asking where their order is?" tests a problem that AST, the official Shipment Tracking extension, and a paid marketplace timeline plugin already partly solve for free. Ask both of these instead:

1. *"In the last month, how many customers emailed asking to cancel or change an order after placing it?"* — this is the uncontested problem and the actual install trigger.
2. *"How do you handle those today?"* — if the answer is "manually, in my inbox," you have a product. If it's "we don't allow cancellations," you have a segment problem worth knowing about before you build.

Keep the $150 ad spend and the side-by-side screen recording, but record the **cancellation flow**, not the timeline. Adjust the pre-order price to $49 against a $99 list rather than $39 — a 60% founder discount anchors the product below its intended price.

Downgrade the idea if fewer than 5 of 15 merchants report handling cancellation or change requests manually. Proceed to build at ≥8 paid pre-orders.

---

**Phase 0 complete. Awaiting `IMPLEMENT MILESTONE 01`.**
