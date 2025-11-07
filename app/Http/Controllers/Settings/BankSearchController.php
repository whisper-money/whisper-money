<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankSearchController extends Controller
{
    /**
     * Search banks by query.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->input('query', '');

        if (strlen($query) < 3) {
            return response()->json([
                'banks' => [],
                'message' => 'Type at least 3 characters to search',
            ]);
        }

        $banks = Bank::query()
            ->where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id)
                    ->orWhereNull('user_id');
            })
            ->where('name', 'like', '%'.$query.'%')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'logo']);

        return response()->json([
            'banks' => $banks,
        ]);
    }
}
