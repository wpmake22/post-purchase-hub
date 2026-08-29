# Claude Code Prompt Book — Post-Purchase Hub for WooCommerce

Paste one block per session. Each is self-contained: Claude Code reads `CLAUDE.md` automatically, so these carry only what's specific to the milestone.

**Rules of use**

- One milestone per session. Long sessions drift.
- Never paste the next milestone until you've read and approved the previous report.
- If a report has anything in **Deviations**, resolve it before moving on. Deviations compound.
- Prompts assume `docs/SPEC.md` and `CLAUDE.md` exist in the repo root / `docs/`.

**Dependency order** — M01 → M02 → {M03 → M04 → M05, M06 → M07 → M08 → M09/M10} → M11 → M12/M13 → M14 → M15 → M16 → M17.
M06 can be built in parallel with M03/M04. M12 and M13 are fully independent once M07 exists.

---

## M00 — Repository bootstrap

> Run once, before M01. Not a milestone; no report needed.

```
Set up the repository skeleton for a commercial WordPress plugin. Do not write any plugin logic yet.

Create:
- Directory structure exactly as specified in docs/SPEC.md Phase 5.
- composer.json: PSR-4 autoload PostPurchaseHub\ -> src/, dev deps for squizlabs/php_codesniffer, wp-coding-standards/wpcs, phpcompatibility/phpcompatibility-wp, woocommerce/woocommerce-sniffs, phpstan/phpstan, php-stubs/woocommerce-stubs, phpunit, yoast/phpunit-polyfills. Scripts: lint, lint:fix, analyse, test:unit, test:int.
- phpcs.xml.dist: WordPress-Extra + WordPress-Docs + WooCommerce-Core, text domain wpmake-post-purchase-hub, prefix wpmphub, minimum_supported_wp_version 6.5, PHP 8.1+ compatibility check, exclude vendor/node_modules/assets/build.
- phpstan.neon.dist: level 6, woocommerce-stubs + wordpress-stubs, paths src/.
- package.json using @wordpress/scripts for asset build from assets/src to assets/build.
- .wp-env.json with WordPress and WooCommerce, plus a second config for HPOS enabled.
- .github/workflows/ci.yml: matrix of PHP 8.1/8.2/8.3/8.4 x WP 6.5/latest x HPOS on/off. Jobs: lint, analyse, test:unit, test:int, npm build.
- .gitignore, .editorconfig, .distignore, SECURITY.md stub, docs/ containing SPEC.md and MILESTONE-PROMPTS.md.
- tests/ directory scaffolding with bootstrap files for unit (no WP) and integration (wp-env), plus empty tests/fixtures/tracking/.

Then verify: composer install succeeds, composer lint runs, composer analyse runs, npm run build runs. Report only the verification output and anything you had to decide that wasn't specified.
```

---

## M01 — Plugin Foundation

```
Implement MILESTONE 01 — Plugin Foundation, per docs/SPEC.md Phase 11.

Goal: an installable, standards-clean plugin that does nothing user-visible.

Build:
1. wpmake-post-purchase-hub.php — plugin header (Requires PHP 8.1, Requires at least 6.5, Requires Plugins: woocommerce), guards for PHP/WP/Woo version and Woo presence with a graceful admin notice and early return, then bootstrap only. No logic in this file.
2. src/Plugin.php — lazy service container (closures resolved on first get, memoised) plus a single register() that wires hooks. No business logic.
3. src/Install/Activator.php and Deactivator.php. Activation: generate wpmphub_token_secret (64 random bytes via wp_generate_password or random_bytes, base64, non-autoloaded option), set wpmphub_schema_version placeholder, schedule nothing yet. Deactivation: clear cron events and plugin transients only — never data.
4. HPOS declaration: FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true) on before_woocommerce_init.
5. src/Support/Logger.php wrapping WC_Logger with source 'wpmake-post-purchase-hub' and a context array.
6. src/Support/Cache.php — get/set/delete/incr, object-cache aware with a transient fallback, namespaced keys, explicit TTLs.

Acceptance criteria to satisfy:
- Activates and deactivates with zero PHP notices on WP 6.5 and latest, PHP 8.1 and 8.4, HPOS on and off.
- With WooCommerce absent or deactivated, the plugin shows an admin notice and does nothing else — no fatals.
- composer lint and composer analyse clean.

Tests to write:
- Integration: activation sets the secret and schema version; deactivation clears cron/transients but not options; re-activation does not regenerate the secret.
- Unit: Cache incr behaviour with and without an object cache backend.
- Integration: "Woo missing" bail path.

Follow the CLAUDE.md workflow: Step 0 confirm scope, then Inspect, Plan, Implement, Test, Review, Report, then STOP.
```

