# Security audit — Milestone 15

Audit of Post-Purchase Hub for WooCommerce against `docs/SPEC.md` Phase 8,
performed at M15. Two tables: every input the plugin accepts and what happens
to it, and Phase 8's fifteen-row threat table with the test or the mitigation
that answers each row.

This document is the record of what was checked. The enforcement lives in
`bin/security-gates.sh`, `phpcs.xml.dist`, `phpstan.neon.dist` and
`tests/unit/Security/ThreatModelTest.php`, so that it runs rather than ages.

---

## 1. Input and output audit

### 1.1 How the "no other input" claim is held

Only the files below read anything from outside the process. That is not a
promise, it is checkable:

- `grep -rn '\$_GET\|\$_POST\|\$_REQUEST\|\$_SERVER\|\$_COOKIE\|\$_FILES\|php://input' src/ free/ pro/ templates/`
  returns exactly the rows in 1.2 and nothing else.
- `ThreatModelTest::test_no_template_reads_request_input()` fails the build if a
  template grows a superglobal (CLAUDE.md hard rule 10).
- `ThreatModelTest::test_nothing_accepts_a_file_upload()` fails the build on
  `$_FILES`, `wp_handle_upload`, `media_handle_*` or `move_uploaded_file`.
- PHPCS's `WordPress.Security.ValidatedSanitizedInput` is an **error** as of
  M15, so an unsanitised or unslashed superglobal read cannot merge.

Everything else in `src/` receives already-validated arguments from these
entry points, or reads this plugin's own options.

### 1.2 HTTP inputs

| File | Input source | Validation | Sanitisation | Output context | Escaping |
| --- | --- | --- | --- | --- | --- |
| `Rest/LookupController.php` | `POST /pph/v1/lookup` → `order_number` | `is_scalar`, non-empty after trim, ≤ `OrderLookup::MAX_NUMBER_LENGTH` (64) | `sanitize_text_field` | JSON `message` | n/a — the message is one of three fixed translated strings; no input is reflected |
| `Rest/LookupController.php` | `POST /pph/v1/lookup` → `email` | `is_scalar`, ≤254, `is_email` | `sanitize_email` | JSON `message`; the address itself only ever reaches `Sanitizer::hash_email()` | n/a — never echoed, never logged raw |
| `Rest/LookupController.php` | `$_SERVER['REMOTE_ADDR']` | none needed — identity only | `sanitize_text_field` + `wp_unslash` | none | n/a — hashed by `RateLimiter`, truncated hash in logs |
| `Rest/RequestsController.php` | `POST /pph/v1/requests` → `order_id` | `is_numeric` and `> 0` | `absint` | JSON integer | n/a |
| `Rest/RequestsController.php` | → `reason_code` | `Sanitizer::reason_code()` against `Cancel::reason_codes()` | `sanitize_text_field` | JSON string; admin list table; email subject lines | `esc_html()` at every render |
| `Rest/RequestsController.php` | → `note` | `is_scalar` | `Sanitizer::note()` — `wp_strip_all_tags` then `mb_substr` to 2000 | wp-admin detail screen, HTML email, plain-text email | `esc_html()` in all three; `wpautop( esc_html() )` where paragraphs are wanted |
| `Rest/RequestsController.php` | → `token` | `is_scalar` | regex `^[A-Za-z0-9_-]*\.[a-f0-9]*$`, then HMAC-verified by `TokenService::decode()` with `hash_equals` | none | n/a — never echoed |
| `Rest/RequestsController.php` | `DELETE /pph/v1/requests/{id}` → `id` | `is_numeric` and `> 0`; the row is then re-verified against its order's owner, never trusted from `request_id` | `absint` | JSON integer | n/a |
| `Rest/HelpController.php` | `POST /pph/v1/help` → `topic` | `HelpTopics::normalise()` whitelist | `sanitize_text_field` | HTML and plain-text email | `esc_html()` |
| `Rest/HelpController.php` | → `message` | `is_scalar`, `mb_strlen` ≤ 4 × `Help::MESSAGE_MAX_LENGTH` at the schema, so markup cannot spend the real budget | `Sanitizer::note()` | HTML and plain-text email | `wpautop( esc_html() )` / `esc_html()` |
| `Rest/ReorderController.php` | `POST /pph/v1/reorder` → `mode` | `in_array` against `ReorderOptions::modes()` | `ReorderOptions::normalise_mode()` (falls back to merge) | JSON string | n/a |
| `Rest/ReorderController.php` | → `order_id` | `is_numeric` and `> 0` | `absint` | JSON | n/a |
| `Frontend/LookupForm.php` | `$_POST[pph_order_number]`, `$_POST[pph_order_email]`, `$_POST[pph_order_lookup]` | same service as the REST route — `Security\GuestLookupService` | `sanitize_text_field` / `sanitize_email` + `wp_unslash` | 302 to `Urls::current()`; nothing submitted is rendered (post/redirect/get) | n/a |
| `Frontend/LookupForm.php` | `$_GET[pph_lookup]` | matched against four `LookupResult` constants | `sanitize_key` | notice text chosen from a fixed table, never taken from the URL | `esc_html()` in `templates/lookup/form.php` |
| `Frontend/GuestContext.php` | `$_GET[pph_token]` | regex `^[A-Za-z0-9_-]+\.[a-f0-9]{64}$`, then HMAC + expiry + order-key in `TokenService` / `OwnershipResolver` | `sanitize_text_field` + `wp_unslash` | `Location` header, token stripped | `wp_safe_redirect()`; host comes from `home_url()`, never the `Host` header |
| `Frontend/GuestContext.php` | `$_COOKIE[pph_guest_context]` | regex `^[a-f0-9]{32}$` | `sanitize_text_field` + `wp_unslash` | none | n/a — used as a cache key under `sha256` |
| `Frontend/GuestOrderView.php` | `$_GET[pph_context]` | compared to two constants | `sanitize_key` | one of two fixed strings | `esc_html()` |
| `Frontend/ReorderView.php` | `$_GET[pph_reorder]` | must equal the order already being rendered | `absint` | none | n/a |
| `Frontend/Assets.php` | `$_GET[pph_reorder]` | presence test only | `absint` | none | n/a |
| `Support/Urls.php` | `$_SERVER['REQUEST_URI']` | path and query only; scheme and host are taken from `home_url()` | `esc_url_raw` + `wp_unslash` | `Location` header, form `action` | `wp_safe_redirect()` / `esc_url()` |

