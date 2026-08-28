=== WPMake Post-Purchase Hub for WooCommerce ===
Contributors: wpmakedev
Tags: woocommerce, orders, order tracking, order status, cancel order
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give every WooCommerce order a progress timeline, delivery estimate, and self-service cancel, reorder and help — on your own order pages.

== Description ==

Every WooCommerce store gets the same email, over and over: **where is my
order?** The customer already has the answer in their account — an order status
like "processing" — but that word means nothing to them. So they write to you,
and you answer by hand.

WPMake Post-Purchase Hub turns that status into something a customer can actually read:
a stage-by-stage timeline on the order page they already visit, with a delivery
estimate where you have told it your shipping times. Then it gives them the few
things they would otherwise have emailed you about — cancelling, reordering,
finding an invoice, asking a question — as buttons that route into a queue you
control.

It adds all of this to the pages your theme already draws. It does not take them
over unless you ask it to.

= Features =

**Order timeline**

* **Stage-by-stage progress** on the single order page and in My Account, built
  from status changes recorded as they happen — so the dates shown are real.
* **A Progress column** on the My Account order list, so a customer with several
  open orders sees all of them at a glance.
* **Stages mapped from your own statuses** — the plugin reads the statuses your
  store actually uses, including custom ones added by other plugins.
* **Internal statuses stay internal.** Any status can be set to "not shown to
  customers" and contributes nothing to the timeline.
* **Honest blanks.** Where a date is not known, it says so rather than inventing
  one.

**Delivery estimates**

* **Handling time** in business days, set once for the store and overridable per
  shipping method.
* **Transit time as a range** per shipping method, so customers see "arrives
  between the 26th and the 28th" rather than a single date that will sometimes be
  wrong.
* **Your non-working days and holidays** are excluded from every calculation.
* **Real tracking wins.** As soon as a tracking plugin supplies a real tracking
  number, the estimate steps aside automatically. Works with **Advanced Shipment
  Tracking** and **WooCommerce Shipment Tracking** out of the box, and any other
  source through a filter.

**Customer self-service**

* **Request cancellation** — the customer gives a reason and an optional note.
  It becomes a request you approve or decline.
* **Buy these again** — re-adds a past order to the cart, after a screen showing
  what changed: what is out of stock, what no longer exists, what costs more.
* **Invoice** — links to the invoice your invoice plugin already generated.
  Supports **PDF Invoices & Packing Slips**, and falls back to the printable
  order page when no invoice plugin is present.
* **Get help with this order** — a message form that reaches you with the order
  number, status and items already attached.
* Each action can be switched off, and switching one off removes it from the page
  **and** refuses it at the API.

**Merchant request queue**

* **One queue** under WooCommerce, with a count in the menu of everything waiting
  on you.
* **Approve or decline** with an internal note the customer never sees.
* **Optional restocking** when you approve a cancellation.
* **Rules you set** — which statuses are cancellable, how many times one order
  may be the subject of a request, how long a customer waits before asking again,
  and the response time you promise them.
* **Requests show on the order screen** too, so you are never surprised by one
  you did not know about.

**Guest order lookup**

* **For customers who checked out without an account**, off until you switch it
  on deliberately.
* **Nothing is revealed by guessing.** The form emails a signed link to the
  address already on the order — never to the address typed into the form.
* **Rate limited**, with a link lifetime you control.

**Setup and configuration**

* **A setup wizard** that asks what you need and skips what you do not. Nothing
  reaches your storefront until you finish it — activating the plugin changes
  nothing on its own.
* **A settings screen organised into cards**, with a search box that filters as
  you type.
* **A status panel** that says plainly whether everything is working, and what to
  do when it is not.

**Placement for developers and site builders**

* **Shortcode** — `[pph_orders]` renders the timeline view on any page.
* **Shortcode** — `[pph_order_lookup]` renders the guest lookup form.
* **Block** — an Order timeline block for the block editor.
* **WP-CLI** — `wp pph backfill-timeline` fills in what can be derived for
  orders placed before you installed the plugin.
* **Filters throughout**, including the stage map, action availability, tracking
  detection and invoice sources.
* **High-Performance Order Storage and legacy post storage** both supported, and
  the test suite runs against both.

= What this plugin deliberately does not do =

Being plain about the edges matters more than a longer feature list.

* **It never issues a refund**, and never touches a payment gateway. Approving a
  cancellation changes the order status and optionally restocks; refunding stays
  in WooCommerce, one click away on the order itself.
* **It never cancels an order by itself.** A cancellation is always a request a
  human approves.
* **It does not create tracking numbers.** It reads what your tracking plugin
  already stores. With no tracking plugin there is nothing to show, so it shows a
  delivery estimate instead and says so.
* **It generates no PDFs.** The invoice action links to what another plugin
  produced.
* **It does not replace your theme's order pages** unless you turn that on — and
  it refuses to turn on while your theme already customises those templates,
  rather than producing a broken page.

= No outbound requests, no tracking, no data sharing =

This plugin makes **no outbound HTTP requests of any kind**. No telemetry, no
update pings, no remote fonts or images, no analytics. Nothing about your store
or your customers leaves your server, because nothing is ever sent anywhere.

