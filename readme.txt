=== Post-Purchase Hub for WooCommerce ===
Contributors: wpmake
Tags: woocommerce, orders, order tracking, order status, cancel order
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show customers where their order is, and let them act on it — one order page instead of five plugins.

== Description ==

Post-Purchase Hub gives every WooCommerce order a clear progress timeline on the
customer's own account pages, and gives the merchant one place to see what
customers are asking for.

The timeline is built from status changes the plugin records as they happen, so
the dates it shows are real. Where a date is not known, it says so rather than
guessing.

= What it does =

* A stage-by-stage order timeline on My Account, added to your existing
  templates rather than replacing them.
* Stages that adapt to the statuses your store actually uses.
* A shortcode `[pph_orders]` and an "Order timeline" block for placing the same
  view on any page.
* A WP-CLI command for filling in history on orders placed before installation.

= What it does not do =

Being plain about the edges matters more than a longer feature list:

* It does not issue refunds, and never touches a payment gateway.
* It does not create tracking numbers. It reads what your tracking plugin
  already stores; with no tracking plugin, there is nothing to show.
* It does not replace your theme's order pages unless you turn that on, and it
  refuses to turn on when your theme already customises those templates.
* It makes no outbound network requests of any kind, and collects no analytics.

== Installation ==

1. Upload the plugin to `wp-content/plugins/`, or install it through Plugins →
   Add New.
2. Activate it. Nothing on your storefront changes until you complete setup.
3. Go to WooCommerce → Post-Purchase to review the stages your store uses.

== Frequently Asked Questions ==

= Will this break my theme's My Account page? =

It is designed not to. The default mode adds sections through WooCommerce's own
hooks and leaves your templates alone. Full template replacement is opt-in, and
the plugin checks first whether your theme has customised those templates — if
it has, replacement stays off.

= Why does an old order have no dates on its timeline? =

WooCommerce does not keep a history of status changes, so a plugin can only
record them from the moment it is installed. Older orders show their stages
without dates rather than showing invented ones. `wp pph backfill-timeline`
fills in what can be derived from the dates WooCommerce does store.

= Does it work with High-Performance Order Storage? =

Yes. All order data is read and written through WooCommerce's CRUD layer, and
the test suite runs against both storage engines.

== Screenshots ==

1. Every order gets a progress timeline on the customer's own account page, with an estimated delivery date wherever you have set shipping times.
2. The My Account order list gains a Progress column, and shows only the actions that actually apply to each order.
3. Customers ask to cancel and you approve or decline. Nothing is cancelled automatically, and the plugin never issues a refund.
4. The request queue: every cancellation request waiting on a decision, with the order it belongs to and the reason the customer gave.
5. A setup wizard that asks what you need and skips what you do not. Nothing reaches your storefront until you finish it.
6. The settings screen, with a status panel that says plainly whether everything is working.
7. Map your own order statuses onto the stages customers see, and set handling and transit times per shipping method.
8. Choose what customers can do for themselves, and the rules around cancellation requests.
9. Guest order lookup: customers without an account reach their order through a secure link emailed to the address already on it.

== Upgrade Notice ==

= 1.0.0 =
First public release.

== Changelog ==

= 1.0.0 =
* First public release.
* Order progress timeline on My Account and single order pages, built from status changes recorded as they happen.
* Timeline stages mapped from the order statuses your store actually uses, with any status hideable from customers.
* Delivery estimates from your handling time and per-shipping-method transit times, excluding your non-working days and holidays.
* Real tracking data replaces the estimate automatically wherever a supported tracking plugin provides it.
* Self-service actions on the customer's order page: request cancellation, buy the order again, view an invoice, and get help.
* Cancellation requests arrive in a merchant queue to approve or decline, with optional restocking on approval.
* Guest order lookup, off by default, sending a signed link to the billing address already on the order.
* Setup wizard that keeps the storefront unchanged until it is finished.
* `[pph_orders]` shortcode and an Order timeline block.
* `wp pph backfill-timeline` for orders placed before installation.
* Works with both High-Performance Order Storage and legacy post storage.
