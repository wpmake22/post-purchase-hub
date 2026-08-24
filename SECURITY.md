# Security Policy

Post-Purchase Hub for WooCommerce exposes customer order data and accepts
customer-submitted requests, so security reports are treated as the highest
priority class of issue we handle.

## Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | ✅ |
| < 1.0 | ❌ (pre-release) |

## Reporting a vulnerability

Please report privately — do not open a public GitHub issue, and do not post
details in the WordPress.org support forum.

- Email: security@wpmake.net
- Include: affected version, WordPress and WooCommerce versions, whether HPOS is
  enabled, reproduction steps, and the impact you were able to demonstrate.
- Please do not test against stores you do not own.

We aim to acknowledge within 3 working days and to ship a fix or a mitigation
plan within 30 days for anything exploitable by an unauthenticated visitor.

## Scope we care about most

- Access to another customer's order data (IDOR) through any route, shortcode,
  block or template path.
- Guest lookup: order-number enumeration, or any observable difference between
  an existing and a non-existing order.
- Signed link tokens: tampering, truncation, cross-order replay, expiry bypass.
- Missing capability or nonce checks on admin handlers.
- SQL injection through any filter, sort, search or REST argument.
- Stored XSS via customer-supplied reasons and notes, in admin, on the front end
  or in either email format.

## Out of scope

- Findings that require an administrator or shop-manager account to exploit.
- Vulnerabilities in WordPress, WooCommerce or third-party plugins — please
  report those to their maintainers.
- Missing hardening headers on pages this plugin does not render.
- Automated scanner output without a demonstrated impact.

## Disclosure

We will credit reporters in the changelog unless anonymity is requested, and we
ask for coordinated disclosure until a patched release is available on
WordPress.org.
