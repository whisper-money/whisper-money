<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\Listings;
use App\Http\Requests\Api\V1\DeleteLabelRequest;
use App\Http\Requests\Api\V1\StoreLabelRequest;
use App\Http\Requests\Api\V1\UpdateLabelRequest;
use App\Http\Resources\V1\LabelResource;
use App\Models\Label;
use App\Services\Labels\LabelWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LabelController extends Controller
{
    public function __construct(private LabelWriter $writer) {}

    /**
     * List labels
     *
     * Your labels in a space, newest first, the ones that track a savings goal
     * included. As in the app, another member's labels are not listed.
     */
    public function index(Request $request, Listings $listings): AnonymousResourceCollection
    {
        return $listings->labels($this->user($request), $this->space($request))->paginate($request);
    }

    /**
     * Retrieve a label
     *
     * @param  string  $label  The label's id.
     */
    public function show(Request $request, string $label): LabelResource
    {
        return new LabelResource($this->inSpace($request, Label::class, $label));
    }

    /**
     * Create a label
     */
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
     * Changes only the fields you send. The label of a savings goal
     * (`source: saving_goal`) carries the goal's name, so renaming it is a
     * `422`; its color can still be changed.
     *
     * @param  string  $label  The label's id.
     */
    public function update(UpdateLabelRequest $request, string $label): LabelResource
    {
        return new LabelResource($this->writer->update($request->label(), $this->space($request), $request->validated()));
    }

    /**
     * Delete a label
     *
     * Deletes the label; the transactions that carried it no longer show it.
     * The label of a savings goal (`source: saving_goal`) is how the goal counts its
     * contributions, so it cannot be deleted on its own: that answers `422`
     * with the error under `label`.
     *
     * @param  string  $label  The label's id.
     */
    public function destroy(DeleteLabelRequest $request, string $label): JsonResponse
    {
        $model = $request->label();
        $model->delete();

        return response()->json([
            'id' => $model->id,
            'deleted' => true,
        ]);
    }
}