### 1.3 Admin inputs

Every row runs a capability check **before** its nonce check, per Phase 8: a
stale nonce from a user who should never have reached the screen is a 403, not
an invitation to retry.

| File | Input source | Authorisation | Validation | Sanitisation | Output context | Escaping |
| --- | --- | --- | --- | --- | --- | --- |
| `Admin/RequestActionController.php` | `$_POST` on `admin_post_pph_{approve,decline}_request` | `edit_shop_orders`, then `wp_verify_nonce( …, 'pph_request_action' )` | `request_id` `is_scalar`; the row's order is re-resolved and re-checked | `absint`, `sanitize_text_field` for `admin_note` | redirect only | `wp_safe_redirect()` |
| `Admin/Notices.php` | `$_REQUEST[_wpnonce]`, `$_REQUEST[redirect]` | `manage_woocommerce`, POST-only guard, then nonce | `redirect` must match `^[a-z0-9_-]+\.php(\?page=[a-z0-9_-]+)?$` or is discarded | `sanitize_text_field` + `wp_unslash` + `rawurldecode` | redirect only | `wp_safe_redirect( admin_url( … ) )` |
| `Admin/Wizard.php` | `$_POST[pph_settings]` (array), `$_POST[pph_step]`, `$_POST[pph_skip]` | `manage_woocommerce`, `check_admin_referer()` in the caller before anything is read | per-field, by `SettingsSanitizer::sanitize_field()` against the field's declared type | per-field; the raw array is never used directly | admin form values | `esc_attr()` / `esc_html()` in `SettingsRenderer` |
| `Admin/SettingsPage.php` | `$_GET[tab]`, `$_POST[pph_settings_tab]` | core's `options.php` runs capability (`manage_woocommerce`, declared via `option_page_capability_*`) and nonce before the sanitise callback | matched against `SettingsFields::TABS` | `sanitize_key` | tab markup | `esc_attr()` / `esc_url()` / `esc_html()` |
| `Admin/RequestListTable.php` | `$_GET[type,status,order_id,orderby,order,s,paged]` | `edit_shop_orders` on the screen | `orderby`/`order` whitelisted by `RequestQuery::order_by()`; `per_page` clamped to 100 | `sanitize_key` / `absint` / `sanitize_text_field` | list-table cells | `esc_html()` / `esc_url()` / `esc_attr()` |
| `Admin/Menu.php` | `$_GET[request_id]` | `edit_shop_orders` | `> 0`, row looked up and re-verified | `absint` | detail screen | `esc_html()` |
| `Admin/Assets.php` | `$_GET[page]` | admin screen | compared against a fixed list | `sanitize_key` | none | n/a |

### 1.4 Non-HTTP inputs