Order data is read and written only through WooCommerce's own CRUD layer, and the
plugin never writes to another plugin's data.

= Source code and build tools =

The plugin is GPL, and everything it ships can be read and rebuilt. The zip
contains the unminified source of every compiled asset alongside the compiled
files themselves:

* `assets/src/` — the JavaScript and SCSS sources for every bundle in
  `assets/build/`, including the setup wizard's React app.
* `webpack.config.js` and `package.json` — the build configuration and the
  script that runs it.

To rebuild the compiled assets from the sources in the zip:

`npm install && npm run build`

The build uses [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts),
WordPress's own webpack configuration, and no other bundler. Nothing is
obfuscated, and no compiled file has a source that is not in the zip.

Development happens at https://github.com/wpmake22/post-purchase-hub, where the
PHP source, the test suites and the release script live as well.

Install it, spend two minutes in the wizard, and stop answering the same email.

== Installation ==

**From your WordPress dashboard**

1. Go to **Plugins → Add New** and search for "WPMake Post-Purchase Hub".
2. Click **Install Now**, then **Activate**.

**Uploading the zip**

1. Go to **Plugins → Add New → Upload Plugin** and choose the zip file.
2. Click **Install Now**, then **Activate**.

**After activating**

WooCommerce must be active first — the plugin will not activate without it.

Activating changes nothing on your storefront. A notice appears pointing at the
setup wizard, and until you finish it your customers see exactly what they saw
before.

1. Click **Run the setup wizard**, or go to **WooCommerce → Post-Purchase Hub
   → Settings** and start it from there.
2. Choose what you want to set up first. Every question can be skipped, and a
   skipped question keeps a sensible default you can change later.
3. Map your order statuses onto the stages customers will see.
4. Set your handling time, and the transit time of each shipping method if you
   want delivery estimates.
5. Choose which self-service actions customers get.
6. Choose whether the plugin adds to your order pages or replaces them. Additive
   is the safe default.
7. Press **Finish and go live**. That is the moment your customers start seeing
   their timelines.

**Optional**

* To offer guest order lookup, enable it under **Settings → Guest Access**, then
  add `[pph_order_lookup]` to a page and link it from your footer or your order
  emails.
* To place the timeline somewhere of your own, use the `[pph_orders]` shortcode
  or the Order timeline block.

== Frequently Asked Questions ==

= Will this break my theme's My Account page? =

It is designed not to. The default mode adds sections through WooCommerce's own
hooks and leaves your templates alone. Full template replacement is opt-in, and
the plugin checks first whether your theme has customised those templates — if it
has, replacement stays off rather than producing a broken page.

= Do I need a tracking plugin for this to be useful? =

No. Without one, customers see a delivery estimate worked out from your handling
and transit times, which is the honest answer rather than a fabricated tracking
number. If you do have one, its real tracking data replaces the estimate
automatically and you do not have to do anything.

= Why is no delivery estimate showing? =

Almost always because the shipping method on that order has no transit time set.
A method with no transit time configured shows no estimate at all — a deliberate
blank rather than a guess. Set it under **Settings → Timeline → Delivery
estimates**.

= Why does an old order have no dates on its timeline? =

WooCommerce does not keep a history of status changes, so a plugin can only
record them from the moment it is installed. Older orders show their stages
without dates rather than showing invented ones. `wp pph backfill-timeline` fills
in what can be derived from the dates WooCommerce does store.

= Does it cancel orders automatically? =

No, and it never will. A customer's cancellation request lands in a queue and
nothing happens to the order until you approve it. If you decline, the order is
left exactly as it was.

= Does it issue refunds? =

No. Approving a cancellation changes the order status and optionally returns the
items to stock. Refunding is done in WooCommerce, on the order itself. This
plugin never touches a payment gateway.

= Can customers without an account see their order? =

Only if you enable guest order lookup, which is off by default. When it is on, a
customer enters their order number and billing email and the plugin emails a
signed link to the address **already on the order** — never to the address typed
into the form. Guessing an order number therefore reveals nothing.

= Why can one of my customers not request a cancellation? =

The button is hidden, correctly, when the order is not in one of your cancellable
statuses, when a request is already pending on it, when the order has hit its
per-order request limit or is inside the waiting period, or when you have switched
the action off.

= Where do I change the emails this plugin sends? =

With all your other WooCommerce emails, at **WooCommerce → Settings → Emails**.
They are ordinary WooCommerce emails, so they inherit the header, footer and
colours you have already set, and there is one place to change them rather than
two.

= Does it work with High-Performance Order Storage? =

Yes. All order data is read and written through WooCommerce's CRUD layer, and the
test suite runs against both HPOS and legacy post storage.

= Can I show the timeline somewhere other than the order page? =

Yes. Use the `[pph_orders]` shortcode or the Order timeline block on any page.
Each signed-in customer sees their own orders.

= What happens to my data if I delete the plugin? =

Nothing is deleted unless you ask for it. Deleting the plugin leaves your settings
and request history in place, so reinstalling picks up where you left off. If you
would rather it cleaned up after itself, switch on **Delete all data when
uninstalling** under **Settings → Advanced** first.

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
