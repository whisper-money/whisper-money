---
paths:
  - 'app/Jobs/**'
---

# Jobs

## failed() runs on a fresh instance, not the one that ran handle()
The queue calls a job's `failed()` on a brand-new object: `CallQueuedHandler::failed()` does `unserialize($data['command'])` and only attaches the `Job` to it. So no property set during `handle()` is visible in `failed()` — a "did I already do X this run?" flag there is always its default, and a test that calls `$job->handle()` then `$job->failed()` on the same object will pass while doing nothing in production.

Coordinate the two halves through something durable instead (a row, a cache key) and write the test with a fresh `new TheJob(...)` before calling `failed()`. `$this->attempts()` does work in `failed()` — the Job instance is attached.

Bitten in #833, where a private bool flag was meant to stop `SyncBankingConnectionJob::failed()` re-logging a failure `handle()` had already recorded.
