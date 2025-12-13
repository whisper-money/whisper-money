<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Label;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Label::query()->where('user_id', $request->user()->id);

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $labels = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $labels,
        ]);
    }
}
