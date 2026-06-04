<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CategoryDeletionStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeleteCategoryRequest;
use App\Http\Requests\Settings\StoreCategoryRequest;
use App\Http\Requests\Settings\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryTree;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CategoryTree $tree = new CategoryTree) {}

    /**
     * Show the user's categories settings page.
     */
    public function index(): Response
    {
        $categories = auth()->user()
            ->categories()
            ->forDisplay()
            ->get();

        return Inertia::render('settings/categories', [
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        try {
            auth()->user()->categories()->create($request->validated());
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwDuplicateCategoryNameValidationException($exception);
        }

        return to_route('categories.index');
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        try {
            $category->update($request->validated());
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwDuplicateCategoryNameValidationException($exception);
        }

        $this->tree->syncDescendantTypes($category);

        return to_route('categories.index');
    }

    /**
     * Soft delete the specified category, handling its children according to
     * the chosen strategy.
     */
    public function destroy(DeleteCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        match ($request->strategy()) {
            CategoryDeletionStrategy::Cascade => $this->tree->deleteSubtree($category),
            CategoryDeletionStrategy::Promote => $this->detachChildrenAndDelete($category, null),
            CategoryDeletionStrategy::Reparent => $this->detachChildrenAndDelete($category, $category->parent_id),
        };

        return to_route('categories.index');
    }

    /**
     * Move the category's direct children to a new parent, then soft delete it.
     */
    private function detachChildrenAndDelete(Category $category, ?string $newParentId): void
    {
        try {
            $category->children()->update(['parent_id' => $newParentId]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'strategy' => __('A category with the same name already exists at the destination level. Rename it first.'),
            ]);
        }

        $category->delete();
    }

    private function throwDuplicateCategoryNameValidationException(UniqueConstraintViolationException $exception): never
    {
        if (! str_contains($exception->getMessage(), 'categories_user_id_name_unique')
            && ! str_contains($exception->getMessage(), 'categories_user_id_name_active_unique')
            && ! str_contains($exception->getMessage(), 'categories_user_id_parent_name_active_unique')) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'name' => __('validation.unique', ['attribute' => 'name']),
        ]);
    }
}
