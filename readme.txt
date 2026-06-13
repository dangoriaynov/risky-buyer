=== Problem Client ===
Contributors: dangoriaynov
Tags: woocommerce, orders, customers, blacklist, fraud
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flag problematic WooCommerce customers by phone or name and automatically mark their orders in the admin.

== Description ==

Problem Client lets your team keep a shared list of problematic customers
(uncollected COD shipments, fake orders, abusive behaviour, etc.) and flags
their orders right in the WooCommerce orders list.

Each entry is a phone and/or a name, with a reason and an optional note. When a
customer's order matches an entry (by normalized phone OR name), the order row
is highlighted with a warning badge — and you get a clear warning panel on the
order edit screen.

= Features =

* **Check tab** — look up a caller by phone and/or name. Shows an exact match
  (definitive) plus possible (partial) matches for manual verification.
* **Orders list flagging** — a warning badge and colored bar on orders of
  blacklisted customers. Computed server-side, so it works across pagination.
  Supports both HPOS (the new orders screen) and the legacy screen.
* **Order edit panel** — a warning when the customer is blacklisted, or a
  "mark client" form otherwise.
* **Add one or in bulk** — add a single client, or paste a list (one per line).
* **Roles** — the team can add (capability `edit_shop_orders`); only
  administrators (`manage_options`) can edit or delete.

= Architecture =

The plugin talks to its data through a storage-provider interface. Today it uses
a local database table; the design is ready for a future shared/central service
so multiple sites can contribute to and read the same list.

== Installation ==

1. Upload the `problem-client` folder to `/wp-content/plugins/`, or install it
   from the Plugins screen.
2. Activate the plugin (this creates the database table).
3. Manage the list under **WooCommerce → Проблемни клиенти**.

Requires WooCommerce.

== Frequently Asked Questions ==

= How are duplicate / matching customers detected? =

Phones are normalized to their last 9 digits and names are lowercased with
punctuation removed, so the same person matches across formatting differences.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. The plugin declares HPOS compatibility and reads orders from the current
status view server-side.

= Who can add or remove entries? =

Anyone who can edit orders (`edit_shop_orders`) can add. Only administrators
(`manage_options`) can edit or delete.

== Screenshots ==

1. Check tab — look up a customer by phone and/or name.
2. Orders list with a flagged problematic customer.
3. Order edit screen warning panel.
4. Bulk add.

== Changelog ==

= 0.3.0 =
* Internationalized: English source strings with full translation support; ships a Bulgarian (bg_BG) translation.
* Unique plugin prefix across classes, options, table, AJAX actions and hooks.
* Check tab now does partial (LIKE) matching on phone or name, newest first.
* Admin page moved under the WooCommerce menu (just before Settings).
* Declared HPOS compatibility; added uninstall cleanup and GPL license.

= 0.2.0 =
* Admin page reorganised into tabs: Check / List / Add.
* Check tab: exact match plus possible (partial) matches.
* Bulk add (one client per line; batch reason/note; existing entries skipped).
* Moved the admin page under the WooCommerce menu.
* Declared HPOS compatibility; load text domain.

= 0.1.0 =
* Initial release: storage-provider architecture, local table, matching by
  phone or name, orders-list flagging, order metabox, management page.

== Upgrade Notice ==

= 0.3.0 =
Translatable strings (with Bulgarian translation), partial check search, and a unique plugin prefix.

= 0.2.0 =
Adds a customer check tab and bulk add; moves the page under WooCommerce.
