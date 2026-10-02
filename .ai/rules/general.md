---
paths:
  - chatgpt-app-submission.json
---

# General

## The reviewer data follows the stored test cases, not the other way round
OpenAI runs the test cases stored with the plugin on openai-review@whisper.money, which `demo:reset --review` reseeds daily. Those cases and the reviewer password were entered in the old submission form, which no longer exists, and a migrated plugin cannot replace them: a ZIP with `review.test_cases` needs a package-declared MCP server, and adding one to an existing plugin is rejected. So `chatgpt-app-submission.json` mirrors what OpenAI has stored, and `reviewDataset()` is shaped to it: euros, Uber rides under "Shopping", every ledger account bank-imported. `REVIEW_PASSWORD` must stay the password stored with the submission. Changing either side means re-running the cases in ChatGPT against the other.
