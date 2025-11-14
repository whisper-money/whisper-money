<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bank::query()->where(function ($q) use ($request) {
            $q->whereNull('user_id')
                ->orWhere('user_id', $request->user()->id);
        });

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $banks = $query->orderBy('updated_at', 'asc')->get();

        return response()->json([
            'data' => $banks,
        ]);
    }
}

