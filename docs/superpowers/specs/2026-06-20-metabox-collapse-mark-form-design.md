# Collapse the order-metabox "mark client" form behind one button

**Date:** 2026-06-20
**Status:** Approved (design)

## Problem

On every WooCommerce order-edit screen, the RiskyBuyer side metabox immediately
renders the full "mark this client" form for a not-yet-listed client: an intro
sentence, the `Причина` (reason) dropdown, the `Бележка` (note) textarea, and the
`Маркирай клиента` button. This is visual noise on the vast majority of orders,
where the merchant never needs to flag anyone.

## Goal

Default the metabox body to a single primary call-to-action button. Reveal the
existing form inline only when the merchant chooses to act. Reduce clutter
without changing the marking flow itself.

## Scope

Only the "can add, client not yet listed" branch of the metabox. The
blacklisted-client view, the order-edit banner (`order_notice`), the
no-permission message, and the AJAX mark/unmark flow are all unchanged.

## Design (Approach A — server renders both, JS toggles)

The server renders both the reveal button and the form. The form starts hidden;
a small JS handler swaps them. This mirrors the existing Add-tab reveal pattern
(`.rb-toggle` / `.rb-collapse`), needs no AJAX, and keeps all strings in PHP.

### Behavior

- **Default view** (client not yet listed, user has `can_add()`): the metabox
  body shows only one primary button — **`Маркирай като проблемен`**.
- **On click:** the button hides, the form (intro line · `Причина` dropdown ·
  `Бележка` textarea · `Маркирай клиента` button) appears inline, and the reason
  select receives focus. From there it behaves exactly as today.
- **Unchanged:** blacklisted-client view, order-edit banner, no-permission
  message.

### Rejected alternatives

- **B — JS builds the form on click.** Duplicates the form markup and its
  translatable strings in JavaScript. More code, easy to drift.
- **C — Lazy-load the form over AJAX.** A whole request and server endpoint for a
  three-field form. Overkill.

## Implementation touch points

1. **`includes/admin/class-riskybuyer-order-metabox.php`** — in the
   `$bl->can_add()` branch of `render_box()`: emit the reveal button
   (`<button type="button" class="button button-primary rb-reveal-mark">`),
   then wrap today's form markup (intro + reason + note + mark button) in a
   hidden container `<div class="rb-mark-form" hidden>`.
2. **`assets/admin.js`** — new handler: on `.rb-reveal-mark` click, hide the
   button, unhide the sibling `.rb-mark-form`, and focus `.rb-reason`.
3. **`assets/admin.css`** — make `.rb-metabox .rb-reveal-mark` full-width so the
   button reads as a clear CTA in the narrow sidebar.
4. **`languages/risky-buyer-bg_BG.po` + `languages/risky-buyer.pot`** — add the
   string `Mark as problematic` → `Маркирай като проблемен`, then recompile
   `risky-buyer-bg_BG.mo`.

## Success criteria

- A not-yet-listed client's order shows only the `Маркирай като проблемен` button
  in the metabox body by default.
- Clicking it reveals the reason/note/confirm form inline with the reason select
  focused; marking a client still works end to end.
- Blacklisted view, banner, and no-permission view look and behave exactly as
  before.
- The new button text appears translated in Bulgarian (recompiled `.mo`).
