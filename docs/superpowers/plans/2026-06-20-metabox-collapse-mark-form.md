# Collapse Order-Metabox Mark Form Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Default the order-metabox body to a single "Маркирай като проблемен" button that reveals the existing mark form inline on click.

**Architecture:** Server renders both the reveal button and the form; the form starts hidden via inline `display:none`, and a small jQuery handler in `assets/admin.js` reveals it on click — mirroring the existing Add-tab `.rb-toggle` reveal idiom. No AJAX, no new endpoints; all strings stay in PHP.

**Tech Stack:** PHP (WordPress/WooCommerce admin), jQuery, WP admin CSS, GNU gettext (`msgfmt`).

## Global Constraints

- Text domain for all `__()`/`esc_html__()` calls: `risky-buyer` (copied verbatim).
- New collapsed-button copy in Bulgarian: `Маркирай като проблемен` (exact).
- Source string (msgid) for that button: `Mark as problematic` (exact).
- Match existing code idiom: server renders hidden markup with inline `style="display:none;"`; jQuery `.show()`/`.hide()` toggles it (do **not** use the `hidden` attribute — jQuery `.show()` won't override it).
- Do **not** touch: the blacklisted-client view, `order_notice()` banner, the no-permission branch, the AJAX mark/unmark flow, `.rb-mark-btn`, reasons, or permissions.

**Testing note:** This repo has no JS or admin-render test harness (only `tests/test-normalize.php` for pure logic, which this change does not touch). Verification per task is: PHP lint, JS syntax check, keeping the existing suite green, and a manual browser check on a real order-edit screen. This is intentional, not an omission.

---

### Task 1: Collapse the form behind a reveal button (PHP + JS)

Functional core: by default the metabox body shows only the reveal button; clicking it reveals today's form with the reason select focused. Marking still works end to end.

**Files:**
- Modify: `includes/admin/class-riskybuyer-order-metabox.php` (the `$bl->can_add()` branch of `render_box()`, currently lines 223-231)
- Modify: `assets/admin.js` (first IIFE, after the `.rb-mark-btn` handler, around line 33)

**Interfaces:**
- Consumes: existing `.rb-mark-btn` AJAX handler, `.rb-reason` / `.rb-note-input` field classes (unchanged).
- Produces: a `.rb-reveal-mark` trigger button and a sibling `.rb-mark-form` container, both inside `.rb-metabox`.

- [ ] **Step 1: Wrap the form and add the reveal button (PHP)**

In `includes/admin/class-riskybuyer-order-metabox.php`, replace the entire `elseif ( $bl->can_add() ) {` block body. Change from:

```php
		} elseif ( $bl->can_add() ) {
			echo '<p>' . esc_html__( 'Mark this client as problematic (by name and phone from the order).', 'risky-buyer' ) . '</p>';
			echo '<p><label>' . esc_html__( 'Reason', 'risky-buyer' ) . '<br><select class="rb-reason widefat">';
			foreach ( Riskybuyer_Blacklist::reasons() as $code => $r ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $r['label'] ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p><label>' . esc_html__( 'Note (optional)', 'risky-buyer' ) . '<br><textarea class="rb-note-input widefat" rows="2"></textarea></label></p>';
			echo '<button type="button" class="button button-primary rb-mark-btn">' . esc_html__( 'Mark client', 'risky-buyer' ) . '</button>';
		} else {
```

to:

```php
		} elseif ( $bl->can_add() ) {
			echo '<button type="button" class="button button-primary rb-reveal-mark">' . esc_html__( 'Mark as problematic', 'risky-buyer' ) . '</button>';
			echo '<div class="rb-mark-form" style="display:none;">';
			echo '<p>' . esc_html__( 'Mark this client as problematic (by name and phone from the order).', 'risky-buyer' ) . '</p>';
			echo '<p><label>' . esc_html__( 'Reason', 'risky-buyer' ) . '<br><select class="rb-reason widefat">';
			foreach ( Riskybuyer_Blacklist::reasons() as $code => $r ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $r['label'] ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p><label>' . esc_html__( 'Note (optional)', 'risky-buyer' ) . '<br><textarea class="rb-note-input widefat" rows="2"></textarea></label></p>';
			echo '<button type="button" class="button button-primary rb-mark-btn">' . esc_html__( 'Mark client', 'risky-buyer' ) . '</button>';
			echo '</div>';
		} else {
```

- [ ] **Step 2: Lint the PHP file**

Run: `php -l includes/admin/class-riskybuyer-order-metabox.php`
Expected: `No syntax errors detected in includes/admin/class-riskybuyer-order-metabox.php`

- [ ] **Step 3: Add the reveal handler (JS)**

In `assets/admin.js`, immediately after the closing `} );` of the `.rb-mark-btn` click handler (the block that ends at line 33, before the `.rb-unmark-btn` handler), insert:

```javascript
	$( document ).on( 'click', '.rb-reveal-mark', function () {
		$( this ).hide().siblings( '.rb-mark-form' ).show().find( '.rb-reason' ).trigger( 'focus' );
	} );

```

- [ ] **Step 4: Syntax-check the JS file**

Run: `node --check assets/admin.js`
Expected: no output, exit code 0. (If `node` is unavailable, skip — Step 6 covers behavior.)

- [ ] **Step 5: Confirm the existing test suite still passes**

Run: `php tests/test-normalize.php`
Expected: ends with `15 passed, 0 failed`

- [ ] **Step 6: Manual browser verification**

Open a WooCommerce order whose buyer is **not** yet listed, on the order-edit screen.
Expected:
- The "⚠ Проблемен клиент" metabox body shows **only** the `Маркирай като проблемен` button (no dropdown/textarea visible).
- Clicking it hides the button, reveals the reason dropdown + note + `Маркирай клиента` button inline, with the reason dropdown focused.
- Choosing a reason and clicking `Маркирай клиента` still marks the client (page reloads into the blacklisted view).
- Open a second order for an **already-listed** buyer: the blacklisted view and the top banner look exactly as before.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/class-riskybuyer-order-metabox.php assets/admin.js
git commit -m "Collapse order-metabox mark form behind a reveal button

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Full-width reveal button (CSS polish)

Make the reveal button read as a clear CTA in the narrow sidebar metabox.

**Files:**
- Modify: `assets/admin.css` (in the metabox block near the top, after the `.rb-metabox .rb-meta` rule ending at line 16)

**Interfaces:**
- Consumes: the `.rb-reveal-mark` button class produced in Task 1.

- [ ] **Step 1: Add the CSS rule**

In `assets/admin.css`, after the `.rb-metabox .rb-meta { ... }` rule (ends line 16), insert:

```css
.rb-metabox .rb-reveal-mark {
	display: block;
	width: 100%;
	text-align: center;
}
```

- [ ] **Step 2: Manual browser verification**

Reload the not-yet-listed order-edit screen.
Expected: the `Маркирай като проблемен` button spans the full metabox width and is centered. Clicking it still reveals the form (Task 1 behavior intact).

- [ ] **Step 3: Commit**

```bash
git add assets/admin.css
git commit -m "Style metabox reveal button as a full-width CTA

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Bulgarian translation for the new string

Add the source/translation pair and recompile the binary catalog so the button renders in Bulgarian.

**Files:**
- Modify: `languages/risky-buyer.pot` (after the `Mark client` entry, around line 347)
- Modify: `languages/risky-buyer-bg_BG.po` (after the `Mark client` entry, around line 78)
- Regenerate: `languages/risky-buyer-bg_BG.mo`

**Interfaces:**
- Consumes: the msgid `Mark as problematic` emitted by `render_box()` in Task 1.

- [ ] **Step 1: Add the entry to the POT template**

In `languages/risky-buyer.pot`, directly after the existing block:

```
#: includes/admin/class-riskybuyer-order-metabox.php:157
msgid "Mark client"
msgstr ""
```

insert:

```
#: includes/admin/class-riskybuyer-order-metabox.php:224
msgid "Mark as problematic"
msgstr ""
```

- [ ] **Step 2: Add the translated entry to the Bulgarian catalog**

In `languages/risky-buyer-bg_BG.po`, directly after:

```
msgid "Mark client"
msgstr "Маркирай клиента"
```

insert:

```

msgid "Mark as problematic"
msgstr "Маркирай като проблемен"
```

- [ ] **Step 3: Recompile the binary catalog**

Run: `msgfmt languages/risky-buyer-bg_BG.po -o languages/risky-buyer-bg_BG.mo`
Expected: no output, exit code 0.

- [ ] **Step 4: Verify the new string is in the compiled catalog**

Run: `msgunfmt languages/risky-buyer-bg_BG.mo | grep -A1 "Mark as problematic"`
Expected output includes:
```
msgid "Mark as problematic"
msgstr "Маркирай като проблемен"
```

- [ ] **Step 5: Manual browser verification**

Hard-reload the not-yet-listed order-edit screen (clear any object/opcode cache if present).
Expected: the reveal button reads `Маркирай като проблемен` (Bulgarian), not the English fallback.

- [ ] **Step 6: Commit**

```bash
git add languages/risky-buyer.pot languages/risky-buyer-bg_BG.po languages/risky-buyer-bg_BG.mo
git commit -m "Add Bulgarian translation for the metabox reveal button

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Default body = one button → Task 1 Step 1 (PHP).
- Click reveals form inline, reason focused → Task 1 Steps 3 + 6.
- Marking still works end to end → Task 1 Step 6.
- Blacklisted/banner/no-permission unchanged → enforced by Global Constraints; verified Task 1 Step 6.
- Full-width CTA in narrow sidebar → Task 2.
- New Bulgarian string + recompiled `.mo` → Task 3.

All four spec implementation touch points (metabox PHP, admin.js, admin.css, translations) are covered.

**Placeholder scan:** No TBD/TODO/"handle edge cases"/"similar to" — every code step shows literal code.

**Type/name consistency:** `.rb-reveal-mark` and `.rb-mark-form` are introduced in Task 1 and consumed identically in Tasks 1 (JS), 2 (CSS). The msgid `Mark as problematic` matches between Task 1 (PHP) and Task 3 (.po/.pot). The Bulgarian copy `Маркирай като проблемен` matches the Global Constraints and the AskUserQuestion answer.
