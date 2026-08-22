# CLAUDE.md — Post-Purchase Hub for WooCommerce

You are working on a commercial WordPress plugin that will run on thousands of live WooCommerce stores. Read this file fully before your first action in any session.

**Authoritative spec:** `docs/SPEC.md`. It defines scope, architecture, database design, security design, UX, milestones and acceptance criteria. If a request conflicts with the spec, say so before acting — do not silently follow either one.

**Edition architecture:** `docs/EDITIONS.md`. This is a single repo producing two distributions. Read it before writing any code that could belong to one edition and not the other.

**Milestone prompts:** `docs/MILESTONE-PROMPTS.md`. Work proceeds one milestone at a time.

---

## Identity

| | |
| --- | --- |
| Plugin name | Post-Purchase Hub for WooCommerce |
| Slug / text domain | `post-purchase-hub` |
| Function/hook/option prefix | `pph_` |
| PHP namespace root | `PostPurchaseHub\` (PSR-4 → `src/`) |
| Free-only namespace | `PostPurchaseHub\Free\` → `free/src/` |
| Pro-only namespace | `PostPurchaseHub\Pro\` → `pro/src/` |
| Distributions | `post-purchase-hub-{v}.zip` (WP.org) and `post-purchase-hub-pro-{v}.zip` (store) |
| Order meta prefix | `_pph_` |
| DB table prefix | `{$wpdb->prefix}pph_` |
| REST namespace | `pph/v1` |
| Minimum | WordPress 6.5, PHP 8.1, WooCommerce latest−2 |

Never name anything with a leading `WooCommerce` — trademark policy. "… for WooCommerce" only.

---

## Hard rules

These are not preferences. Violating one is a bug even if tests pass.

1. **All order access through WooCommerce CRUD.** `wc_get_order()`, `wc_get_orders()`, `$order->get_meta()`, `$order->update_meta_data()` + `$order->save()`. Zero direct reads or writes of `postmeta`, `wc_orders`, or `wc_orders_meta`. HPOS and legacy storage must behave identically.
2. **Ownership is decided in exactly one place** — `PostPurchaseHub\Security\OwnershipResolver::assertCanAccess()`. No inline `get_customer_id()` comparison anywhere else, in any layer, ever.
3. **Every REST route has a real `permission_callback`.** `__return_true` is forbidden. Every route declares `args` with `validate_callback` and `sanitize_callback` per field.
4. **No state mutation on GET.** Ever.
5. **Escape at the point of output, every time**, even for values sanitised on write. Sanitisation is not escaping.
6. **Every `$wpdb` call uses `prepare()`.** `ORDER BY` and column names come from a hardcoded whitelist, never from request input.
7. **No outbound HTTP requests** anywhere in the free plugin. No telemetry, no update pings, no remote assets.
8. **No refund is ever issued by this plugin in v1.** Approving a cancellation changes status and optionally restocks. If you find yourself near `WC_Order_Refund` or `wc_create_refund()`, stop and ask.
9. **Never write to another plugin's meta.** Tracking data is read-only. Adapters read; they never repair or backfill.
10. **Templates contain no business logic.** They receive a prepared view model and echo escaped values. No queries, no CRUD calls in `templates/`.
11. **No new runtime Composer dependencies** without explicit approval. Dev dependencies are fine.
12. **Bounded growth.** Any array or table that grows with activity gets a cap or retention rule in the same commit that introduces it.
13. **Never change order status as a side effect of a read path.**
14. **Full template replacement is opt-in.** Additive rendering via Woo hooks is the default and must always work standalone.
15. **Guest access is off until explicitly enabled** in the setup wizard.

### Edition rules (see `docs/EDITIONS.md`)

16. **Core never references edition code.** No `PostPurchaseHub\Pro\` or `PostPurchaseHub\Free\` anywhere under `src/` — not in code, not in strings, not in docblocks, not in `class_exists()`. CI greps for this and fails the build.
17. **Core never branches on edition.** No `if ( pph_is_pro() )` in `src/`. Core registers extension points; Pro fills them. If Pro cannot be built on core's public surface, the surface is wrong — say so rather than reaching through it.
18. **No inline build markers.** Never write `//#if__PREMIUM`-style blocks. Edition code is separated by directory so the free artifact is a strict subset of tested source, never a rewritten one. Wanting a marker means a missing filter.
19. **Free must be coherent alone.** No dead buttons, no half-features, no error paths that only make sense with Pro installed.
20. **Never edit `bin/build.php` or `bin/verify-build.php` opportunistically.** If a verification check fails, fix the code it caught. Changing the check to pass is only acceptable as a deliberate, explained decision — raise it and wait.

