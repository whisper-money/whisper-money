<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ImportDataController extends Controller
{
    /**
     * Get data needed for import operations.
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'accounts' => $user->accounts()
                ->with('bank')
                ->orderBy('name')
                ->get(),
            'categories' => $user->categories()
                ->forDisplay()
                ->get(),
            'banks' => $user->banks()
                ->orderBy('name')
                ->get(),
            'automationRules' => $user->automationRules()
                ->with(['category', 'labels'])
                ->orderBy('priority')
                ->get(),
        ]);
    }
}