---

## M02 — Data Layer & Migrations

```
Implement MILESTONE 02 — Data Layer & Migrations.

Reference docs/SPEC.md Phase 7 for the exact schema. Do not invent columns or indexes; if you think one is missing, flag it in Step 0 and wait.

Build:
1. src/Install/Schema.php — dbDelta() for wpmphub_requests and wpmphub_request_items with the columns, types and indexes in Phase 7. Both tables created at install even though wpmphub_request_items has no writer until v1.1 (rationale is in the spec — do not "optimise" it away).
2. src/Install/Migrator.php — integer wpmphub_schema_version (non-autoloaded), checked once on plugins_loaded, runs numbered idempotent migration classes from src/Install/Migrations/. No migration may touch many rows inline.
3. src/Requests/Request.php — typed model, no DB access.
4. src/Requests/RequestRepository.php — create, find, findByOrder, update, query(filters, orderby, order, page, per_page), count(filters). Every query prepared. orderby and order validated against hardcoded whitelists. No SELECT * into PHP for lists.
5. uninstall.php — honours the wpmphub_settings 'delete_data_on_uninstall' flag, default FALSE. When true: drop both tables, delete wpmphub_* options, delete _wpmphub_* order meta in batches via CRUD, clear transients and cron. When false: nothing.
6. src/CLI/CleanupCommand.php — wp wpmphub cleanup, batched retention sweep, idempotent, --dry-run.

Acceptance:
- Tables created with exactly the specified indexes (assert via SHOW INDEX).
- Migrator runs once, is safe to run repeatedly, and never runs on a matching version.
- Uninstall removes nothing when retention is on and everything when off.
- Passing an unexpected orderby value must not reach SQL.

Tests: schema/index assertions; repository CRUD and query correctness including pagination boundaries; migration idempotency; uninstall both branches; SQL injection attempt through every query parameter.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M03 — Timeline Engine

```
Implement MILESTONE 03 — Timeline Engine.

Critical constraint from CLAUDE.md: WooCommerce stores no per-status transition history. Do NOT derive one from order notes.

Build:
1. src/Timeline/StageMap.php — default stages Placed, Confirmed, Packed, Shipped, Out for delivery, Delivered, plus branch states Cancelled, Refunded, Failed, On hold. Filters wpmphub_timeline_stages and wpmphub_status_stage_map. Also: detectUsedStatuses() scanning the last 200 orders via wc_get_orders(), result cached in Support\Cache for 12h — this feeds the M14 wizard.
2. src/Timeline/TransitionRecorder.php — hooks woocommerce_order_status_changed, appends {status, stage, timestamp_utc} to _wpmphub_timeline via CRUD. Forward-only: never rewrite an existing entry. Hard cap the array (10 entries), drop oldest non-terminal on overflow. One save per transition, no extra queries.
3. src/Timeline/TimelineBuilder.php — returns an immutable view model: ordered stages with state (complete/current/pending), timestamps where known, branch state if terminal, and a flag for "historical order, timestamps unavailable".
4. Graceful degradation: orders created before activation render stages from current status with no timestamps and no error. Corrupt _wpmphub_timeline meta must log a warning and fall back, never fatal.
5. src/CLI/BackfillCommand.php — wp wpmphub backfill-timeline, batched, resumable via a stored cursor, --dry-run, safe to interrupt. Derives what it can from date_created/date_paid/date_completed only.

Acceptance:
- Full lifecycle order renders correctly at every step.
- Pre-activation order renders stages without timestamps, no notice.
- _wpmphub_timeline never exceeds the cap.
- Identical output with HPOS on and off (assert in the same test).
- Zero order notes parsed anywhere.

Tests: unit tests per status path including out-of-order and repeated transitions; integration test asserting meta shape after each transition; explicit HPOS-on vs HPOS-off parity test; corrupt-meta fallback test.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M04 — Rendering Layer  ⚠ highest-risk milestone

