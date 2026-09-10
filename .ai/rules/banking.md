---
paths:
  - 'app/Services/Banking/*Client.php'
---

# Banking

## Route a banking client's HTTP calls through TranslatesTransportFailures
A banking client must not let a transport failure escape raw. `SyncBankingConnectionJob::handleTemporaryError()` charges `consecutive_sync_failures` for anything that is not a `TransientBankingProviderException`, and running that budget out drops the connection out of every future scheduled sync silently, with no way back but reconnecting. A raw `ConnectionException` therefore turns a provider blip into a dead connection, and gets the user "an unexpected error occurred" instead of "temporarily unavailable".

Use `app/Services/Banking/Concerns/TranslatesTransportFailures.php`: implement `provider()`, build the request with `transportClient()` (it sets the read and connect timeouts), and wrap the call in `translateTransportFailures()`. A timeout and a 5xx become transient; 401/403/429 stay `RequestException` because the job acts on those.

Two traps:
- Put the translation **outside** any `retry(..., when: 429)`, never inside the callback — the `when` closure would receive the translated exception and the 429 retry would stop working.
- Cover every path. `BinanceClient::getTickerPrices()` used a separate public client and bypassed the signed-request helper.

`WiseClient` and `InteractiveBrokersClient` still carry their own inline copies of this logic (written before the trait existed, in #968/#970). Folding them in is an open follow-up — do not add a third hand-rolled copy.
