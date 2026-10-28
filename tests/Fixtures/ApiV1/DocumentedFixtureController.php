<?php

namespace Tests\Fixtures\ApiV1;

use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\SpaceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Endpoints shaped like the ones later resources add, mounted only by the
 * tests of the spec conventions (OperationConventions).
 */
class DocumentedFixtureController extends Controller
{
    /**
     * List fixtures
     */
    #[Paginated(SpaceResource::class)]
    #[ScopedToSpace]
    public function index(Request $request): AnonymousResourceCollection
    {
        return SpaceResource::collection([]);
    }

    /**
     * Create a fixture
     */
    #[ScopedToSpace]
    public function store(Request $request): JsonResponse
    {
        return new JsonResponse(['ok' => true], 201);
    }

    /**
     * Delete a fixture
     */
    public function destroy(string $fixture): JsonResponse
    {
        return new JsonResponse(null, 204);
    }
}