```
Implement MILESTONE 04 — Rendering Layer. This is the highest-risk milestone in the project; the failure mode is breaking customer-facing pages on themes we cannot test. Read docs/SPEC.md risk T1 before starting.

Build:
1. src/Frontend/Renderer.php — ADDITIVE mode as default. Hook woocommerce_view_order, woocommerce_order_details_after_order_table, and woocommerce_my_account_my_orders_column_* . Never replace a template in this mode.
2. src/Frontend/TemplateLoader.php — resolves templates/ with theme override support at yourtheme/wpmake-post-purchase-hub/. Template names come from a hardcoded whitelist; no request-derived paths.
3. Replacement mode via the woocommerce_locate_template filter, gated behind a setting that defaults to off.
4. src/Admin/TemplateConflictScanner.php — detects whether the active theme or child theme overrides woocommerce/myaccount/orders.php or view-order.php; result cached; consumed by M14.
5. [wpmphub_orders] shortcode and a server-rendered wpmphub/orders block (block.json + render_callback).
6. src/Frontend/Assets.php — enqueue only on My Account order endpoints, the lookup page, and pages containing our shortcode/block. No global frontend CSS. Versioned from the build manifest.
7. templates/partials/timeline.php — accessible markup: an ordered list, text state labels (never colour alone), contrast >= 4.5:1 against Woo defaults, data-wpmphub-* attributes on every element e2e tests will target.

Performance requirement, non-negotiable: rendering the orders list must add ZERO queries per row. Prime any needed data in one batch before the loop. Assert this in a test.

Acceptance:
- Renders correctly in additive mode on Storefront, Astra, Kadence, Blocksy, Divi and Elementor Hello, desktop and 375px.
- Replacement mode refuses to enable silently when TemplateConflictScanner finds a conflict.
- Zero added queries per orders-list row.
- No plugin assets on any non-order page.

Tests: query-count assertion on a 20-order list; asset-scope assertion; Playwright visual screenshots per theme at 1440px and 375px using Playwright MCP; keyboard-only traversal of the timeline; template-override resolution unit test including a path-traversal attempt.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M05 — Estimated Delivery

```
Implement MILESTONE 05 — Estimated Delivery.

Context: this is the only part of the WISMO promise the plugin can keep without a third-party tracking plugin. It matters more than its size suggests.

Build:
1. src/Support/Dates.php — business-day arithmetic: add N business days from a datetime, honouring store timezone (wp_timezone), configurable weekend days, and a configurable holiday date list. Pure, fully unit-testable, no WP globals beyond the timezone.
2. src/Timeline/EstimatedDelivery.php — computes a range from handling time (global default + per-shipping-method override) plus per-method transit min/max. Returns a value object with start, end and a formatted localised string via wp_date().
3. Cache the computed range in _wpmphub_eta; invalidate on status change and on any shipping-line change.
4. Suppress the ETA entirely when real tracking data is available (M06 provides the check — until then, gate behind an interface and stub it).
5. Filter wpmphub_estimated_delivery receiving the value object and the order.
6. templates/partials/eta.php.

Acceptance:
- Correct across DST boundaries, year boundaries and leap years.
- Correct with a store timezone that is not UTC and not the server timezone.
- Never returns a date in the past; if the computed range has passed, return null and render nothing.
- Unconfigured shipping method: no ETA, no placeholder, no error.
- Disappears the moment tracking data exists.

Tests: table-driven unit tests over a fixture set of at least 25 date cases including Friday-evening orders, DST spring-forward and fall-back, Dec 31 rollover, and Feb 29. Integration test for cache invalidation on status change.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M06 — Security Layer

