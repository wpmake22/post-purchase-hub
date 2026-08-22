#!/usr/bin/env bash
#
# Post-Purchase Hub — security grep gates (docs/MILESTONE-PROMPTS.md M15, task 5).
#
# Six patterns that must never appear in shipped code. Each one is a class of
# mistake this plugin has already decided against in docs/SPEC.md Phase 8 or in
# CLAUDE.md's hard rules, and each is the kind of thing a reviewer reads past
# because it looks ordinary in isolation. A grep does not read past it.
#
# Deliberately greps rather than sniffs: these are architectural prohibitions,
# not style, and a grep is legible to someone who has never written a PHPCS
# sniff. Every gate prints the offending lines and fails the build.
#
# Run locally with `composer security:gates`.

set -uo pipefail

cd "$(dirname "$0")/.."

# Everything that ends up in a distribution zip. Tests are excluded on purpose:
# a test proving the plugin issues no refund has to be allowed to name
# wc_create_refund, and a gate that forbade it could not be tested at all.
#
# Filtered for existence rather than listed flat: CI moves pro/ aside to run
# the core-only suite, and a gate that reported "No such file" against a
# directory that is deliberately absent is a gate nobody trusts.
CANDIDATES=(src free pro templates post-purchase-hub.php uninstall.php)
PATHS=()

for candidate in "${CANDIDATES[@]}"; do
	if [ -e "$candidate" ]; then
		PATHS+=("$candidate")
	fi
done

if [ "${#PATHS[@]}" -eq 0 ]; then
	echo "::error::No shipped source found to check. Run this from the plugin root."
	exit 1
fi

FAIL=0

fail() {
	# GitHub renders ::error:: as an annotation; elsewhere it is just a prefix.
	echo "::error::$1"
	FAIL=1
}

# ---------------------------------------------------------------------------
# 1. permission_callback => __return_true
#
# CLAUDE.md hard rule 3. Matched across two lines because the array formatting
# WPCS enforces puts the callback on the same line, but a hand-wrapped
# `'permission_callback' =>\n\t'__return_true',` would slip a single-line grep.
# ---------------------------------------------------------------------------
if grep -rn -A1 --include='*.php' "permission_callback" "${PATHS[@]}" | grep -n "__return_true"; then
	fail "A REST route uses __return_true as its permission_callback. Every route needs a real one (CLAUDE.md hard rule 3)."
fi

# ---------------------------------------------------------------------------
# 2. $wpdb read/write without prepare()
#
# CLAUDE.md hard rule 6. Looks at the call and the two lines after it, because
# the prepared form is conventionally wrapped:
#
#     $wpdb->get_results(
#         $wpdb->prepare( $sql, ...$args ),
#
# A call that genuinely cannot be prepared — DDL, where the only interpolation
# is an identifier — is exempted by the `phpcs:ignore ...PreparedSQL...`
# annotation it already needs, so an exemption is a thing PHPCS and a reviewer
# both see rather than a second convention private to this script.
# ---------------------------------------------------------------------------
UNPREPARED=$(
	grep -rn -B1 -A2 --include='*.php' -E '\$wpdb->(query|get_results|get_var|get_col|get_row)\(' "${PATHS[@]}" \
		| awk -v RS='--\n' '!/prepare/ && !/phpcs:ignore.*PreparedSQL/ && NF'
)

if [ -n "$UNPREPARED" ]; then
	echo "$UNPREPARED"
	fail "A \$wpdb call runs without prepare(). Values are placeholders; identifiers come from a hardcoded whitelist (CLAUDE.md hard rule 6)."
fi

# ---------------------------------------------------------------------------
# 3. wp_set_auth_cookie
#
# docs/SPEC.md Phase 8: a signed token grants order-scoped read and action
# capability, never a WP session. Magic login is an account-takeover primitive
# and this plugin will not grow one by accident.
# ---------------------------------------------------------------------------
if grep -rn --include='*.php' "wp_set_auth_cookie" "${PATHS[@]}"; then
	fail "wp_set_auth_cookie() found. Signed links are order-scoped capability, never a login (docs/SPEC.md Phase 8)."
fi

# ---------------------------------------------------------------------------
# 4. Refund APIs
#
# CLAUDE.md hard rule 8. 1.0 executes no refunds: approving a cancellation
# moves the status and optionally restocks, and the merchant refunds through
# WooCommerce's own UI.
# ---------------------------------------------------------------------------
if grep -rnE --include='*.php' "wc_create_refund|WC_Order_Refund" "${PATHS[@]}"; then
	fail "A refund API is referenced. This plugin issues no refunds in 1.0 (CLAUDE.md hard rule 8)."
fi

# ---------------------------------------------------------------------------
# 5. Ownership comparison outside OwnershipResolver
#
# CLAUDE.md hard rule 2. Ownership is decided in exactly one place; a second
# comparison somewhere convenient is how an IDOR gets in.
# ---------------------------------------------------------------------------
if grep -rn --include='*.php' "get_customer_id" "${PATHS[@]}" | grep -v 'src/Security/OwnershipResolver.php:'; then
	fail "get_customer_id() referenced outside OwnershipResolver. Ownership is decided in exactly one place (CLAUDE.md hard rule 2)."
fi

# ---------------------------------------------------------------------------
# 6. Outbound HTTP
#
# CLAUDE.md hard rule 7 and docs/SPEC.md Phase 8's SSRF row. No telemetry, no
# update pings, no remote assets — which is also what makes the WP.org security
# review of this plugin a short conversation.
# ---------------------------------------------------------------------------
if grep -rn --include='*.php' "wp_remote_" "${PATHS[@]}"; then
	fail "An outbound HTTP call was found. This plugin makes none (CLAUDE.md hard rule 7)."
fi

if [ "$FAIL" -eq 0 ]; then
	echo "Security gates: all six clear."
fi

exit "$FAIL"
