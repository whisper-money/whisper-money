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
        $space = $user->activeSpace();

        return response()->json([
            'accounts' => $space->accounts()
                ->with('bank')
                ->orderBy('name')
                ->get(),
            'categories' => $space->categories()
                ->forDisplay()
                ->get(),
            'banks' => $user->banks()
                ->orderBy('name')
                ->get(),
            'automationRules' => $space->automationRules()
                ->with(['category', 'labels'])
                ->orderBy('priority')
                ->get(),
        ]);
    }
}