```
Implement MILESTONE 06 — Security Layer. Read docs/SPEC.md Phase 8 in full first. Everything downstream depends on this being right; prefer asking over guessing.

Build:
1. src/Security/OwnershipResolver.php — assertCanAccess(int $order_id, string $context): WC_Order. Accepts exactly three identities: logged-in customer matching the order's customer_id, a valid signed token bound to this order, or a user with edit_shop_orders. Throws a typed exception otherwise. This is the ONLY ownership check in the codebase.
2. src/Security/TokenService.php — payload is order_id|order_key|expiry; token is base64url(payload).hash_hmac('sha256', payload, secret). Verify with hash_equals only. Include the order key so rotating it revokes outstanding links. Default TTL 14 days, configurable, hard-capped at 90. Tokens are IDEMPOTENT within TTL — never one-time-burn (mail scanners pre-fetch links). Never create a WP session; wp_set_auth_cookie must not appear anywhere.
3. src/Security/RateLimiter.php — sliding windows keyed by IP, by SHA-256 email hash, and site-wide. Object-cache aware with transient fallback. Never wp_options. Casing- and dot-normalise emails before hashing so aliases can't bypass.
4. src/Security/Sanitizer.php — reason codes validated against a server-side whitelist; notes wp_strip_all_tags + 2000-char cap; email normalisation; a nocache() helper setting DONOTCACHEPAGE and Cache-Control: private, no-store.
5. A PHPCS sniff (or a documented CI grep gate if a sniff is disproportionate) that fails the build on get_customer_id() comparisons outside OwnershipResolver.

Acceptance:
- Tampered, truncated, expired, signature-stripped and cross-order-replayed tokens are all rejected.
- A logged-in customer requesting another customer's order is rejected with no information disclosure about existence.
- Rate limiter behaves identically with and without an object cache.
- Rotating an order key invalidates its outstanding tokens.

Tests: dedicated security suite. Include a token fuzz test (at least 500 mutated tokens, all must fail closed), an email-alias bypass test, and a test asserting wp_set_auth_cookie is never reachable from this namespace.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M07 — Action Engine

```
Implement MILESTONE 07 — Action Engine.

Build:
1. src/Actions/ActionRegistry.php — register/get actions with id, label, contexts (list|detail), and a resolver.
2. src/Actions/EligibilityResolver.php — evaluates: allowed order statuses, order age window, payment-method exclusions, order-type and product-type exclusions, per-order request caps, cooldown since last request. Returns a result object with a machine reason code and a customer-facing message. Filter wpmphub_action_eligibility.
3. src/Integrations/Compat/ — hard exclusions for WooCommerce Subscriptions parent and renewal orders and for bookable products, applied to cancel and reorder. Filterable but excluded by default.
4. Render eligible actions in both list and detail contexts, reusing Woo's woocommerce_my_account_my_orders_actions filter rather than duplicating the mechanism.
5. templates/partials/actions.php.

Critical: eligibility must be enforced server-side at the point of execution, not only used to hide buttons. Assume the UI is forged.

Acceptance:
- Eligibility is correct across the full matrix of order status x payment method x order type x age.
- An excluded order type has no button AND returns a denial when the action is invoked directly.
- Reason codes are stable strings suitable for logging and for support diagnosis.

