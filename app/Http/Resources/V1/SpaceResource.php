<?php

namespace App\Http\Resources\V1;

use App\Models\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Space
 */
class SpaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'personal' => $this->personal,
            'owned' => $this->owner_id === $request->user()?->getAuthIdentifier(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
