---
paths:
  - chatgpt-app-submission.json
---

# General

## Test cases are written against the --review dataset
OpenAI runs `test_cases`/`negative_test_cases` on openai-review@whisper.money, which `demo:reset --review` reseeds daily from the demo dataset: English, USD, Primary Checking bank-imported, budgets "Monthly Groceries" / "Weekly Dining Out". Every prompt must name things that dataset has (merchants, accounts, categories, budgets, currency). v2.1.0 was rejected because the cases asked for euros, a "Shopping" category, a "food" budget and a bank account the stale account did not have. Changing the demo dataset or a test case means re-checking the other.
