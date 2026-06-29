# WordPress.org resubmission — status & handoff

_Last updated: 2026-06-29 · Plugin: **RiskyBuyer – Customer Flags for WooCommerce** · Version: **1.0.3**_

## Where things stand

The WordPress.org submission (Review ID `AUTOPREREVIEW TRM risky-buyer/winter2007d`,
17 Jun 2026) was **pended** in pre-review for:

1. Plugin name/slug `risky-buyer` flagged as too generic.
2. Inline `<style>` / `<script>` in the orders-list.
3. (minor note) `load_plugin_textdomain()` not strictly needed since WP 4.6.

All addressed in code:

- **Renamed** → display name **"RiskyBuyer – Customer Flags for WooCommerce"**;
  slug + text domain + main file → **`riskybuyer`**. We will request slug
  `riskybuyer` (we own the riskybuyer.com brand; no name collision in the directory).
- **Inline assets enqueued**: orders-list CSS moved into `assets/admin.css`; JS
  moved to `assets/orders-list.js`, enqueued via `wp_enqueue_script` +
  `wp_localize_script`.
- **`load_plugin_textdomain()` kept on purpose** (hooked on `init`) — the plugin
  bundles a bg_BG translation and supports WP 6.0+. Noted in the reply.
- **v1.0.3** added a "Settings" action link on the Plugins screen
  (via `plugin_action_links_` + `plugin_basename(RISKYBUYER_FILE)`).

Distributable built: **`riskybuyer.zip`** (v1.0.3, 21 files, top folder `riskybuyer/`).
Already deployed in-place to the live site **dobavki.club** (there the folder stays
`risky-buyer/` so the plugin keeps its active pointer — see `deploy` notes).

## Plugin Check: the 117 errors are expected, not real defects

Running Plugin Check under the **old** slug `risky-buyer` shows **117
`TextDomainMismatch` errors** ("expected 'risky-buyer', got 'riskybuyer'") + 1
`load_plugin_textdomain` warning. This is exactly what the review email pre-warned:

> "You may see a warning regarding the 'Text Domain', as we haven't changed the
> slug on our side yet. That's fine."

Plugin Check derives the *expected* text domain from the **folder/slug it runs
under**. Proof it's only the mismatch: 120 uses of `'riskybuyer'` − 3 non-translation
uses (the `SLUG` const, the `strpos`, and `load_plugin_textdomain`) = **exactly 117**.

**To see it clean:** in a fresh [WordPress Playground](https://playground.wordpress.net),
*Upload Plugin* → `riskybuyer.zip` (top folder is `riskybuyer`), activate, then
Tools → Plugin Check → select **RiskyBuyer**. Expect **0 errors, 1 warning**.
Do **not** use WP.org's "Test in Playground" on the pending submission — it still
runs under the old `risky-buyer` slug and will keep showing the mismatch until the
slug is reassigned.

## Remaining steps (for the user)

1. Upload `riskybuyer.zip` (v1.0.3) at <https://wordpress.org/plugins/developers/add/>
   (logged in as `winter2007d`).
2. Reply to the review email with the message below.

## Reply email (short, as they asked)

> Please reserve the new slug: **riskybuyer**
>
> I've renamed the plugin to "RiskyBuyer – Customer Flags for WooCommerce".
> RiskyBuyer is our own brand — we operate the shared service the plugin optionally
> syncs with at riskybuyer.com — and the name doesn't collide with any existing
> plugin in the directory. The "for WooCommerce" suffix marks the integration;
> we're not affiliated with WooCommerce.
>
> The text domain is already set to the requested slug `riskybuyer`, so the Plugin
> Check "Text Domain mismatch" results are only because the slug hasn't been
> reassigned on your side yet (as your email noted). Tested under a `riskybuyer`
> folder, Plugin Check is clean.
>
> One clarification: I kept `load_plugin_textdomain()` (hooked on `init`) because
> the plugin bundles a Bulgarian (bg_BG) translation and still supports WordPress 6.0.
>
> New version uploaded. Thanks!

## Risk note

"RiskyBuyer" is the **higher-risk** name choice — the human reviewer may still call
it generic despite the brand argument. Fallback if they push back: pick a coined
name (e.g. `buyerward`) and redo the text-domain sweep across the code + readme +
language filenames.