Tests: exhaustive matrix unit test (generate the matrix, don't hand-write cases). A test that calls the action executor directly for every ineligible combination and asserts denial. Subscriptions and Bookings fixtures.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M08 — Cancellation Requests (customer side)

```
Implement MILESTONE 08 — Cancellation Requests, customer side. This is the plugin's install trigger; the copy matters as much as the code.

Build:
1. src/Rest/RequestsController.php — POST /wpmphub/v1/requests. Schema-validated args, nonce for logged-in users or signed token for guests, rate limited, eligibility RE-CHECKED at execution time (never trust the client). Also DELETE for customer withdrawal of a pending request.
2. src/Requests/RequestService.php — creates the row, writes a WooCommerce order note, fires wpmphub_request_created, queues emails (M10 will implement them; use the interface and stub if not yet present).
3. src/Actions/Cancel.php — the action definition and its executor.
4. templates/partials/request-modal.php — accessible modal: focus trap, focus restore on close, aria-describedby on validation errors, keyboard reachable. Reason select from the whitelist plus optional note.
5. Copy requirement: the UI must say "Request cancellation", never "Cancel order". Confirmation must state clearly that this is a request, not a completed cancellation, and show the configured expected response time.
6. Timeline branch state "Cancellation requested" with that expected response time.
7. assets/src/js/requests.js — no framework, fetch with X-WP-Nonce, aria-busy on the button, disabled during flight, error surfaced inline with a support reference ID matching the log line.

Acceptance:
- Eligible order: row created, order note written, pending state visible immediately, both emails queued.
- Ineligible order: REST returns 403 with a human message even when the button is injected into the DOM by hand.
- Second request inside the cooldown: 429.
- Guest with a valid token can submit; guest without one cannot.
- Nothing in this milestone touches refunds or payment gateways.

Tests: e2e happy path plus every rejection path via Playwright MCP. IDOR test (other customer's order id). CSRF test (missing and stale nonce). Direct-REST forgery test. Modal keyboard and screen-reader pass.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M09 — Admin Request Queue

```
Implement MILESTONE 09 — Admin Request Queue. This is the merchant-side product; treat it as a first-class surface, not admin plumbing.

Build:
1. src/Admin/Menu.php — submenu under WooCommerce: "Post-Purchase Hub" with Requests (default landing) and Settings. Pending count as a menu bubble. NO top-level menu.
2. src/Admin/RequestListTable.php — WP_List_Table over wpmphub_requests. Filters by type, status and date range. Sortable columns from a whitelist. Real pagination via RequestRepository. Columns: request, order (deep link), customer, type, reason, age, status, actions. Query count must be constant regardless of row count — assert it.
3. src/Admin/RequestDetail.php — items, reason, customer note, full history, internal admin note field.
4. Approve/decline handlers: capability check (edit_shop_orders) FIRST, then nonce, then action. Approve: transition order to cancelled, restock if the setting is on, write an order note naming the user and timestamp, fire wpmphub_request_approved, send the customer email. Decline: status + note + email. Both idempotent against double-submit.
5. src/Admin/OrderMetabox.php — linked requests on the order edit screen, with a link into the queue.
6. Empty state: "No requests yet. When a customer asks to cancel an order, it appears here." plus a link to preview the customer-facing order page.

Absolute constraint: approving must NEVER call wc_create_refund() or any gateway refund API. The customer email and the admin UI both link the merchant to Woo's own refund screen instead.

Acceptance:
- Approve transitions status, restocks per setting, writes the note, sends the email, issues no refund.
- A user with only 'read' gets 403 from every handler, not just a hidden button.
- Double-submitting approve produces one transition and one email.
- Order already cancelled by another route: request closes as completed with a reconciliation note, no duplicate transition.

Tests: capability test per handler; nonce-failure test per handler; restock assertion; idempotency test; query-count assertion at 5 and 500 rows; an explicit test asserting no refund function is called (mock/spy).

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M10 — Emails

```
Implement MILESTONE 10 — Emails.

Build as WC_Email subclasses registered via woocommerce_email_classes so merchants get Woo's template system, the customiser and the block email editor for free:
1. Customer: request received, request approved, request declined, secure order link.
2. Admin: new request; plus an opt-in daily digest.
3. HTML and plain-text templates in templates/emails/, theme-overridable.
4. Locale resolution from the ORDER, not the current request — critical on multilingual stores.
5. A hook that optionally injects a signed order link into existing Woo transactional emails, opt-in per email type, off by default.

Escaping requirement: plain-text variants are the usual place unescaped user input leaks. Assert escaping in both formats for customer notes and reasons.

Acceptance:
- All emails appear under WooCommerce > Settings > Emails and respect enable/disable, subject and heading overrides.
- Correct language on a WPML or Polylang store when the admin's locale differs from the customer's.
- Links resolve to the correct order and expire per TTL.
- No unescaped user input in HTML or plain text.

Tests: trigger integration tests asserting recipients, subject and locale; escaping assertions in both formats with an XSS payload in the note; token-in-email resolution test; digest test.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M11 — Guest Lookup & Signed Links

```
Implement MILESTONE 11 — Guest Lookup & Signed Links. This is the highest-severity security surface in the plugin. Read docs/SPEC.md Phase 8 "Guest lookup" before writing code, and read core's [woocommerce_order_tracking] implementation including its weaknesses.

Build:
1. [wpmphub_order_lookup] shortcode and wpmphub/order-lookup block.
2. src/Rest/LookupController.php — POST /wpmphub/v1/lookup taking order number and email. Both must match. Email compared with hash_equals after normalisation. Rate limited per IP, per email hash and site-wide.
3. NO EXISTENCE ORACLE. Success and failure must be indistinguishable in response body, status code, headers and response time. On any failure, respond that a secure link has been sent to the address on file if the order exists — and if it does exist, actually send one, to the address on the order only, never to the submitted address.
4. src/Frontend/GuestContext.php — on landing with a token, exchange it for a short-lived cookie-bound context and redirect so the token never appears in a subsequent URL, Referer header or server log.
5. Sanitizer::nocache() on every order-bearing response.
6. Filter hook for reCAPTCHA/Turnstile. Do not bundle a CAPTCHA.
7. Guest access setting defaults to OFF and cannot be enabled without the acknowledgement step in M14.

Acceptance:
- Correct pair returns the order with eligible actions.
- Wrong email is byte-identical and timing-equivalent to a non-existent order number.
- Sequential order-number probing yields no information without the matching email.
- Throttle fires at the configured threshold and logs a structured security event.
- Page is not served from cache by WP Rocket or LiteSpeed (verify with both installed).
- Token is absent from the URL after the first navigation.

Tests: enumeration test asserting body, header and timing equivalence across at least 50 paired requests; replay, tamper and expiry tests; email-alias rate-limit bypass test; cache-plugin verification; full e2e guest journey via Playwright MCP.

If any part of the timing-equivalence requirement cannot be met reliably in PHP, say so explicitly with your measurements and proposed mitigation rather than shipping something that looks constant-time but isn't.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M12 — Reorder

```
Implement MILESTONE 12 — Reorder.

Build:
1. src/Actions/Reorder.php — wrap Woo's order_again semantics (filter woocommerce_valid_order_statuses_for_order_again as needed); do not reimplement cart population from scratch.
2. Validate every line before touching the cart: product exists, is purchasable, is in stock at the required quantity, variation still resolvable, price delta vs the original line.
3. templates/partials/reorder-summary.php — an explicit reconciliation screen listing each line's outcome (added / unavailable / out of stock / variation changed / price changed with the delta). The cart must NOT be mutated before the customer confirms.
4. Merge-vs-replace choice when the cart is non-empty.
5. Item cap per attempt plus rate limiting.
6. Excluded order/product types per M07 must not offer reorder.

Acceptance:
- A fixture order containing one in-stock item, one out-of-stock item, one deleted product and one deleted variation produces four correct, explicit summary lines.
- All lines unavailable: clear message, cart untouched.
- Price changed: delta shown, never hidden.
- Non-empty cart: merge or replace, customer's choice.

Tests: unit test per failure mode; e2e with a deliberately broken historical order; assertion that no cart mutation occurs before confirmation.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M13 — Invoice Access & Get Help

```
Implement MILESTONE 13 — Invoice Access and Get Help. Deliberately small; resist scope growth.

Build:
1. src/Integrations/Invoices/Detector.php — detect commonly installed WooCommerce PDF invoice plugins and surface their existing download URL for the order. Fall back to Woo's own order print view. Cache detection.
2. NO PDF GENERATION. No dompdf, mPDF, TCPDF or any PDF library may appear in composer.json or the codebase. If you believe generation is required, stop and ask.
3. src/Actions/Invoice.php — the button, shown only when a source exists.
4. src/Actions/Help.php + templates/partials/help-form.php — form pre-filled with order number, status, item summary and current timeline state. Submits to a configurable recipient, and fires wpmphub_help_submitted so helpdesk plugins can intercept. Rate limited, sanitised, length-capped. This is a contextual handoff, not a ticketing system.

Acceptance:
- Invoice button absent when no source exists — no broken link, no placeholder.
- Help submission arrives with full order context and no unescaped input in either email format.
- grep confirms no PDF library anywhere.

Tests: detector unit tests with a fixture per supported invoice plugin plus the none case; escaping test on the help email with an XSS payload; rate-limit test.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M14 — Settings & Onboarding

```
Implement MILESTONE 14 — Settings and Onboarding.

Build:
1. src/Admin/SettingsPage.php — six tabs in this order: General, Timeline, Actions, Guest Access, Emails, Advanced. Settings API with a sanitisation callback per field. Single serialised wpmphub_settings option. Inline help on every field. No setting without a user story.
2. src/Admin/Wizard.php — four steps, skippable, resumable via wpmphub_setup_state:
   Step 1: detected statuses from StageMap::detectUsedStatuses(), proposed stage map, editable. Empty stages must not be shown to customers.
   Step 2: handling time, global plus per-shipping-method override.
   Step 3: tracking source — show what was detected (AST / official / native / none) and be honest when none is found, with a link to install one.
   Step 4: display mode, additive (recommended, default) vs full replacement, with a side-by-side live preview and a blocking warning if TemplateConflictScanner found a conflict.
   Final screen: which actions to enable. Cancellation defaults to request-and-approve, never auto-approve.
3. src/Admin/HealthPanel.php — detected tracking plugin, detected invoice plugin, template conflicts, cron status, schema version.
4. src/Admin/Notices.php — post-activation notice pointing at the wizard, dismissible, only on WooCommerce screens.
5. Confirmations required for: enabling full replacement mode, enabling guest access (with a security acknowledgement), enabling delete-data-on-uninstall.

Hard requirement: NOTHING renders on the frontend until the wizard completes. A plugin that silently rewrites the customer order page on activation is a plugin that gets uninstalled.

Acceptance:
- Fresh install: frontend unchanged until the wizard completes.
- Wizard completes in under two minutes on a store with existing orders.
- Every setting round-trips and sanitises; malformed input never fatals.
- Guest access cannot be enabled without the acknowledgement.
- Wizard resumable after abandonment mid-step.

Tests: e2e wizard flow via Playwright MCP including abandon-and-resume; sanitisation unit test per field including malformed and hostile input; capability test on settings save; a test asserting no frontend output pre-wizard.

Workflow per CLAUDE.md. Report, then STOP.
```

---

## M15 — Security Hardening & Audit

```
Implement MILESTONE 15 — Security Hardening and Audit. This is an audit milestone: mostly reading, minimal new code.

Work through docs/SPEC.md Phase 8's threat table row by row. For every row, produce either a passing automated test or a written mitigation with rationale. There is no third option and "not applicable" needs an argument.

Tasks:
1. Line-by-line input and output audit of every file. Produce a table: file, input source, validation, sanitisation, output context, escaping function.
2. Raise PHPCS security sniffs to error level; fix everything they surface.
3. PHPStan to level 6 clean (level 7 if achievable without pointless annotations — report which).
4. Adversarial self-review: for each of the 15 abuse scenarios in the spec, attempt it against the running code and record the result.
5. Grep gates added to CI, each failing the build: permission_callback => __return_true anywhere; $wpdb-> query without prepare; wp_set_auth_cookie anywhere; wc_create_refund anywhere; get_customer_id comparison outside OwnershipResolver; wp_remote_ anywhere.
6. SECURITY.md with supported versions and a disclosure policy.

Deliver as your report:
- The input/output audit table.
- The threat table with test name or mitigation per row.
- Findings, each with severity, file, and fix status.
- Anything you could not verify and why.

Be adversarial about your own earlier work in this repo. Assume you made mistakes in M08 and M11 specifically, and go looking for them.

Report, then STOP.
```

---

## M16 — Test Suite & Compatibility Matrix

```
Implement MILESTONE 16 — Test Suite and Compatibility Matrix.

Tasks:
1. Raise coverage to >=70% on Timeline/, Actions/, Requests/, Security/, Support/Dates. Report actual figures per namespace. Do not pad with trivial getter tests — if a target is unreachable meaningfully, say so and explain.
2. Complete the Playwright suite via Playwright MCP:
   Customer: view order, read timeline, request cancellation, receive confirmation, see pending state.
   Guest: lookup, landing, action.
   Merchant: wizard, queue triage, approve, decline.
   Errors: ineligible order, throttled, expired token, empty-cart reorder, no-tracking shipped order.
   Viewports 375px and 1440px. Target data-wpmphub-* attributes only, never theme selectors.
3. Execute the compatibility matrix from docs/SPEC.md Phase 15 and report a pass/fail grid: WP 6.5/6.6/6.7/latest, PHP 8.1-8.4, Woo latest/-1/-2, HPOS on/off/mid-sync, classic vs block My Account, six themes, and the plugin set (AST, official Shipment Tracking, a PDF invoice plugin, Subscriptions, Bookings, WPML, Polylang, WP Rocket, LiteSpeed, Redis Object Cache), multisite.
4. Performance profile with Query Monitor: added server time and added asset weight on the orders list and order detail. Budget: <50ms and <15KB. Report actuals.
5. Accessibility: keyboard-only traversal of every interactive element, and a screen-reader pass on the timeline, modal and lookup form.

Report the grid and the numbers, not a summary judgement. Where something fails, propose the fix but do not implement it without approval.

Report, then STOP.
```

---

## M17 — Release

```
Implement MILESTONE 17 — Release preparation for 1.0.0.

Tasks:
1. readme.txt for WP.org. Description leads with the deflected support ticket, not a feature list. It MUST include an explicit "What this plugin does not do" section naming: the tracking-plugin dependency, no carrier API integration, no PDF generation, no automatic refunds, returns not in 1.0. FAQ covering theme styling, guest-access security, tracking sources and template overrides. Privacy statement: no data leaves the site. Requires PHP 8.1, Requires at least 6.5, Requires Plugins: woocommerce.
2. Changelog for 1.0.0 per docs/SPEC.md Phase 18.
3. POT generation with wp i18n make-pot, plus translator notes for ambiguous strings. Verify no concatenated translatable strings remain.
4. Docs in docs/: setup guide, guest-access security page, tracking integration guide, template override guide, complete filter and action reference generated from the codebase.
5. Verification runs: clean install; upgrade from a pre-release build; uninstall with retention on and off; deactivate/reactivate.
6. Work the Phase 18 release checklist item by item and report pass/fail for each line.
7. Five support macros for the predictable tickets: theme styling in replacement mode, no tracking showing, guest access appears disabled, request not received, timeline empty on old orders.
8. .distignore verified so the built zip excludes tests, dev config and assets/src. Report the zip's file count and size.

Report the checklist results line by line. Then STOP.
```

---

# Utility prompts

Use these between milestones, not instead of them.

## Pre-flight before a risky milestone

```
Before we implement MILESTONE {NN}, do a read-only investigation. Do not modify any file.

1. Read every existing file this milestone will touch and summarise what is actually there versus what the spec assumes.
2. Read the relevant WooCommerce core source (name the files) and tell me what core already provides that we would otherwise duplicate.
3. List every assumption in the milestone description that you cannot verify from the codebase.
4. Name the three most likely ways this milestone breaks something already working.
5. Propose the implementation order within the milestone.

Output only that analysis. No code.
```

## Mid-milestone course correction

```
Stop implementing. Show me the current diff, then answer:
1. What in the milestone's acceptance criteria is now satisfied, and what isn't?
2. What have you changed that the milestone did not ask for?
3. Have you violated any CLAUDE.md hard rule? Check each of the 15 explicitly and answer per rule.
4. What did you discover that should change the plan?

Do not continue until I respond.
```

## Adversarial review of a completed milestone

```
Review MILESTONE {NN} as a hostile senior reviewer who did not write it and assumes it is broken.

For each, cite file and line:
1. Security: any path reaching order data without OwnershipResolver; any unprepared query; any unescaped output including in emails and admin; any state change on GET; any missing capability or nonce check.
2. HPOS: any direct meta access; anything that would behave differently with HPOS off.
3. Performance: queries in loops; uncached repeated detection; autoloaded options that shouldn't be; unconditional asset loading.
4. Correctness: unhandled failure paths; assumptions about third-party data shape; timezone and locale handling.
5. Maintainability: classes over 300 lines; methods over 50; duplicated logic; abstractions with one implementation.
6. Spec drift: anything differing from docs/SPEC.md that was not reported as a deviation.

Rank findings by severity. Propose fixes. Do not implement anything yet.
```

## Theme compatibility sweep (Playwright MCP)

```
Run a rendering compatibility sweep using Playwright MCP. Do not change plugin code.

For each of Storefront, Astra, Kadence, Blocksy, Divi and Elementor Hello:
1. Activate the theme.
2. Screenshot My Account > Orders and a single order detail page at 1440px and 375px, in additive mode.
3. Note any overflow, overlap, unreadable contrast, broken alignment or missing element.
4. Record whether the theme overrides myaccount/orders.php or view-order.php.

Then produce a grid: theme x viewport x pass/fail with the specific defect. Propose CSS fixes that are scoped to our data-wpmphub-* elements only and never affect theme styles. Do not apply them yet.
```

## Jira ticket creation (Atlassian MCP)

```
Using the Atlassian MCP, create tickets for the findings in the review above.

One ticket per finding. Each with: a title stating the defect not the fix, description containing file, line, reproduction, expected vs actual, and the CLAUDE.md rule or spec section it violates. Priority from the severity ranking. Label: wpmake-post-purchase-hub, plus one of security / compat / performance / correctness / maintainability. Link all to the milestone epic.

Show me the list before creating anything.
```

## Session handoff

```
This session is ending. Write docs/HANDOFF.md containing:
1. Milestone in progress and its completion state against each acceptance criterion.
2. Every file created or modified this session, with why.
3. Decisions made that are not in docs/SPEC.md, and their rationale.
4. Open questions requiring my input, each with the options and trade-offs.
5. The exact next action for the following session.
6. Anything left broken or half-finished, named explicitly.

Be blunt about incomplete work. A handoff that overstates progress costs more than one that understates it.
```
