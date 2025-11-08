<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankSearchController extends Controller
{
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
            ->orderByRaw('
                CASE
                    WHEN LOWER(name) = LOWER(?) THEN 1
                    WHEN LOWER(name) LIKE LOWER(?) THEN 2
                    ELSE 3
                END,
                name
            ', [$query, $query.'%'])
            ->limit(100)
            ->get(['id', 'name', 'logo']);

        return response()->json([
            'banks' => $banks,
        ]);
    }
}
