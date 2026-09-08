<?php

namespace App\Http\Controllers;

use App\Features\Achievements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

/**
 * "Not now" on the prompt that asks a reader to categorize the month.
 *
 * A snooze rather than a dismissal: the pile does not go away on its own, and a
 * prompt that can be silenced forever is one nobody ever acts on. Three days is
 * long enough to stop being nagged and short enough that the month still gets
 * tidied before it closes.
 *
 * Kept on the user rather than in the browser, like the AI consent prompt
 * beside it, so saying "not now" on the laptop also says it on the phone.
 */
class UncategorizedPromptController extends Controller
{
    private const SNOOZE_DAYS = 3;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Feature::active(Achievements::class), 404);

        $user = $request->user();
        $user->uncategorized_prompt_snoozed_until = now()->addDays(self::SNOOZE_DAYS);
        $user->save();

        return response()->json([
            'snoozed_until' => $user->uncategorized_prompt_snoozed_until->toIso8601String(),
        ]);
    }
}
