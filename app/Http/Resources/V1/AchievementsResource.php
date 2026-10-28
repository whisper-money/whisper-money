<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Your medals: the whole catalog grouped into tracks, earned or not.
 *
 * @property array{currency: string, overview: array{unlocked: int, total: int, streak: array{months: int, since: ?string}|null, latest: array{name: ?string, achieved_on: string}|null}, tracks: list<array<string, mixed>>} $resource
 */
#[SchemaName('Achievements')]
class AchievementsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource['overview'];

        return [
            /**
             * The currency the money milestones are set in: your account's currency when the medals have levels for it, `USD` otherwise.
             *
             * @example EUR
             */
            'currency' => $this->resource['currency'],
            /**
             * How many medals you have earned.
             *
             * @var int
             *
             * @example 12
             */
            'unlocked' => $overview['unlocked'],
            /**
             * How many medals there are.
             *
             * @var int
             *
             * @example 59
             */
            'total' => $overview['total'],
            /**
             * Your saving streak as it stands: how many months in a row you have saved, from the last monthly report, and the first day of the first of them. It is a live figure, not a medal, and it can break. `null` with no streak running.
             *
             * @var array{months: int, since: string}|null
             *
             * @example {"months": 3, "since": "2026-07-01"}
             */
            'streak' => $overview['streak'],
            /**
             * The medal you earned last, or `null` with none.
             *
             * @var array{name: string|null, achieved_on: string}|null
             *
             * @example {"name": "First transaction", "achieved_on": "2026-03-02"}
             */
            'latest' => $overview['latest'],
            /**
             * The tracks, each a ladder of medals.
             */
            'tracks' => AchievementTrackResource::collection($this->resource['tracks']),
        ];
    }
}
