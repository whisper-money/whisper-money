<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One track of medals: a ladder of milestones of the same kind.
 *
 * @property array{key: string, label: string, note: ?string, unlocked: int, medals: list<array<string, mixed>>} $resource
 */
#[SchemaName('AchievementTrack')]
class AchievementTrackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The track's key.
             *
             * @var string
             *
             * @example transactions
             */
            'key' => $this->resource['key'],
            /**
             * The track's name.
             *
             * @var string
             *
             * @example Transactions recorded
             */
            'label' => $this->resource['label'],
            /**
             * What the track has to explain about itself, or `null`.
             *
             * @var string|null
             *
             * @example null
             */
            'note' => $this->resource['note'],
            /**
             * How many of its medals you have earned.
             *
             * @var int
             *
             * @example 1
             */
            'unlocked' => $this->resource['unlocked'],
            /**
             * Its medals, from the first rung up.
             */
            'medals' => AchievementResource::collection($this->resource['medals']),
        ];
    }
}
