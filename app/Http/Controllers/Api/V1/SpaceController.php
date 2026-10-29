<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\SpaceResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Spaces', 'A space holds a set of accounts and everything recorded on them. Every user has a personal space and may be invited to others. Endpoints that work on one space take an optional `space` parameter, which defaults to the personal space.', weight: 10)]
class SpaceController extends Controller
{
    /**
     * List spaces
     *
     * Every space you can access, your personal space first. You belong to a
     * handful of spaces at most, so the list is returned whole, without
     * pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SpaceResource::collection($this->user($request)->accessibleSpaces());
    }
}
