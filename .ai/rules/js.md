---
paths:
  - 'resources/js/**'
---

# Js

## Keyboard shortcuts go through the shortcut system, never an ad-hoc keydown listener
Declare the shortcut in SHORTCUTS (resources/js/lib/shortcut-catalog.ts: keys like `mod+enter`, scope, translated description), handle it with useShortcut(id, handler, { enabled }) or <Shortcut>, and show it with <ShortcutKbd id> plus the hint's aria-keyshortcuts, so the chip reads the same entry the handler does. Never add a window/document keydown listener for a shortcut: the registry owns the only one, and the catalog test rejects two shortcuts on the same keys in overlapping scopes and combos the browser keeps (mod+N/T/W/R/L/Q/P, copy/paste…). The listeners in doc-search (⌘K), sidebar (⌘B) and the categorize page/onboarding step (Ctrl+R/N/B) predate the system and are pending migration.

## A dialog registers its shortcuts from inside DialogContent
The content of Dialog, AlertDialog, Sheet and Drawer is a modal shortcut layer: while open it sits on top of the stack and the layers beneath, the page included, get no keys except `global`-scope shortcuts. useShortcut registers on the layer of the component that calls it, found through React context, so a component that renders its own <Dialog> must place <Shortcut id onTrigger enabled /> inside <DialogContent>; called in the component body, the shortcut lands on the page's layer and sleeps while the dialog is open. The four roots render through ShortcutLayerRoot, which mirrors their open state so a closing modal's shortcuts stop at once (Radix and vaul keep the content mounted through the exit animation, and a second ⌘⏎ there would save twice). A new modal primitive needs both: ShortcutLayerRoot around its root, ShortcutLayer inside its content.

## Shortcut keys: bubble phase, defaultPrevented wins, platform text only after hydration
The registry listens on window in the bubble phase and skips events already defaultPrevented, so a widget that owns a key keeps it: cmdk's Enter, Radix Select's typeahead, Radix's Escape. A guard that must run before Radix (sortable-grid's capture-phase Escape) cannot be a registry shortcut. Plain-key shortcuts never fire from text fields or comboboxes (a closed Radix Select trigger still picks options by typeahead); modifier shortcuts opt in with allowInEditable. Nothing fires from inside an open listbox or menu, so ⌘⏎ never saves under a picker that is still choosing. ⌘ vs Ctrl is unknown on the server (SSR): read it through usePlatform/useShortcutHint, which return null until hydrated, never from navigator during render.
