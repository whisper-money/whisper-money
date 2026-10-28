<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\DeleteLabelRequest;
use App\Http\Requests\Api\V1\StoreLabelRequest;
use App\Http\Requests\Api\V1\UpdateLabelRequest;
use App\Http\Resources\V1\LabelResource;
use App\Models\Label;
use App\Services\Labels\LabelWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Labels', 'A label is a free tag: a transaction can carry any number of them next to its single category. Labels are also how a savings goal counts its contributions, and budgets and automation rules can use them. Names are unique within a space.', weight: 60)]
class LabelController extends Controller
{
    public function __construct(private LabelWriter $writer) {}

    /**
     * List labels
     *
     * The labels in a space, newest first, the ones that track a savings goal
     * included. Every member of a space sees all of its labels.
     */
    #[ScopedToSpace]
    #[Paginated(LabelResource::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            Label::query()->forSpace($this->space($request)),
            $request,
            [['created_at', 'desc'], ['id', 'desc']],
            LabelResource::class,
        );
    }

    /**
     * Retrieve a label
     *
     * @param  string  $label  The label's id.
     */
    #[ScopedToSpace]
    public function show(Request $request, string $label): LabelResource
    {
        return new LabelResource($this->inSpace($request, Label::class, $label));
    }

    /**
     * Create a label
     *
     * Tag transactions with it through `PUT /api/v1/transactions/{id}/labels`.
     */
    #[ScopedToSpace]
    public function store(StoreLabelRequest $request): JsonResponse
    {
        $label = $this->writer->create(
            $this->user($request),
            $this->space($request),
            $request->validated('name'),
            $request->validated('color'),
        );

        // Refreshed so the source the database defaults comes back filled in.
        return (new LabelResource($label->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update a label
     *
     * Changes only the fields you send. Only the member of the space who
     * created the label can change it. The label of a savings goal
     * (`source: saving_goal`) carries the goal's name, so renaming it is a
     * `422`; its color can still be changed.
     *
     * @param  string  $label  The label's id.
     */
    #[ScopedToSpace]
    public function update(UpdateLabelRequest $request, string $label): LabelResource
    {
        return new LabelResource($this->writer->update($request->label(), $this->space($request), $request->validated()));
    }

    /**
     * Delete a label
     *
     * Deletes the label; the transactions that carried it no longer show it.
     * Only the member of the space who created it can delete it. The label of
     * a savings goal (`source: saving_goal`) is how the goal counts its
     * contributions, so it cannot be deleted on its own: that answers `422`
     * with the error under `label`.
     *
     * @param  string  $label  The label's id.
     */
    #[ScopedToSpace]
    public function destroy(DeleteLabelRequest $request, string $label): JsonResponse
    {
        $model = $request->label();
        $model->delete();

        return response()->json([
            /**
             * The deleted label's id.
             *
             * @format uuid
             */
            'id' => $model->id,
            /**
             * Always `true`.
             */
            'deleted' => true,
        ]);
    }
}
