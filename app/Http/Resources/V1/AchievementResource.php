<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A medal: a milestone you reached, or one still ahead. A medal is never taken
 * back once earned, even if the streak or balance behind it falls.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('Achievement')]
class AchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The medal's key, unique in the catalog.
             *
             * @var string
             *
             * @example transactions.2
             */
            'key' => $this->resource['key'],
            /**
             * How rare the medal is: `common`, `uncommon`, `rare` or `epic`.
             *
             * @var string
             *
             * @example common
             */
            'rarity' => $this->resource['rarity'],
            /**
             * The percentage of Whisper Money users who hold it, or `null` while there are too few users to tell or nobody holds it yet.
             *
             * @var float|null
             *
             * @example 42.5
             */
            'share' => $this->resource['share'],
            /**
             * `earned`; `next` for the one rung of its track you are working towards; or `locked` for the ones past it.
             *
             * @var 'earned'|'next'|'locked'
             *
             * @example next
             */
            'state' => $this->resource['state'],
            /**
             * The medal's name. `null` on a `locked` medal, which stays a silhouette: only the `next` one is named.
             *
             * @var string|null
             *
             * @example 50 transactions
             */
            'name' => $this->resource['name'],
            /**
             * The medal's pictogram, a Lucide icon name; `null` on a `locked` medal.
             *
             * @var string|null
             *
             * @example ListChecks
             */
            'icon' => $this->resource['icon'],
            /**
             * The milestone the medal stands for: its `type` (`money`, `count`, `percent`, `months`, `weeks` or `days`), its `value` (in minor units of `currency` for money) and that `currency`, `null` for the other types. `null` on a `locked` medal and on the ones that are an event with no number to them.
             *
             * @var array{type: string, value: int|float, currency: string|null}|null
             *
             * @example {"type": "count", "value": 50, "currency": null}
             */
            'figure' => $this->resource['figure'],
            /**
             * On an `earned` medal, what you had reached on the day, shaped like `figure`; `null` otherwise.
             *
             * @var array{type: string, value: int|float, currency: string|null}|null
             *
             * @example null
             */
            'reached' => $this->resource['reached'],
            /**
             * The day the medal was earned, or `null`.
             *
             * @var string|null
             *
             * @format date
             *
             * @example null
             */
            'achieved_on' => $this->resource['achieved_on'],
            /**
             * On the `next` medal of the tracks whose figure is cheap to read, how far along you are: `now` against `goal`, and `unlocking` once you are past the goal but the medal still waits for the nightly check that awards it. `null` otherwise.
             *
             * @var array{now: int, goal: int|float, unlocking: bool}|null
             *
             * @example {"now": 23, "goal": 50, "unlocking": false}
             */
            'progress' => $this->resource['progress'],
        ];
    }
}
