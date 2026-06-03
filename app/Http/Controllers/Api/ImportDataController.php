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
                ->with('bank:id,name,logo')
                ->orderBy('name')
                ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code']),
            'categories' => $user->categories()
                ->forDisplay()
                ->get(),
            'banks' => $user->banks()
                ->orderBy('name')
                ->get(['id', 'name', 'logo']),
            'automationRules' => $user->automationRules()
                ->with(['category:id,name,icon,color', 'labels:id,name,color'])
                ->orderBy('priority')
                ->get(),
        ]);
    }
}
