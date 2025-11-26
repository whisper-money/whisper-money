<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Account::query()
            ->with('bank')
            ->where('user_id', $request->user()->id);

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $accounts = $query->orderBy('updated_at', 'asc')->get();

        return response()->json([
            'data' => $accounts,
        ]);
    }
}
