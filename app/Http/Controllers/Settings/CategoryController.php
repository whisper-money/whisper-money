<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCategoryRequest;
use App\Http\Requests\Settings\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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
            ->get(['id', 'name', 'icon', 'color']);

        return Inertia::render('settings/categories', [
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        auth()->user()->categories()->create($request->validated());

        return to_route('categories.index');
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

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
}
