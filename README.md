# Problem Client

WordPress/WooCommerce plugin that maintains a list of **problematic clients**
(by phone and/or name, with a reason and note) and automatically flags their
orders in the admin orders list. Built with a **storage-provider** abstraction
so the list can later move to a **shared central service** used by many sites.

Interface language: Bulgarian (the shop's audience).

## Status

`v0.1.0` — early. Data is stored locally. For now changes are uploaded to the
site **manually**; formal activation in WordPress comes later, once polished.

## Features

- Blacklist clients by **phone or name** + reason (`uncollected` / `fake` /
  `abusive` / `other`) + free note.
- Orders of blacklisted clients are flagged in the orders list (warning badge +
  colored bar) — works **across pagination** (computed server-side).
- Order edit screen: a warning panel when the client is blacklisted, or a
  "mark client" form otherwise.
- Admin page **"Проблемни клиенти"** with tabs:
  - **Проверка** — check a caller by phone and/or name → exact match + possible
    (partial) matches for manual verification.
  - **Списък** — search/filter; edit/delete (admins only).
  - **Добавяне** — add one client, or **bulk** (one per line, batch reason/note;
    existing entries skipped).

## Permissions

| Action | Capability |
|--------|------------|
| Add | `edit_shop_orders` |
| Edit / delete | `manage_options` |

## Structure

```
problem-client.php                      Bootstrap, constants, activation hook
includes/
  class-pc-plugin.php                   Wiring + shared admin assets
  class-pc-blacklist.php                Service: normalize, perms, CRUD, matching
  class-pc-matcher.php                  Match current-view orders -> map
  class-pc-ajax.php                     AJAX: mark / unmark from an order
  storage/
    interface-pc-storage-provider.php   Storage contract
    class-pc-local-table-provider.php   Local DB table implementation
  admin/
    class-pc-orders-list.php            Orders list flagging
    class-pc-order-metabox.php          Order edit panel + mark form
    class-pc-admin-page.php             Management page
assets/admin.css, assets/admin.js
docs/PLAN.md                            Plan & spec (incl. future central sync)
```

## Install (manual, for now)

1. Copy this folder to `wp-content/plugins/problem-client/` on the site.
2. Activate **Problem Client** in WP Admin → Plugins (creates the DB table).
   - The table is also created/upgraded on demand on any admin request.
3. Manage the list under **WP Admin → Проблемни клиенти**.

Requires WooCommerce. Works with HPOS (the new `wc-orders` screen) and the
legacy orders screen.

## Future

A `Probclient_Remote_Api_Provider` will talk to a central service so multiple sites share
one list (add by anyone, edit/delete by resource admins). See `docs/PLAN.md`.
