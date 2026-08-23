---
paths:
  - 'app/Mcp/**'
---

# MCP server

## Adding or removing a tool touches four other files, all enforced
Registering a class in `WhisperMoneyServer::$tools` is the easy half. Four surfaces go red in CI if they are not updated with it:

1. `tests/Unit/Mcp/ToolAnnotationsTest.php` — hardcodes the read-only tool list, the destructive list, and the total as `count($readOnly) + <write count>`.
2. `tests/Unit/Mcp/LandingToolCountTest.php` — asserts `MCP_READ_TOOL_COUNT` and `MCP_WRITE_TOOL_COUNT` in `resources/js/pages/welcome.tsx` match the served split. The landing page states those two numbers in prose; the tool-chip animation next to them is unrelated marketing and needs no edit.
3. `chatgpt-app-submission.json` — every served tool needs a `tools.<name>` entry with `annotations` plus all three justifications. `app_info.description` also enumerates what the app can do.
4. The `#[Instructions]` block on `WhisperMoneyServer` — the server-level doc the agent actually reads.

`ToolAnnotationsTest` also caps every tool `#[Description]` at 200 characters (the ChatGPT submission form's limit). Detail that does not fit belongs in a schema field description or the instructions.

## MCP strings stay English, including in comments
Never add `__()` anywhere under `app/Mcp`. The localization extractor scans docblocks and comments too, so even an example `__('…')` inside a comment becomes a required `lang/es.json` key and reddens CI.

## Presenters are shared through a Concerns trait, never copied
A row shape returned by more than one tool lives in `app/Mcp/Tools/Concerns/Presents*.php` (see `PresentsBudgets`, `PresentsAutomationRules`). Read tools extend `McpTool` and write tools extend `WriteTool`, so a presenter parked on `WriteTool` is unreachable from a list tool — and copying it trips `bun run dry`, a required check.
