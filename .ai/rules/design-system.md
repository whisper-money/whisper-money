---
paths:
    - 'resources/js/**/*.tsx'
    - 'resources/css/**'
---

# Design system

## Read docs/design-system.md before building or restyling UI

The rules for tokens, primitives, amounts, charts and page patterns live in `docs/design-system.md`; `/design-system` previews the real components. The code (`resources/css/app.css`, `resources/css/theme.css`, `resources/js/components/ui`) is the source of truth. Its debt catalog lists the patterns still in the code that break the rules (raw bordered divs, per-feature stat cards…): don't copy them. Marketing and legal pages (`welcome`, `terms`, `privacy`, `comparison`, `roadmap`, `integrations`, `documentation/*`) are out of scope.

## Color policy

- Income amounts in `text-success` with a `+`; expenses in the foreground color with their sign. Never red for an expense.
- Trends: favourable to the user → `success`, unfavourable → `muted-foreground`. Red is never used for trends.
- Warnings → the `warning` token (`text-warning`, `bg-warning text-warning-foreground`). Errors and destructive actions → `destructive` only.
- Charts use only `--chart-1..10` from the user's chart theme; income always takes the same slot; never `--success` in a chart series.
- Tokens, not palette classes: no `text-green-*`, `text-red-*`, `text-emerald-*`, `text-rose-*`, `text-amber-*` in app UI.

## Reuse components/ui before writing a component

Look in `resources/js/components/ui` (and the feature folders) before writing a new component, and extend a primitive with a variant rather than copying its markup. Money goes through `AmountDisplay`, which handles privacy mode, or `useFormatAmount()` where it must be a string (a translated sentence, an `aria-label`, a chart tick); never `formatCurrency` or `Intl.NumberFormat({ style: 'currency' })` from a component (`lib/amount-display-guard.test.ts`). A bordered group is a `Card`. An app page opens with `PageHeader` (`components/page-header.tsx`: `title`, `description`, `media`, `actions`), its only `<h1>`; `HeadingSmall` is for sections inside a page, never the page title (`lib/page-header-guard.test.ts`). A list, table, chart or page with nothing to show renders `EmptyState` (`components/ui/empty-state.tsx`, `size="inline"` inside a card, cell, drawer or picker), never a hand-rolled centred `<p>` (`lib/empty-state-guard.test.ts`).

## No component branches on the chart theme

A component never reads `chartColorScheme` (or an `isColorful` flag) to choose colors. What a chart slot looks like per theme is decided in CSS (`[data-chart-color=…]` in `app.css`). The branches in `useChartColors` (`hooks/use-chart-color-scheme.tsx`) are known debt, not a pattern to extend.
