# Risky Buyer

WordPress/WooCommerce plugin that maintains a list of **problematic clients**
(by phone and/or name, with a reason and note) and automatically flags their
orders in the admin. Built on a **storage-provider** abstraction so the list
can optionally sync with a **shared central service** (riskybuyer.com) used by
many sites.

Interface language: Bulgarian (the shop's audience).

## Status

`v1.0.1`. The list is stored locally; central sync is optional (Settings tab).
Releases are currently uploaded to the site **manually** (see Deploy). Not yet
published in the WordPress.org directory.

## Features

- Blacklist clients by **phone or name** + reason (`uncollected` / `fake` /
  `abusive` / `other`) + free note.
- Orders of blacklisted clients are flagged in the orders list (warning badge +
  colored bar) — works **across pagination** (computed server-side).
- Order edit screen: a warning banner + panel when the client is blacklisted;
  otherwise a single **"Mark as problematic"** button that reveals the
  reason/note form on click.
- Admin page **"Проблемни клиенти"** with tabs:
  - **Списък** — search/filter; edit/delete (admins only); autocomplete of
    customers from existing orders.
  - **Добавяне** — add one client, or **bulk** (one per line, batch reason/note;
    existing entries skipped).
  - **Настройки** — optional central sync: server URL + API key (validate-once),
    manual sync/push.

## Permissions

| Action | Capability |
|--------|------------|
| Add | `edit_shop_orders` |
| Edit / delete / manage | `manage_options` |

## Structure

```
risky-buyer.php                              Bootstrap, constants, activation hook
includes/
  class-riskybuyer-plugin.php                Wiring + shared admin assets
  class-riskybuyer-blacklist.php             Service: normalize, perms, CRUD, matching, reasons
  class-riskybuyer-matcher.php               Match current-view orders -> map
  class-riskybuyer-ajax.php                  AJAX: mark / unmark, settings, sync, search
  class-riskybuyer-settings.php              Settings storage
  class-riskybuyer-remote-sync.php           Optional sync with the central service
  storage/
    interface-riskybuyer-storage-provider.php  Storage contract
    class-riskybuyer-local-table-provider.php   Local DB table implementation
  admin/
    class-riskybuyer-orders-list.php         Orders list flagging
    class-riskybuyer-order-metabox.php       Order edit panel + mark form
    class-riskybuyer-admin-page.php          Management page (List / Add / Settings)
assets/admin.css, assets/admin.js
languages/                                   .pot + bg_BG .po/.mo
server/                                      Central FastAPI service (separate deploy)
docs/PLAN.md, docs/CENTRAL-SYNC-PLAN.md      Plans & specs
```

## Install (manual)

1. Copy the plugin folder to `wp-content/plugins/risky-buyer/` on the site.
2. Activate **Risky Buyer** in WP Admin → Plugins (creates the DB tables).
   - Tables are also created/upgraded on demand on any admin request.
3. Manage the list under **WP Admin → Проблемни клиенти**.

Requires WooCommerce. Works with HPOS (the new `wc-orders` screen) and the
legacy orders screen.

## Build & deploy

- **Build the distributable zip** (`risky-buyer.zip`, gitignored) from a clean
  copy of the plugin files minus the `.distignore` exclusions
  (`server/`, `docs/`, `tests/`, `README.md`, dotfiles), packed under a
  top-level `risky-buyer/` folder.
- **Release:** bump the version in `risky-buyer.php` (header `Version:` and the
  `RISKYBUYER_VERSION` constant) and `readme.txt` (`Stable tag` + a `Changelog`
  entry), then rebuild the zip.
- **Deploy to the live site:** rsync the built plugin folder into
  `wp-content/plugins/risky-buyer/` and restore file ownership to the site user.
  (Folder name and main file are unchanged across updates, so the plugin stays
  active.) The central FastAPI service under `server/` is deployed separately —
  see `server/README.md`.

## Central sync

`Riskybuyer_Remote_Sync` talks to the central service (riskybuyer.com) so
multiple sites can share one list: open read for everyone, authenticated write
(`Authorization: Bearer <key>`, scoped per domain). See `docs/CENTRAL-SYNC-PLAN.md`
and `server/README.md`.
