# MAC Project Tracker Botanical UI

This document is the visual source of truth for every MAC Project Tracker admin screen. It governs presentation only. Existing WordPress capabilities, nonces, handlers, data semantics, sync rules, Visual Tone states, capture behavior, provider routing, retries, approvals, and exclusions must remain unchanged during UI work.

## Product character

MAC Tracker is a quiet, premium design-operations workspace: botanical and editorial at the edges, operational and highly legible inside. The V3 direction is **Warm Botanical / Quiet Olive**. Warm ivory, paper, greige, stone and mushroom taupe carry most of the product; olive is a restrained accent. Avoid generic SaaS blue, purple gradients, glass effects, novelty cards, muddy khaki and botanical decoration behind data.

## Tokens

```css
--mac-canvas: #F3F0E8;
--mac-paper: #FBF9F4;
--mac-surface: #FFFEFA;
--mac-wash: #EDE8DE;
--mac-greige: #D8D1C5;
--mac-stone: #B9B0A3;
--mac-mushroom: #746C61;
--mac-olive: #6F7554;
--mac-olive-dark: #4D533A;
--mac-sage: #BFC6AA;
--mac-ink: #25271F;
--mac-line: rgba(77, 83, 58, 0.15);

/* Legacy admin stylesheet aliases retained for compatibility. */
--mac-sage-600: var(--mac-olive);
--mac-linen-100: var(--mac-wash);
```

The identity is warm-neutral rather than green. Ivory, paper and greige provide
the canvas; mushroom taupe handles muted copy; quiet olive identifies active
navigation and deliberate actions. Never use forest green as the dominant page
background.

Semantic states use text plus color: muted green for success/locked, amber for review/warning, blue for running/info, red for failed/blocked, and taupe for idle/neutral.

## Typography

- Inter: navigation, controls, tables, body copy, metrics.
- Cormorant Garamond: selected large editorial titles and footer-band quotes only.
- JetBrains Mono: eyebrows, IDs, timestamps, and technical metadata.

Never use the serif face in dense tables or forms.

## Spacing, radii, and shadows

- Use a 4px base spacing scale with common gaps of 8, 12, 16, 24, 32, and 48px.
- Controls: 8px radius. Cards: 12px. Editorial bands: 16px.
- Use thin warm borders and soft shadows; do not use oversized floating shadows.
- Pages have generous outer whitespace, while tables and logs remain compact.

## App shell and navigation

MAC Tracker routes use a plugin-owned full-page shell at `/mac-project-tracker/`: sticky warm-paper sidebar, sticky clean topbar, and an edge-to-edge neutral content canvas. Do not wrap the app in a floating paper card. The clean child routes are `/projects/`, `/analysis/`, `/skipped/`, and `/settings/`. The sidebar contains exactly Dashboard, Projects, AI Analysis, Skipped Projects, and Settings. Provide a small WordPress Admin escape link and version information. Scope every chrome-neutralizing rule to the MAC Tracker body class so normal wp-admin screens remain untouched.

Standalone routes use the current WordPress session and require an authenticated
administrator with `manage_options`. Production project, domain, WPM and
operational data must never remain anonymously readable. Existing admin-post,
AJAX, REST, nonce and capability protection remains authoritative for every
write action.

At tablet widths the sidebar becomes a drawer. At mobile widths content stacks, tables scroll horizontally, and decoration never covers controls.

## Components

### Buttons and forms

Primary buttons use dark quiet olive with cream text. Secondary buttons are paper/white with warm olive borders. Danger buttons use muted red. Keep labels explicit, focus rings visible, and controls at least 36px tall. Password and technical values use the same form hierarchy as normal text fields.

### Cards

Cards use paper or white surfaces, 1px borders, 12px radii, and restrained padding. Use cards to group metrics, analytics, forms, or review material—not as a replacement for dense tabular data.

### Tables

Tables use Inter, sticky headers, thin separators, compact rows, clear sort state, and subtle hover. Important metadata can use JetBrains Mono. On small screens preserve the table and allow horizontal scrolling.

### Badges

Badges are compact, slightly rounded, and always include readable status text. Color is supportive, never the only signal.

### Tabs and dropdowns

Tabs are a calm horizontal rail with a clear quiet-olive active state. Dropdown row actions are keyboard-accessible, close on Escape/outside click, and never introduce actions unsupported by existing handlers.

## Page composition

- Dashboard: the most editorial; date range, metrics, restrained analytics, activity, health.
- Projects: the most operational; functional filters and a premium dense table.
- AI Analysis: hybrid; automation controls, Action/Processing/Review/Locked/Workflow Log tabs, screenshots, evidence, and existing actions.
- Skipped Projects: minimal searchable table and restore.
- Settings: Import/Connections/Automation/Data Management tabs, with destructive actions in a final Danger Zone.

## Bottom editorial band

Every main page ends with exactly one reusable botanical editorial band after the last operational section. It uses a local botanical illustration or a warm neutral-to-olive gradient fallback, a subtle overlay, one Cormorant Garamond quote, and a tiny mono label. Never place a band between tables, cards, forms, or workflow controls.

Page quotes:

- Dashboard: “Good websites grow businesses.”
- Projects: “Good systems make good work visible.”
- AI Analysis: “Turn websites into insights.”
- Skipped Projects: “Keep the signal. Remove the noise.”
- Settings: “Better tools create better work.”

## Responsive rules

- 1440px and above: fixed sidebar, wide workspace, multi-column analytics.
- 1024–1439px: narrower sidebar and two-column Dashboard.
- 768–1023px: drawer sidebar, two-column where space permits, scrollable tables.
- Below 768px: single-column cards/forms, drawer navigation, horizontally scrollable tables, compact topbar.
- Honor reduced-motion preferences.

## Accessibility

Use semantic headings, navigation, forms, tables, and status text. Maintain readable contrast, visible `:focus-visible` outlines, useful icon labels, keyboard-operable tabs and menus, and appropriate `aria-expanded`, `aria-selected`, and `hidden` states.

## Do / don't

Do use warm-neutral canvases, paper navigation, quiet olive accents, mono metadata, restrained serif moments, readable data tables, and one page-end botanical band.

Do not use blue as the product identity, arbitrary gradients, giant radii, low-contrast beige-on-beige, serif data text, remote image hotlinks, decorative plants behind operational content, or global CSS that leaks into other wp-admin pages.
