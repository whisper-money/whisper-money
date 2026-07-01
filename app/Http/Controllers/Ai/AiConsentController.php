<?php

namespace App\Http\Controllers\Ai;

use App\Actions\Ai\StartCategorizationBackfill;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiConsentController extends Controller
{
    /**
     * Record the user's broad "use AI to help understand my finances" consent
     * and kick off a backfill of their uncategorized transactions.
     */
    public function store(Request $request, StartCategorizationBackfill $startBackfill): JsonResponse
    {
        $user = $request->user();
        $user->recordAiConsent();
        $user->dismissAiConsentPrompt();

        return response()->json([
            'consented' => true,
            'categorization' => $startBackfill->handle($user),
        ]);
    }

    /**
     * Permanently dismiss the AI consent prompt without granting consent.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $request->user()->dismissAiConsentPrompt();

        return response()->json(['dismissed' => true]);
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
