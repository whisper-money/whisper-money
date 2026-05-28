<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCategoryRequest;
use App\Http\Requests\Settings\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the user's categories settings page.
     */
    public function index(): Response
    {
        $categories = auth()->user()
            ->categories()
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color', 'type', 'cashflow_direction']);

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

        return to_route('categories.index');
    }

    /**
     * Soft delete the specified category.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        return to_route('categories.index');
    }

    private function throwDuplicateCategoryNameValidationException(UniqueConstraintViolationException $exception): never
    {
        if (! str_contains($exception->getMessage(), 'categories_user_id_name_unique')
            && ! str_contains($exception->getMessage(), 'categories_user_id_name_active_unique')) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'name' => __('validation.unique', ['attribute' => 'name']),
        ]);
    }
}
