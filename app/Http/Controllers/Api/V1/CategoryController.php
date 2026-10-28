<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\DeleteCategoryRequest;
use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Requests\Api\V1\UpdateCategoryRequest;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use App\Services\Categories\CategoryWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Categories', 'A category says what a transaction was for. Each transaction has at most one. Categories form a tree up to 3 levels deep through `parent_id`, and a whole tree shares its root\'s type and cashflow direction. A budget that tracks a parent category also tracks its children.', weight: 50)]
class CategoryController extends Controller
{
    public function __construct(private CategoryWriter $writer) {}

    /**
     * List categories
     *
     * The categories in a space, newest first, roots and children alike: rebuild
     * the tree from `parent_id`. Every member of a space sees all of its
     * categories.
     */
    #[ScopedToSpace]
    #[Paginated(CategoryResource::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            Category::query()->forSpace($this->space($request)),
            $request,
            [['created_at', 'desc'], ['id', 'desc']],
            CategoryResource::class,
        );
    }

    /**
     * Retrieve a category
     *
     * @param  string  $category  The category's id.
     */
    #[ScopedToSpace]
    public function show(Request $request, string $category): CategoryResource
    {
        return new CategoryResource($this->inSpace($request, Category::class, $category));
    }

    /**
     * Create a category
     *
     * A root category takes the `type` you send, and its cashflow direction
     * follows from it: `savings` and `investment` count as an outflow, a
     * `transfer` keeps the `cashflow_direction` you send (`hidden` when you
     * send none) and the rest are left out of the cashflow. A child, created
     * with a `parent_id`, takes its parent's type and direction whatever you
     * send.
     */
    #[ScopedToSpace]
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $space = $this->space($request);
        $validated = $request->validated();
        $parentId = $validated['parent_id'] ?? null;

        $category = $this->writer->create(
            $this->user($request),
            $space,
            $validated,
            $parentId === null ? null : $this->inSpace($request, Category::class, $parentId),
        );

        return (new CategoryResource($category->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update a category
     *
     * Changes only the fields you send. Only the member of the space who
     * created the category can change it. Moving it under another parent,
     * or making it a root with `parent_id: null`, re-derives its type and
     * cashflow direction as on create, and its whole subtree follows: its
     * children and their children take its new type and direction. While it
     * has a parent, `type` and `cashflow_direction` are ignored.
     *
     * @param  string  $category  The category's id.
     */
    #[ScopedToSpace]
    public function update(UpdateCategoryRequest $request, string $category): CategoryResource
    {
        $validated = $request->validated();
        $parentId = $validated['parent_id'] ?? null;

        return new CategoryResource($this->writer->update(
            $request->category(),
            $this->space($request),
            $validated,
            $parentId === null ? null : $this->inSpace($request, Category::class, $parentId),
        ));
    }

    /**
     * Delete a category
     *
     * Deletes the category and takes it off its transactions, which are left
     * uncategorized. Only the member of the space who created it can delete
     * it. `strategy` decides what happens to its children; when moving them
     * would leave two categories with the same name at one level, nothing is
     * deleted and the answer is a `422` under `strategy`: rename one first.
     *
     * @param  string  $category  The category's id.
     */
    #[ScopedToSpace]
    public function destroy(DeleteCategoryRequest $request, string $category): JsonResponse
    {
        $model = $request->category();
        $strategy = $request->strategy();

        $this->writer->delete($model, $strategy);

        return response()->json([
            /**
             * The deleted category's id.
             *
             * @format uuid
             */
            'id' => $model->id,
            /**
             * Always `true`.
             */
            'deleted' => true,
            /**
             * The strategy that was applied to the children.
             *
             * @var 'reparent'|'promote'|'cascade'
             */
            'strategy' => $strategy->value,
        ]);
    }
}
