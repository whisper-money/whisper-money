<?php

namespace App\Http\Resources\V1;

use App\Models\Space;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A space: a set of accounts and everything recorded on them, owned by one
 * user and shared with the members they invite.
 *
 * @mixin Space
 */
#[SchemaName('Space')]
class SpaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The space's id, which the `space` parameter takes.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'id' => $this->id,
            /**
             * The space's name.
             *
             * @example Personal
             */
            'name' => $this->name,
            /**
             * Whether this is your personal space, the default when `space` is left out.
             */
            'personal' => $this->personal,
            /**
             * Whether you own the space, rather than being a member of it.
             */
            'owned' => $this->owner_id === $request->user()?->getAuthIdentifier(),
            /**
             * When the space was created.
             *
             * @format date-time
             *
             * @example 2026-01-15T09:30:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
