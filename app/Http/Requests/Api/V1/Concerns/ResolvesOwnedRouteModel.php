<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use App\Services\SpaceResolver;

/**
 * For the `/api/v1/categories/{category}` and `/api/v1/labels/{label}` write
 * requests: the object is looked up among the caller's own in the request's
 * space, so one in another space, or another member's, is a 404 before
 * anything is validated. CategoryPolicy and LabelPolicy, as in the app, are
 * checked again on it.
 */
trait ResolvesOwnedRouteModel
{
    private Category|Label|null $routeModel = null;

    /**
     * The model the route points at and its route parameter:
     * `[Category::class, 'category']`.
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
        /** @var User $user */
        $user = $this->user();
        $resolver = app(SpaceResolver::class);

        return $this->routeModel ??= $resolver->ownedInSpaceOrFail($model, $user, $resolver->forApiRequest($this), (string) $this->route($parameter));
    }
}
