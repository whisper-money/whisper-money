<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\SpaceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpaceController extends Controller
{
    /**
     * Every space the user can access, personal first. A user belongs to a
     * handful of spaces at most, so the list is returned whole as `{data}`
     * rather than cursor-paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SpaceResource::collection($this->user($request)->accessibleSpaces());
    }
}
