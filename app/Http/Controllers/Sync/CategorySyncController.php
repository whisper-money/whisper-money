<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategorySyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->where('user_id', $request->user()->id);

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $categories = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $categories,
        ]);
    }
}