---

## Architecture

```
src/
  Plugin.php            container + hook registration only, no business logic
  Install/              Activator, Deactivator, Schema, Migrator
  Timeline/             StageMap, TransitionRecorder, TimelineBuilder, EstimatedDelivery
  Actions/              ActionRegistry, EligibilityResolver, Cancel, Reorder, Invoice, Help
  Requests/             Request, RequestRepository, RequestService
  Security/             OwnershipResolver, TokenService, RateLimiter, Sanitizer
  Integrations/         Tracking/ (adapters), Invoices/, Compat/
  Rest/                 controllers + permission callbacks
  Emails/               WC_Email subclasses
  Frontend/             Renderer, TemplateLoader, Shortcodes, Blocks, Assets, GuestContext
  Admin/                Menu, RequestListTable, RequestDetail, SettingsPage, Wizard, Notices, OrderMetabox
  Support/              Logger, Cache, Dates
  CLI/                  WP-CLI commands
templates/              logic-free, theme-overridable via yourtheme/post-purchase-hub/
assets/src → assets/build (@wordpress/scripts)
tests/unit | tests/integration | tests/e2e | tests/fixtures

free/src|templates|tests    PostPurchaseHub\Free\  — upsell and locked-teaser UI only
pro/bootstrap.php           entry point, loaded via is_readable() if present
pro/src|templates|tests     PostPurchaseHub\Pro\   — returns, rules engine, analytics, licensing
pro/assets/src → build
bin/build.php               produces both zips
bin/verify-build.php        inspects the built zips for leakage
```

`src/` ships in both editions. The build deletes `pro/` for the free zip and `free/` for the pro zip. Pro attaches at the `pph_loaded` action and extends core only through documented filters, the `ActionRegistry`, interfaces and template overrides.

`Timeline/`, `Actions/` and `Requests/` are separate domains. They communicate through services and never reach into each other's storage.

Deliberate non-choices — do not introduce these: DI framework, template engine, React admin, custom post types, custom taxonomies, custom capabilities, Action Scheduler (v1), `admin-ajax`, runtime vendor packages.

---

## Coding standards

- WordPress Coding Standards via `phpcs.xml.dist` (WordPress-Extra + WordPress-Docs + WooCommerce-Core). PHPCS must be clean before you report a milestone done.
- PHPStan level 7 with `php-stubs/woocommerce-stubs`. Clean before reporting done.
- Classes under ~300 lines, methods under ~50. If you exceed it, split — don't argue.
- Every user-facing string translatable with `post-purchase-hub` text domain. Translator context (`_x`) on anything ambiguous. Never concatenate translatable strings.
- Comments explain **why**, never **what**. No comment restating the line below it.
- Any behavioural default a merchant might reasonably disagree with gets a documented filter at the moment it is introduced.
- Hook naming: `pph_{noun}_{verb}` for actions, `pph_{noun}` for filters.

---

## Testing

Run before reporting any milestone complete:

```bash
composer lint          # PHPCS
composer analyse       # PHPStan
composer test:unit     # PHPUnit, no WP bootstrap
composer test:int      # PHPUnit + wp-env, run for HPOS on AND off
npm run build          # asset build must succeed
```

Integration tests run twice: `HPOS=1` and `HPOS=0`. A milestone touching order data is not done until both pass.

Tests also run twice per edition: core-only, and core plus `pro/`. Pro must not change core behaviour except where a documented filter says it does. Before reporting any milestone that touched packaging, extension points or the plugin bootstrap:

```bash
npm run build && composer build && composer verify
```

`verify-build.php` inspects the actual zips rather than the source tree, because a build can pass every static check and still ship the wrong files.

