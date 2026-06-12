<?php

namespace App\Http\Controllers\Ai;

use App\Features\AiRuleSuggestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class AiConsentController extends Controller
{
    /**
     * Record the user's broad "use AI to help understand my finances" consent.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(Feature::for($user)->active(AiRuleSuggestions::class), 403);

        $user->recordAiConsent();

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
