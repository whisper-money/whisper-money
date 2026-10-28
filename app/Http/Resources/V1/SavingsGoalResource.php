<?php

namespace App\Http\Resources\V1;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPresenter;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A savings goal, tracked through a label: its contributions are the
 * transactions tagged with it. A `one_off` goal carries its `progress`
 * towards a total, a `monthly` goal its `monthly` record; the fields of the
 * other kind are `null`.
 *
 * @mixin SavingsGoal
 */
#[SchemaName('SavingsGoal')]
class SavingsGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $row = app(SavingsGoalPresenter::class)->one($this->resource);

        return [
            /**
             * The goal's id.
             *
             * @format uuid
             *
             * @example 0199a3fb-2c3d-7e4f-8a5b-6c7d8e9f0a12
             */
            'id' => $this->id,
            /**
             * The id of the space the goal is in.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'space_id' => $this->space_id,
            /**
             * The goal's name, which is also the name of its label.
             *
             * @example Emergency fund
             */
            'name' => $this->name,
            /**
             * `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created.
             *
             * @example monthly
             */
            'kind' => $this->kind->value,
            /**
             * The id of the goal's label. A transaction counts towards the goal once it carries this label: set it with `PUT /api/v1/transactions/{id}/labels`.
             *
             * @format uuid
             *
             * @example 0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23
             */
            'label_id' => $this->label_id,
            /**
             * The currency of the goal's amounts: your account's currency, as an ISO 4217 code.
             *
             * @example EUR
             */
            'currency' => $user->currency_code ?? 'USD',
            /**
             * One-off goals: the total to reach. `null` on a monthly goal.
             *
             * @var int|null
             *
             * @example null
             */
            'target_amount' => $row['target_amount'] ?? null,
            /**
             * One-off goals: what was already saved when the goal was created. `null` on a monthly goal.
             *
             * @var int|null
             *
             * @example null
             */
            'initial_amount' => $row['initial_amount'] ?? null,
            /**
             * One-off goals: the day to reach the total by, or `null` for none.
             *
             * @var string|null
             *
             * @format date
             *
             * @example null
             */
            'target_date' => $row['target_date'] ?? null,
            /**
             * One-off goals: where the goal stands. `saved` and `target` are amounts, `percentage` how much of the target is saved, `rate_per_day` what was added per day since the goal started, `status` (`completed`, `ahead`, `on_track` or `behind`, `null` without a target date) its pace against the target date, `expected_today` what that pace asks to have saved by now, `estimated_date` when the current rate reaches the target and `required_per_month` what is left to save each month to make the target date. `null` on a monthly goal.
             *
             * @var array{saved: int, target: int, percentage: float, target_date: ?string, rate_per_day: float, expected_today: ?int, status: ?string, estimated_date: ?string, required_per_month: ?int}|null
             *
             * @example null
             */
            'progress' => $row['progress'] ?? null,
            /**
             * Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months, frozen when the month opens. `null` on a one-off goal.
             *
             * @var 'amount'|'income_rate'|null
             *
             * @example amount
             */
            'monthly_target_type' => $row['monthly_target_type'] ?? null,
            /**
             * Monthly goals with an `amount` target: the sum to save each month. `null` otherwise.
             *
             * @var int|null
             *
             * @example 30000
             */
            'monthly_target_amount' => $row['monthly_target_amount'] ?? null,
            /**
             * Monthly goals with an `income_rate` target: the percentage of income to save each month. `null` otherwise.
             *
             * @var float|null
             *
             * @example null
             */
            'monthly_target_rate' => $row['monthly_target_rate'] ?? null,
            /**
             * Monthly goals: whether you get an email 5 days before the month ends while the month is short of its target. `null` on a one-off goal.
             *
             * @var bool|null
             *
             * @example true
             */
            'notify_on_month_end_reminder' => $row['notify_on_month_end_reminder'] ?? null,
            /**
             * Monthly goals: the savings account whose incoming transfers an automation rule tags to this goal, or `null` for none.
             *
             * @format uuid
             *
             * @example 0199a3f3-7d2e-7c5f-8b9a-3e6d2c4b5a71
             */
            'auto_tag_account_id' => $this->auto_tag_account_id,
            /**
             * Monthly goals: each month judged against its own target, with nothing carried over between months. `current` is the month in progress (or `null`), with what is `remaining` and the `days_left`; `history` lists every month since the goal was created, oldest first. Each month has its `target`, `saved`, signed `difference`, `income_base` (the income an `income_rate` target was worked out from) and `status`: `met`, `missed`, `in_progress`, `partial` (the goal was created in the month's last 5 days) or `archived` (the goal was archived during it). A `partial` or `archived` month has no verdict and is left out of `months_met`, `months_closed`, `streak`, `best_streak` and `cumulative_difference`. A transaction tagged late recalculates the month it belongs to. `null` on a one-off goal.
             *
             * @var array{current: array{month: string, target: int, saved: int, difference: int, status: string, income_base: ?int, remaining: int, days_left: int}|null, history: list<array{month: string, target: int, saved: int, difference: int, status: string, income_base: ?int}>, months_met: int, months_closed: int, cumulative_difference: int, streak: int, best_streak: int}|null
             *
             * @example {"current": {"month": "2026-10", "target": 30000, "saved": 25000, "difference": -5000, "status": "in_progress", "income_base": null, "remaining": 5000, "days_left": 29}, "history": [{"month": "2026-09", "target": 30000, "saved": 32000, "difference": 2000, "status": "met", "income_base": null}], "months_met": 1, "months_closed": 1, "cumulative_difference": 2000, "streak": 1, "best_streak": 1}
             */
            'monthly' => $row['monthly'] ?? null,
            /**
             * When the goal was archived in the app, or `null`. An archived goal keeps what it saved but can no longer change.
             *
             * @format date-time
             *
             * @example null
             */
            'archived_at' => $this->archived_at?->toIso8601String(),
            /**
             * When the goal was created.
             *
             * @format date-time
             *
             * @example 2026-09-10T09:30:00+00:00
             */
            'created_at' => $this->created_at->toIso8601String(),
            /**
             * When the goal was last changed.
             *
             * @format date-time
             *
             * @example 2026-10-03T18:05:00+00:00
             */
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
