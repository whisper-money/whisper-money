<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiConsentController extends Controller
{
    /**
     * Record the user's broad "use AI to help understand my finances" consent.
     */
    public function store(Request $request): JsonResponse
    {
        $request->user()->recordAiConsent();

        return response()->json(['consented' => true]);
    }

    /**
     * Revoke the user's AI consent.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->revokeAiConsent();

        return response()->json(['consented' => false]);
    }
}