| Source | File | Validation | Notes |
| --- | --- | --- | --- |
| WP-CLI associative args | `CLI/BackfillCommand.php`, `CLI/CleanupCommand.php` | `(int)` with a `max( 1, … )` floor; flags are presence tests | Reaching WP-CLI is already shell-level authentication |
| `pph_settings` option | read in ~10 classes | `is_array()` guard then per-key `(int)` / `(bool)` / `is_array` | Never trusted as an array of the right shape |
| `pph_token_secret` option | `Security/TokenService.php` | `(string)`; an empty secret makes `issue()` throw and `decode()` return null | Fails closed, never falls back to a fixed key |
| Filter return values | throughout | every filter whose value matters is re-typed at the call site | `pph_token_ttl_days` is re-clamped to ≤90 **after** the filter; `pph_guest_lookup_enabled` can only disable; `pph_lookup_order_id` is `(int)` then floored at 0; `pph_locate_template` is existence-checked and discarded if unreadable; `pph_action_eligibility` must return an `EligibilityResult`; `pph_cancel_reason_codes` must return a non-empty array |
| Another plugin's shortcode output | `Integrations/Invoices/PdfInvoicesPackingSlips.php` | `FILTER_VALIDATE_URL`, then `esc_url_raw()` | Read-only. Output is `esc_url()`'d again in `templates/partials/actions.php` |
| Third-party tracking meta | — | — | Nothing reads it today; `NullTrackingAvailability` answers the `pph_has_tracking_data` filter and no concrete adapter ships. See "Issues found". |

---

## 2. Phase 8 threat table

Every row has a passing automated test or a written mitigation. No row is
answered with "not applicable" without an argument.