Coverage targets: ≥70% on `Timeline/`, `Actions/`, `Requests/`, `Security/`, `Support/Dates`. No target on `Admin/` or `Frontend/` presentation — use e2e there.

Playwright MCP is available for browser tests. Use it for admin UI, customer journeys, error states, 375px and 1440px viewports, and per-theme visual checks. Do not write Playwright tests that depend on theme-specific selectors — target plugin-owned `data-pph-*` attributes.

---

## Workflow

Work one milestone at a time, from `docs/MILESTONE-PROMPTS.md`. For each:

**Step 0 — Confirm scope.** Restate the milestone's acceptance criteria and flag anything invalidated by earlier work. On a project this planned, the likeliest failure is faithfully building a milestone that stopped making sense three milestones ago.
**Step 1 — Inspect.** Read the relevant existing code. Never assume file contents.
**Step 2 — Plan.** State exactly what you will change, file by file, before touching anything.
**Step 3 — Implement.** Only what the milestone requires. No opportunistic refactors, no unrelated file edits, no "while I'm here."
**Step 4 — Test.** The commands above, plus the milestone's specific tests.
**Step 5 — Review.** Re-read your own diff against the Hard Rules and the spec's security table.
**Step 6 — Report.** Then **STOP** and wait for approval. Do not begin the next milestone.

### Report format

```
## Completed
## Files Changed          (path — one line why)
## Tests                  (what was added, what passes, coverage delta)
## Deviations             (anything built differently from docs/SPEC.md, and why)
## Issues Found           (bugs, spec gaps, ambiguities discovered)
## Technical Debt         (what was deliberately left)
## Next Milestone         (name it; do not start it)
```

`Deviations` is mandatory and may not be omitted or replaced with "none" unless the implementation matches the spec exactly. A spec silently diverged from is worse than no spec.

---

## Escalate instead of guessing

Stop and ask when you hit any of these:

- The spec is ambiguous or contradicts itself on a decision you need now.
- A hard rule blocks the cleanest implementation.
- A milestone needs a file or capability an earlier milestone was supposed to deliver but didn't.
- You need a new dependency, table, cron event, custom capability, or REST route not in the spec.
- A third-party plugin's meta structure differs from the spec's assumption.
- Anything touches refunds, payment gateways, file uploads, or session creation.

Say what you found, give the options with trade-offs, and wait. Do not pick the convenient one and note it in the report afterwards.

---

## Known traps in this codebase's problem space

- WooCommerce persists only `date_created`, `date_paid`, `date_completed`, `date_modified`. There is **no** per-status transition history. Do not try to derive one from order notes — they are localised and merchant-editable.
- Woo order IDs are sequential and guessable. Any code path that accepts an order ID from a request is an IDOR until proven otherwise.
- `order_again` exists in core but is exposed only for `completed` orders via `woocommerce_valid_order_statuses_for_order_again`. Wrap it; don't reimplement it.
- `[woocommerce_order_tracking]` already does order-number + email lookup in core. Read it before building `LookupController` — including its weaknesses.
- Advanced Shipment Tracking (70k+ installs) and the official Shipment Tracking extension write different meta shapes. Both are fixtures in `tests/fixtures/tracking/`.
- Every theme and page builder styles `myaccount/orders.php` and `view-order.php` differently. This is the single largest source of support tickets for this plugin. Additive-first is not negotiable.
- Corporate mail scanners pre-fetch URLs in emails. Signed tokens must be idempotent within their TTL, never one-time-burn on GET.
- Subscription parent/renewal orders and bookable products have different cancel semantics. Hard-excluded in v1.
- Both zips must contain a folder named exactly `post-purchase-hub/`. A different folder name in the Pro zip means the customer ends up with two copies installed instead of an upgrade.
- The Pro build needs an `Update URI` header. It shares its slug with a WP.org-hosted plugin, so without one WordPress will silently "update" a paying customer down to the free version. The failure is invisible until someone reports missing features.
- Composer's optimized classmap is generated per edition inside the build staging directory. A classmap carried over from the full tree points at stripped files and fatals on load.
