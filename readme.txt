=== RiskyBuyer ===
Contributors: dangoriaynov
Tags: woocommerce, orders, customers, blacklist, fraud
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flag problematic WooCommerce customers by phone or name and automatically mark their orders in the admin.

== Description ==

RiskyBuyer lets your team keep a shared list of problematic customers
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

1. Upload the `risky-buyer` folder to `/wp-content/plugins/`, or install it
   from the Plugins screen.
2. Activate the plugin (this creates the database table).
3. Manage the list under **WooCommerce → Risky buyers**.

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

1. Check tab — look up a buyer by phone and/or name (exact + possible matches).
2. List tab — the shared blacklist with phone/name search and an AND/OR toggle.
3. Add tab — add one buyer or paste many at once.
4. Settings — opt-in synchronization with the central list.
5. Order screen — a flagged risky buyer (banner + list badge).

== External services ==

This plugin can optionally synchronize with a central, shared blacklist service
at **riskybuyer.com** so that your checks are extended with phone numbers
reported by other sites. **This is opt-in and disabled by default** — no data is
sent or received until you enable it under WooCommerce → Risky buyers →
Settings.

When sync is enabled:

* The plugin periodically requests the shared list (GET) to extend your local
  checks. Reading the list is open.
* If you have a write API key, the plugin sends your own entries to the service
  to be shared: phone, name, reason, note, and your site's domain.

The service is provided by the plugin author. Terms of Use:
https://riskybuyer.com/terms — Privacy Policy: https://riskybuyer.com/privacy

== Changelog ==

= 0.6.3 =
* Phone numbers are normalized on save to international +CC form (00 -> +, national leading 0 -> country code; default +359, filterable).
* Order banner now links to the buyer's other orders on this site (excluding the one open).
* List: centered cell values; Edit/Delete shown as icons; reason filter and reason dropdowns color-coded to match the list.
* Settings: clearer wording for the sync status; the "data sent" note moved under the sync panel; constrained width.

= 0.6.2 =
* Admin: removed the redundant Check tab — the List tab now filters instantly in the browser (phone/name with AND/OR + reason, no reload).
* Admin: cleaner Add/Edit forms and centered, bold table headers.

= 0.6.1 =
* Passes Plugin Check with zero errors and warnings (security/SQL/i18n).

= 0.6.0 =
* Renamed to RiskyBuyer (slug, text domain, identifiers) to match the project and site; data is migrated automatically on update.
* Tested up to WordPress 7.0.

= 0.5.0 =
* Settings tab is now AJAX: enabling sync reveals the URL/key fields and saves automatically (no reload, no Save button). The API key is validated against the server; the "Push my list" button appears only for a valid write key.

= 0.4.2 =
* AND/OR toggle added to the Check tab search too (not only the List tab).

= 0.4.1 =
* Prominent banner on the order screen when the customer is on the problem list.
* List tab: separate phone/name search (LIKE) with an AND/OR toggle (AND by default).
* Daily sync moved to ~03:00 site time.

= 0.4.0 =
* Optional synchronization with the central shared blacklist (riskybuyer.com), opt-in and off by default.
* Settings tab: enable sync, server URL, API key, Sync now, Push my list.
* Checks are extended with cached server phone numbers; local entries always stay local.

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

= 0.4.0 =
Adds optional sync with a shared central blacklist (opt-in, off by default).

= 0.3.0 =
Translatable strings (with Bulgarian translation), partial check search, and a unique plugin prefix.

= 0.2.0 =
Adds a customer check tab and bulk add; moves the page under WooCommerce.