| # | Threat | Answer | Where |
| --- | --- | --- | --- |
| 1 | **CSRF** | Test | Admin: `RequestActionControllerTest::test_approve_rejects_a_stale_nonce`, `…_a_missing_nonce`, `WizardTest::test_it_refuses_a_bad_nonce`. Logged-in REST: core's `rest_cookie_check_errors()` rejects a missing `X-WP-Nonce` before any callback runs — not re-tested here because testing core is not this suite's job. Guest REST: the context cookie is `SameSite=Lax`, asserted by `GuestContextTest::test_the_cookie_is_http_only_and_lax`, so a cross-site POST carries no identity. No GET mutates: `Notices::dismiss()` answers 405 to a non-POST even though `admin_post_*` fires for both. |
| 2 | **XSS (reflected)** | Test + tooling | `ThreatModelTest::test_no_template_reads_request_input`; `LookupFormTest` proves the notice text is chosen from a fixed table rather than echoed from the URL. `WordPress.Security.EscapeOutput` is an error, and all twelve `phpcs:ignore`s against it were re-read at M15: each is either `wpautop( esc_html( … ) )`, `wp_kses_post()` (the escaping call itself, matching WooCommerce's own plain-text footers), core's `checked()` helper, or an exception whose message was built from already-inert text. None bypasses escaping. |
| 3 | **XSS (stored)** | Test | Reason codes are whitelisted (`SanitizerTest::test_reason_code_rejects_anything_not_whitelisted`). Notes are stripped and capped on write (`SanitizerTest::test_note_strips_markup`, `…_is_capped_at_the_configured_length`) **and** escaped at every render — admin (`RequestDetail.php:147`), HTML email, plain-text email. |
| 4 | **SQL injection** | Test | `RequestQueryTest::test_injection_through_a_value_stays_an_argument`, `…_through_a_slug_stays_an_argument`, `…_an_unexpected_orderby_is_rejected`, `…_an_unexpected_direction_is_rejected`. Gate 2 of `bin/security-gates.sh` fails the build on an unprepared `$wpdb` call. |
| 5 | **IDOR** | Test | `OwnershipResolverTest` (11 tests) plus per-controller refusals: `RequestsControllerTest::test_a_different_customer_cannot_withdraw`, `HelpControllerTest::test_another_customers_order_is_refused`, `ReorderControllerTest::test_another_customers_order_is_refused`. Gate 5 fails the build on any `get_customer_id()` outside `OwnershipResolver`. Requests are looked up by id then re-verified against their order's owner. |
| 6 | **Privilege escalation** | Test | `RequestActionControllerTest::test_approve_rejects_a_user_without_the_capability`, `…_decline_rejects_…`, `WizardTest::test_it_refuses_a_user_without_the_capability`, `OwnershipResolverTest::test_an_unrelated_capability_does_not_grant_access`. Queue and approve/decline require `edit_shop_orders`; settings require `manage_woocommerce`; no custom capabilities. |
| 7 | **SSRF** | Test | `ThreatModelTest::test_no_outbound_http_request_exists` and gate 6. Zero outbound HTTP: no telemetry, no update pings, no remote assets. |
| 8 | **Path traversal** | Test | `TemplateLoaderTest::test_names_off_the_allow_list_are_refused`, `…_traversal_is_refused_even_when_the_target_exists`, `…_an_unreadable_filtered_path_falls_back`. Names come from a 13-entry constant; no request-derived path exists. |
| 9 | **File uploads** | Test | `ThreatModelTest::test_nothing_accepts_a_file_upload`. Not applicable **because** there is no upload path, and that is now enforced rather than assumed. |
| 10 | **Arbitrary execution** | Test | `ThreatModelTest::test_nothing_executes_a_constructed_callable` — no `eval`, `unserialize`, `create_function`, `call_user_func` or variable-variable anywhere in shipped code. |
| 11 | **Sensitive data exposure** | Test + mitigation | Nocache on every order-bearing response: `ThreatModelTest::test_every_rest_permission_callback_opens_by_refusing_the_cache` (**added at M15** — the nine existing `defined( 'DONOTCACHEPAGE' )` assertions are order-dependent and vacuous after whichever runs first, so this is the form that actually holds), plus `GuestContextTest::test_a_cookie_borne_request_is_marked_uncacheable`. Emails are hashed in logs: `ThreatModelTest::test_no_log_call_carries_a_raw_email`, `GuestLookupServiceTest::test_a_throttle_logs_a_structured_security_event`. PII in query strings: the only credential that travels in a URL is the signed token, and `GuestContextTest::test_the_exchange_strips_the_token_from_the_target` proves it survives exactly one navigation. |
| 12 | **REST permissions** | Test | `ThreatModelTest::test_no_route_is_open` and gate 1. Each of the four controllers asserts its own route shape — `test_the_route_is_post_only_with_a_real_permission_callback` — including that every declared field carries both a `validate_callback` and a `sanitize_callback`. |
| 13 | **Rate limiting** | Test | All five surfaces Phase 8 names. Lookup and link-request (the same flow — a link request *is* a lookup): `GuestLookupServiceTest` IP/email/site. Cancel: `RequestsControllerTest` IP/email/site. Reorder: `ReorderControllerTest` IP/email/site (**email and site added at M15 — they were unexercised**). Help-submit: `HelpControllerTest` IP/email/site (**email and site added at M15**). |
| 14 | **Enumeration via emails** | Test | `GuestLookupServiceTest::test_only_a_match_queues_a_link_for_the_order`, `…_a_wrong_email_queues_nothing`, `…_every_outcome_returns_the_identical_message`, `…_a_hit_and_a_miss_share_a_timing_envelope`. The link goes to `$order->get_billing_email()`, never to the submitted address. |
| 15 | **Abuse of requests** | Test | `EligibilityResolverTest` per-order cap and cooldown; `CancelTest`; `RequestsControllerTest::test_create_returns_429_when_the_cooldown_is_still_running`. Merchant approval is mandatory — nothing customer-initiated changes an order's status. |

### 2.1 Accepted residual risks

Three things the controls above bound rather than eliminate. Each is a
deliberate trade, recorded here so a later reader does not mistake it for an
oversight.

1. **Rate-limit counters are not atomic on the transient backend.**
   `Support\Cache::incr()` is read-modify-write when the site has no persistent
   object cache, so concurrent requests can share a slot. The window is
   microseconds and the overshoot is bounded by concurrency, not by attacker
   choice. A lock per attempt would cost more than the attack it prevents.
2. **The site-wide lookup limit is a denial-of-service lever.** 100 attempts an
   hour across the whole store means an attacker can spend the budget and lock
   out real customers' lookups for the rest of the window. This is Phase 8's
   own number, and the alternative — no site-wide backstop — trades a
   self-healing hour-long outage for unbounded distributed enumeration.
3. **A matched pair can be used to mail the order's own address up to ten times
   an hour.** Bounded by the per-address limit and never redirectable, since the
   link goes to the address on the order. Mail-bombing a stranger requires
   already knowing their order number *and* their billing address.

---

## 3. Enforcement added at M15

| Control | Where | What it catches |
| --- | --- | --- |
| Six grep gates | `bin/security-gates.sh`, run by CI and `composer security:gates` | `__return_true` as a permission callback; an unprepared `$wpdb` call; `wp_set_auth_cookie`; `wc_create_refund` / `WC_Order_Refund`; `get_customer_id()` outside `OwnershipResolver`; `wp_remote_*` |
| `WordPress.Security` and `WordPress.DB` at error severity | `phpcs.xml.dist` | `NonceVerification`, `PreparedSQL`, `ValidatedSanitizedInput` and `DirectDatabaseQuery` were warnings, which a green build tolerates. Silencing one now takes a `phpcs:ignore` with a reason on the line |
| PHPStan level 7 | `phpstan.neon.dist` | Union-member access. Caught four real defects — see "Findings" |
| `ThreatModelTest` | `tests/unit/Security/` | The six prohibitions plus templates reading request input and logs carrying raw addresses, in the unit suite where a developer sees them in a second |

Each gate and each new test was verified adversarially: a violation was planted
for every one and the check was confirmed to fail before the plant was removed.
