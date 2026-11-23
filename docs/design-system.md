# Design system

The rules for building Whisper Money's interface. Read this before you build or
restyle a screen. It describes the system the app already uses (shadcn/ui
`new-york`, a neutral oklch palette, IBM Plex Sans, lucide icons) and fixes
the few decisions that were never written down. It is not a redesign.

- **Source of truth is the code**: `resources/css/app.css` (token values, light
  and dark, chart palettes), `resources/css/theme.css` (the Tailwind theme,
  shared with the user-widget stylesheet `widgets.css`) and
  `resources/js/components/ui` (the primitives). User widgets only get the
  classes safelisted in `widgets.css` (`warning` is not there yet). When this document and the
  code disagree, the code wins and this document gets fixed.
- **Live previews**: [`/design-system`](https://whisper.money/design-system)
  renders the real components with light/dark, privacy mode and chart-theme
  toggles. It holds no rules; this document does.
- **Out of scope**: the marketing and legal pages (`welcome`, `terms`,
  `privacy`, `comparison`, `roadmap`, `integrations`, `documentation/*` and
  `components/landing`) have their own look. These rules don't apply to them.
- **Known gaps**: where the code does not follow a rule yet, the
  [debt catalog](#debt-catalog) says so and links the issue that fixes it.
  Don't copy a pattern listed there.

## Principles

1. **Neutral first.** The interface is greyscale. Color is used sparingly and
   on purpose: one green for money in the user's favour, one amber for warnings,
   one red for errors and destructive actions, and the chart palette for data.
2. **Tokens, not palette classes.** Write `text-success`, `bg-muted`,
   `border-border`. Not `text-green-600` or `bg-zinc-100`. Tokens switch with
   dark mode on their own, so you don't need a `dark:` pair for them.
3. **Reuse before you write.** Look in `components/ui` first, then in
   `components/` for a feature component that already does the job. Extend a
   primitive with a variant before you fork it.
4. **Privacy is a feature.** Every amount the user owns can be masked. Render
   money through `AmountDisplay` so privacy mode works without extra code.
5. **Every component supports dark mode.** Check both themes before you call it
   done.

## Foundations

### Color tokens

Defined as CSS variables in `:root` (light) and `.dark` in `app.css`, and
exposed as Tailwind colors (`bg-*`, `text-*`, `border-*`…) in `theme.css`.

| Token                                    | Use it for                                                                                                                                                                                                   |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `background` / `foreground`              | Page surface and default text.                                                                                                                                                                               |
| `card` / `card-foreground`               | `Card` surfaces. Same as background today.                                                                                                                                                                   |
| `popover` / `popover-foreground`         | Popovers, menus, dropdowns.                                                                                                                                                                                  |
| `primary` / `primary-foreground`         | The main action (default `Button`). Near-black in light, near-white in dark.                                                                                                                                 |
| `secondary` / `secondary-foreground`     | Secondary buttons and quiet fills.                                                                                                                                                                           |
| `muted` / `muted-foreground`             | Quiet surfaces and secondary text (descriptions, captions, unfavourable trends).                                                                                                                             |
| `accent` / `accent-foreground`           | Hover and selected states of menu items and toggles.                                                                                                                                                         |
| `border`, `input`, `ring`                | Borders, input borders, focus rings.                                                                                                                                                                         |
| `success`                                | Money in the user's favour: income amounts, favourable trends. Not for charts.                                                                                                                               |
| `warning` / `warning-foreground`         | Something the user should act on that is not an error (an expiring connection, a budget close to its limit). `warning` is the ink (text, icon, border); `warning-foreground` is text on a `bg-warning` fill. |
| `destructive` / `destructive-foreground` | Errors and destructive actions only. Note that `destructive-foreground` is used as error _text_ (`Alert`), not as text on a red fill.                                                                        |
| `sidebar-*`                              | The app sidebar. Don't use outside it.                                                                                                                                                                       |
| `chart-1` … `chart-10`                   | Data series. See [Chart palettes](#chart-palettes).                                                                                                                                                          |

Feature tokens that only one feature uses. Don't use them elsewhere:

- `--streak-fill`, `--streak-border`, `--streak-track`, `--streak-from`,
  `--streak-to`, `--streak-ink`: the visit streak pill in the header.
- `--medal-copper`, `--medal-steel`, `--medal-gold`, `--medal-obsidian`:
  achievement tiers.
- `--spent`, `--allocated`, `--spent-prev`: budget progress, defined per chart
  theme.

User-chosen colors are data, not tokens: category and label colors come from
the palettes in `types/category.ts` and `types/label.ts`.

### Chart palettes

The user picks a chart theme in Settings → Appearance: `neutral`, `colorful`
(the default), `blue` or `pink`. The theme is a `data-chart-color` attribute on
`<html>` (none for `neutral`), and each theme redefines `--chart-1..10` for
light and dark in `app.css` (`[data-chart-color=…]` and
`.dark[data-chart-color=…]`). `hooks/use-chart-color-scheme.tsx` stores the
choice.

- `neutral`: zinc greys.
- `colorful`: blue, emerald, amber, rose, violet, cyan, orange, teal, pink,
  indigo.
- `blue` and `pink`: one hue from dark to light.

The rules for using them are in [Charts](#charts).

### Typography

| Class                 | Font                   | Use it for                                                                                                                                                                   |
| --------------------- | ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `font-sans` (default) | IBM Plex Sans          | Everything.                                                                                                                                                                  |
| `font-mono`           | Google Sans Code       | Codes, IDs, the app name in the sidebar, aligned figures (`AmountDisplay monospace`).                                                                                        |
| `font-heading`        | IBM Plex Serif, italic | Display headings: page titles in onboarding, summaries and empty states, section titles on `/design-system`. A plain CSS class in `app.css` (base layer), not a theme token. |

Sizes are Tailwind's default scale (`text-xs` … `text-4xl`). Body text is
`text-sm` in dense UI (tables, cards, forms) and `text-base` in reading
contexts. Use `tabular-nums` (or `AmountDisplay monospace`) wherever figures
line up in a column. Fonts load from `resources/css/fonts.css`; don't add
another font.

### Spacing

Tailwind's default spacing scale; there are no custom spacing tokens.

- Use `gap-*` on a flex or grid parent instead of margins between siblings
  (`space-y-*` is acceptable in old code, don't add more).
- Page padding: `p-4` on the page container, `sm:px-6` on wide chrome.
- Inside cards: the padding `Card` and its parts already give you; don't add
  your own on top.

### Radius

One base radius, `--radius: 0.625rem`, from which the theme derives
`rounded-lg` (= `--radius`), `rounded-md` (−2px) and `rounded-sm` (−4px).
`rounded-xl` (Tailwind's default) is what `Card` uses, with `py-6` and `gap-6`. Buttons and inputs are
`rounded-md`; badges and avatars `rounded-full`.

### Shadows

There are no shadow tokens. Current usage, to keep consistent:

- `shadow-xs`: outline buttons, inputs, selects (from the primitives).
- `Card` has no shadow: a border, plus the `GlowingEffect` it renders on hover.
- `shadow-sm`: small raised elements (toggles, chips) in a few feature components.
- `shadow-md`/`shadow-lg`: popovers, dropdowns, dialogs and sheets (from the
  primitives).
- Elsewhere, prefer a border to a shadow. Don't put shadows on
  in-page surfaces yourself.

### Page width

App pages are capped at `max-w-page` (80rem, `--container-page` in
`theme.css`) and centred: `mx-auto w-full max-w-page`. The header uses the same
cap so content and chrome line up.

## Primitives

All in `resources/js/components/ui`. Most are shadcn/ui (`new-york`) on Radix;
some are our own. Import them; don't copy their markup.

### Buttons

- `Button`: variants `default` (the one main action of a view), `secondary`,
  `outline` (most secondary actions), `ghost` (toolbar and icon buttons),
  `link`, `destructive` (the confirm button of a destructive action, not the
  button that opens the confirmation). Sizes `sm`, `default`, `lg`, `icon`,
  `icon-sm`, `icon-lg`.
- `CreateButton`: a `Button` with a leading plus icon, for "New …" actions.
- `ButtonGroup`: joined buttons for one choice or related actions.
- A shortcut chip goes inside the button as a trailing `Kbd` (see the shortcut
  rules in `.ai/rules/js.md`).
- Don't: an `<a>` or a `div` styled as a button; a button with only an icon and
  no `aria-label`.

### Badges

`Badge`: `default`, `secondary`, `outline`, `destructive`. For short statuses
and tags. Not for counts in navigation, and not as buttons.

### Alerts

`Alert` (`default`, `destructive`) with `AlertTitle`/`AlertDescription`, for a
message that belongs to a page or a form. `components/alert-error.tsx` renders a
list of errors. For a transient message, use a toast (`sonner`, via `AppToaster`). A
warning alert uses the `warning` token (no variant yet: see the
[debt catalog](#debt-catalog), item a).

### Cards

`Card` with `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`,
`CardFooter`. Any bordered surface that groups content is a `Card`. Don't build
a card from `rounded-xl border` divs (debt item g).

### Form controls

- Text: `Input`, `Textarea`, `PasswordInput` (show/hide toggle), `InputOTP`.
- Money: `AmountInput` (works in minor units; never parse amounts by hand).
- Choice: `Select` (one value from a short list), `MultiSelect` (several
  values, with search and a tree), `Checkbox`, `RadioGroup`, `Toggle`,
  `ToggleGroup` (a segmented choice such as a period).
- Search-and-pick: `Command` inside a `Popover` (or `PopoverOrDrawer`), as the
  category and label comboboxes in `components/shared` do. Reuse those.
- Always pair a control with a `Label` (`htmlFor`), and show the error under it
  with `InputError`.

### Feedback

`Progress`, `Skeleton` (loading placeholders, see [Skeletons](#skeletons)),
`Spinner` (an action in flight), `Kbd`/`KbdGroup` (keyboard shortcuts),
`AiSparkleIcon` (marks AI features), `Avatar`, `AnimatedCollapse` (show/hide
with a height animation), `GlowingEffect` (the hover glow `Card` already
renders; don't add it again).

### Overlays

- `Dialog`: a focused task (edit a transaction, create a budget).
- `AlertDialog`: confirm a destructive or irreversible action.
- `Sheet`: side panel for filters or details next to the page.
- `Drawer` (vaul): bottom sheet on the phone.
- `Popover`: small anchored content. `DropdownMenu` / `ContextMenu`: lists of
  actions. `Tooltip`: a short label for an icon or a truncated value; never
  essential information, it does not show on touch.
- On the phone a popover becomes a drawer: use `PopoverOrDrawer` or
  `AdaptivePopover` (see [Adaptive popover](#adaptive-popover)).
- A dialog registers its keyboard shortcuts from inside its content (see
  `.ai/rules/js.md`).

### Navigation and data

`Breadcrumb` (the app header renders it from the page's `breadcrumbs`),
`Separator`, `Table`, `DataTable` (+ `DataTablePagination`,
`DataTableViewOptions`) for sortable tabular data, `Collapsible`, `Sidebar`
(the app shell only). The transactions table has its own rules in
`.ai/rules/transactions-table.md`.

## Finance patterns

### Amounts

- Render a user's money with `components/ui/amount-display.tsx`
  (`<AmountDisplay amountInCents currencyCode />`). Amounts are integers in the
  currency's **minor units**, which are not always cents (1 for JPY/COP, 1000
  for KWD, 10⁸ for BTC): `formatCurrency` resolves the scale from the code.
- `AmountDisplay` masks digits when privacy mode is on (`usePrivacyMode`), so
  you get masking for free. When you must produce a string yourself (a chart
  axis, a tooltip, an `aria-label`), format with `formatCurrency` and pass it
  through `maskDigits` when `isPrivacyModeEnabled` is true.
  `PrivacyRevealScope` lets one block reveal its amounts while privacy mode
  stays on.
- **Color policy for amounts**: income in `text-success` with a `+` sign
  (`showSign`); expenses in the default foreground color with their `−` sign.
  Never red for an expense: spending money is not an error.
- Use `variant="large"` for the headline figure of a card, `compact` in dense
  lists, `monospace` when figures line up in a column.

### Trend indicators

Existing components: `components/dashboard/percentage-trend-indicator.tsx` and
`amount-trend-indicator.tsx`.

- A change that is **favourable to the user** is `success`; an
  **unfavourable** one is `muted-foreground`. Red is never used for trends.
- "Favourable" depends on the metric: more income or savings is favourable,
  more spending is not (`invertColors`).
- Show the sign and an up/down icon, so the meaning does not rely on color
  alone.
- Both components still use raw green/red classes (debt item a); follow the
  rule above, not their current colors.

### Charts

- Series colors come **only** from `--chart-1..10` (`var(--color-chart-N)`),
  so they follow the user's chart theme.
- **Income always uses the same slot** across every chart, so the user learns
  one color for it.
- **Never use `--success` (or `--destructive`) in a chart series.** Those are
  for text; in a chart they would ignore the user's theme.
- **No component branches on the active chart theme.** A chart never reads
  `chartColorScheme` to pick colors; what a slot looks like is decided in CSS,
  per theme. (`useChartColors` and its `isColorful` branches are debt item b.)
- Build charts on `components/ui/chart.tsx` (`ChartContainer`,
  `ChartTooltip`, `ChartLegend`), and reuse `StackedBarChart` /
  `StackedAreaChart` where they fit. Tooltips format amounts through the
  privacy-aware formatter.

## Page patterns

### Layouts

- `AppLayout` (`layouts/app-layout.tsx`): every signed-in app page. Sidebar,
  header with breadcrumbs, and the page content. Pass `breadcrumbs`. Wrap your
  content in `mx-auto flex w-full max-w-page flex-col gap-6 p-4`.
- `SettingsLayout` (`layouts/settings/layout.tsx`): settings pages, inside
  `AppLayout`. Each settings section starts with `HeadingSmall`.
- `AuthLayout` (`layouts/auth-layout.tsx`, built on
  `layouts/auth/auth-simple-layout.tsx`): login, registration and the other
  auth pages. It is the only auth layout; the unused split and card variants
  were removed ([#1366](https://github.com/whisper-money/whisper-money/issues/1366)).
- `OnboardingLayout`, `SubscriptionLayout`: the onboarding flow and the
  subscription screens.
- Don't add a new layout for one page; extend one of these.

### Headings

- `components/heading.tsx` (`Heading`): title and description at the top of a
  settings area.
- `components/heading-small.tsx` (`HeadingSmall`): a section inside a page.
- There is no shared page header yet (debt item e); until there is, match the
  `<h1>` of a sibling page.

### Skeletons

Loading states (deferred props, lazy data) use `Skeleton` blocks shaped like
the content they replace: same height, same layout, so nothing jumps when data
arrives. Don't show a spinner for page content; spinners are for actions.

### Dialogs and drawers

Use `Dialog` for a task on desktop and `Drawer` for the same task on the phone
when it needs the thumb (pickers, short forms); `useIsMobile` decides. For a
panel hung off a button in the chrome, use `AdaptivePopover`.

### Adaptive popover

`AdaptivePopover` (in `components/ui/adaptive-popover.tsx`): a popover on
desktop, a bottom drawer on the phone. `PopoverOrDrawer` is the lower-level
switch the comboboxes use. Use these instead of writing the switch again.

### Forms

- Inertia `<Form>` or `useForm`, with Wayfinder actions (see `CLAUDE.md`).
- One column; `grid gap-2` for label + control + error, `gap-6` between
  fields.
- `Label` above the control, `InputError` under it, the submit `Button`
  (default variant) at the end, left-aligned in settings.
- Validation messages come from the server (Form Requests); show them, don't
  duplicate them on the client.

## Do and don't

| Do                                                    | Don't                                                             |
| ----------------------------------------------------- | ----------------------------------------------------------------- |
| `text-success` for income and favourable trends       | `text-green-600`, `text-emerald-500`                              |
| `text-warning` for warnings                           | `text-amber-600`, `bg-yellow-50`                                  |
| `text-destructive` for errors and destructive actions | Red for expenses or unfavourable trends                           |
| `<AmountDisplay>` for money                           | `formatCurrency()` straight into JSX                              |
| `var(--color-chart-N)` in charts                      | `--success`, palette colors, or an `isColorful` branch in a chart |
| `<Card>` for a bordered group                         | `<div className="rounded-xl border">`                             |
| A primitive from `components/ui`                      | A copy of its markup, or a new library for the same thing         |
| `gap-*` between siblings                              | Margins between siblings                                          |
| `mx-auto max-w-page` for page width                   | A one-off `max-w-[…]`                                             |
| `Skeleton` shaped like the content                    | A centred spinner for page content                                |
| Check light and dark                                  | Tokens with a `dark:` duplicate, or palette classes without one   |

## Debt catalog

Patterns in today's code that break these rules. Each has an issue, fixed by a
stacked PR in the design-system stack. Counts were measured over
`resources/js` excluding tests and the out-of-scope marketing pages.

|     | Debt                                                                                                                 | Evidence                                                                                                                                                                                                                                                                                                | Issue                                                               |
| --- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| a   | Hardcoded green/red/emerald/rose/amber classes instead of `success` / `warning` / `destructive` / `muted-foreground` | 72 files (red 35, emerald 28, amber 25, green 24, rose 5). E.g. `pages/settings/billing.tsx`, `components/transactions/transaction-analysis-drawer.tsx`, `components/dashboard/percentage-trend-indicator.tsx`, `components/ui/amount-display.tsx` (`positive-highlight`), `components/input-error.tsx` | [#1358](https://github.com/whisper-money/whisper-money/issues/1358) |
| b   | Components branching on the chart theme (`isColorful` in `useChartColors`)                                           | `hooks/use-chart-color-scheme.tsx` and 11 consumers, e.g. `components/dashboard/net-worth-chart.tsx`, `components/charts/cashflow-trend-chart.tsx`, `components/charts/sankey-chart.tsx`                                                                                                                | [#1359](https://github.com/whisper-money/whisper-money/issues/1359) |
| c   | Ad-hoc `formatCurrency` / `Intl.NumberFormat` in JSX instead of `AmountDisplay`                                      | 42 `.tsx` files (vs 41 using `AmountDisplay`). E.g. `components/onboarding/step-reveal.tsx`, `components/transactions/split-transaction-dialog.tsx`, `pages/savings-goals/show.tsx`                                                                                                                     | [#1360](https://github.com/whisper-money/whisper-money/issues/1360) |
| d   | No shared `EmptyState`                                                                                               | ~15 hand-rolled, e.g. `pages/notifications/index.tsx`, `pages/achievements/index.tsx`, `components/dashboard/top-categories-card.tsx`                                                                                                                                                                   | [#1361](https://github.com/whisper-money/whisper-money/issues/1361) |
| e   | No shared `PageHeader`                                                                                               | `Heading` in 2 files; hand-written `<h1>` in 13, e.g. `pages/transactions/index.tsx`, `pages/monthly-summaries/index.tsx`                                                                                                                                                                               | [#1362](https://github.com/whisper-money/whisper-money/issues/1362) |
| f   | No generic `StatTile`                                                                                                | Per-feature cards: `components/dashboard/cashflow-summary-card.tsx`, `account-balance-card.tsx`, `monthly-savings-dashboard-card.tsx`                                                                                                                                                                   | [#1363](https://github.com/whisper-money/whisper-money/issues/1363) |
| g   | Raw `rounded-xl border` / `rounded-lg border` divs instead of `<Card>`                                               | 71 files vs 39 importing `Card`. E.g. `pages/budgets/show.tsx`, `pages/notifications/index.tsx`                                                                                                                                                                                                         | [#1364](https://github.com/whisper-money/whisper-money/issues/1364) |
| h   | ~~`@headlessui/react` next to Radix~~ **Resolved**                                                                   | The "Saved" flash in `pages/settings/account.tsx` and `pages/settings/password.tsx` now uses a `tw-animate-css` fade; the package is gone and ESLint `no-restricted-imports` forbids `@headlessui/*`                                                                                                    | [#1365](https://github.com/whisper-money/whisper-money/issues/1365) |
| i   | ~~Unused auth layouts~~ **Resolved**                                                                                 | `layouts/auth/auth-split-layout.tsx` and `auth-card-layout.tsx` are deleted, with the `quote` shared prop only the split one read; `auth-simple-layout.tsx` stays, used through `AuthLayout`                                                                                                            | [#1366](https://github.com/whisper-money/whisper-money/issues/1366) |
