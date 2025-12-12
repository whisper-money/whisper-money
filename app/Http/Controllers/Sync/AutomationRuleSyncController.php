<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AutomationRuleSyncController extends Controller
{
    /**
     * Get all automation rules for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $rules = auth()->user()
            ->automationRules()
            ->with(['category:id,name,icon,color', 'labels:id,name,color'])
            ->orderBy('priority')
            ->get();

        return response()->json($rules);
    }
}
