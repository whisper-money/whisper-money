<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Http\ApiV1\ApiV1Exception;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use App\Services\SpaceResolver;

/**
 * For the `/api/v1/categories/{category}` and `/api/v1/labels/{label}` write
 * requests: the object is looked up in the request's space, so one in
 * another space, or someone else's, is a 404 before anything is validated.
 * Every member of the space reads its categories and labels, but only the one
 * who created one may change or delete it (CategoryPolicy and LabelPolicy, as
 * in the app).
 */
trait ResolvesOwnedRouteModel
{
    private Category|Label|null $routeModel = null;

    /**
     * The model the route points at and its route parameter, which also names
     * it in the refusal: `[Category::class, 'category']`.
     *
     * @return array{0: class-string<Category|Label>, 1: string}
     */
    abstract protected function routeModelBinding(): array;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can($this->isMethod('DELETE') ? 'delete' : 'update', $this->routeModel());
    }

    protected function routeModel(): Category|Label
    {
        [$model, $parameter] = $this->routeModelBinding();
        $resolver = app(SpaceResolver::class);

        return $this->routeModel ??= $resolver->inSpaceOrFail($model, $resolver->forApiRequest($this), (string) $this->route($parameter));
    }

    protected function failedAuthorization(): never
    {
        [, $noun] = $this->routeModelBinding();

        throw new ApiV1Exception(403, "not_{$noun}_owner", ucfirst($noun)." {$this->routeModel()->id} belongs to another member of the space, so only they can change or delete it. You can still read it.");
    }
}
