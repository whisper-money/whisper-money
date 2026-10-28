# Achievements

A medal (an achievement) records a milestone you reached: a visit or saving streak, a net worth or savings rate level, transactions recorded, data kept tidy. Medals cover your whole account, not a space.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List achievements

`GET /api/v1/achievements`

The whole medal catalog, grouped into tracks, earned or not. On each track, the first medal you have not earned yet is `next` and comes named, often with how far along you are; the ones past it are `locked` and stay silhouettes, with no name, icon or figure. The catalog is small and fixed, so it is not paginated.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Achievements |  |
| `data.currency` | string | The currency the money milestones are set in: your account's currency when the medals have levels for it, `USD` otherwise. |
| `data.unlocked` | integer | How many medals you have earned. |
| `data.total` | integer | How many medals there are. |
| `data.streak` | object or null | Your saving streak as it stands: how many months in a row you have saved, from the last monthly report, and the first day of the first of them. It is a live figure, not a medal, and it can break. `null` with no streak running. |
| `data.streak.months` | integer |  |
| `data.streak.since` | string |  |
| `data.latest` | object or null | The medal you earned last, or `null` with none. |
| `data.latest.name` | string or null |  |
| `data.latest.achieved_on` | string |  |
| `data.tracks` | array of AchievementTrack | The tracks, each a ladder of medals. |
| `data.tracks[].key` | string | The track's key. |
| `data.tracks[].label` | string | The track's name. |
| `data.tracks[].note` | string or null | What the track has to explain about itself, or `null`. |
| `data.tracks[].unlocked` | integer | How many of its medals you have earned. |
| `data.tracks[].medals` | array of Achievement | Its medals, from the first rung up. |
| `data.tracks[].medals[].key` | string | The medal's key, unique in the catalog. |
| `data.tracks[].medals[].rarity` | string | How rare the medal is: `common`, `uncommon`, `rare` or `epic`. |
| `data.tracks[].medals[].share` | number or null | The percentage of Whisper Money users who hold it, or `null` while there are too few users to tell or nobody holds it yet. |
| `data.tracks[].medals[].state` | "earned" \| "next" \| "locked" | `earned`; `next` for the one rung of its track you are working towards; or `locked` for the ones past it. |
| `data.tracks[].medals[].name` | string or null | The medal's name. `null` on a `locked` medal, which stays a silhouette: only the `next` one is named. |
| `data.tracks[].medals[].icon` | string or null | The medal's pictogram, a Lucide icon name; `null` on a `locked` medal. |
| `data.tracks[].medals[].figure` | object or null | The milestone the medal stands for: its `type` (`money`, `count`, `percent`, `months`, `weeks` or `days`), its `value` (in minor units of `currency` for money) and that `currency`, `null` for the other types. `null` on a `locked` medal and on the ones that are an event with no number to them. |
| `data.tracks[].medals[].figure.type` | string |  |
| `data.tracks[].medals[].figure.value` | number |  |
| `data.tracks[].medals[].figure.currency` | string or null |  |
| `data.tracks[].medals[].reached` | object or null | On an `earned` medal, what you had reached on the day, shaped like `figure`; `null` otherwise. |
| `data.tracks[].medals[].reached.type` | string |  |
| `data.tracks[].medals[].reached.value` | number |  |
| `data.tracks[].medals[].reached.currency` | string or null |  |
| `data.tracks[].medals[].achieved_on` | string (date) or null | The day the medal was earned, or `null`. |
| `data.tracks[].medals[].progress` | object or null | On the `next` medal of the tracks whose figure is cheap to read, how far along you are: `now` against `goal`, and `unlocking` once you are past the goal but the medal still waits for the nightly check that awards it. `null` otherwise. |
| `data.tracks[].medals[].progress.now` | integer |  |
| `data.tracks[].medals[].progress.goal` | number |  |
| `data.tracks[].medals[].progress.unlocking` | boolean |  |

#### Example request

```bash
curl https://whisper.money/api/v1/achievements \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "currency": "EUR",
        "unlocked": 12,
        "total": 59,
        "streak": {
            "months": 3,
            "since": "2026-07-01"
        },
        "latest": {
            "name": "First transaction",
            "achieved_on": "2026-03-02"
        },
        "tracks": [
            {
                "key": "transactions",
                "label": "Transactions recorded",
                "note": null,
                "unlocked": 1,
                "medals": [
                    {
                        "key": "transactions.2",
                        "rarity": "common",
                        "share": 42.5,
                        "state": "next",
                        "name": "50 transactions",
                        "icon": "ListChecks",
                        "figure": {
                            "type": "count",
                            "value": 50,
                            "currency": null
                        },
                        "reached": null,
                        "achieved_on": null,
                        "progress": {
                            "now": 23,
                            "goal": 50,
                            "unlocking": false
                        }
                    }
                ]
            }
        ]
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`, `not_savings_goal_owner`). |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
