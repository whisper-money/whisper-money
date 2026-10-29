<?php

namespace App\Http\Resources\V1;

use App\Models\Label;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A label: a free tag a transaction can carry any number of, next to its
 * single category.
 *
 * @mixin Label
 */
#[SchemaName('Label')]
class LabelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The label's id.
             *
             * @format uuid
             *
             * @example 0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23
             */
            'id' => $this->id,
            /**
             * The id of the space the label is in.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'space_id' => $this->space_id,
            /**
             * The label's name, unique within the space.
             *
             * @example Holidays
             */
            'name' => $this->name,
            /**
             * The label's color.
             *
             * @example blue
             */
            'color' => $this->color,
            /**
             * Where the label comes from: `user` for one made by hand, `saving_goal` for the one a savings goal tracks its contributions with.
             *
             * @example user
             */
            'source' => $this->source->value,
            /**
             * When the label was created.
             *
             * @format date-time
             *
             * @example 2026-01-15T09:30:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
