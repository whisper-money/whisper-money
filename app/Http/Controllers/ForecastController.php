<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forecast\ShowForecastRequest;
use App\Models\ForecastItem;
use App\Services\Forecast\ForecastPresenter;
use App\Services\Forecast\ForecastReviewPresenter;
use App\Services\Forecast\ForecastSuggestionCount;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash-flow forecast ("Previsión") of one account, or of every account
 * together, behind the Forecast flag. Users without Pro reach it too, and
 * see the next month only.
 *
 * `reviewPrompt` says whether the user has any line yet, which opens the
 * review on its own the first time. How many suggestions wait for an answer
 * (`newSuggestionCount`) comes right after the page, since working it out
 * when it is not cached reads 18 months of history; the review itself
 * (`review`) is only sent when the page asks for it.
 */
class ForecastController extends Controller
{
    public function index(ShowForecastRequest $request, ForecastPresenter $presenter, ForecastReviewPresenter $review, ForecastSuggestionCount $count): Response
    {
        $user = $request->user();
        $spaceId = $user->activeSpace()->id;

        return Inertia::render('forecast/index', [
            ...$presenter->present($user, $spaceId, $request->account(), $request->horizon()),
            'reviewPrompt' => [
                'hasLines' => ForecastItem::query()->where('user_id', $user->id)->forSpace($spaceId)->notArchived()->exists(),
            ],
            'newSuggestionCount' => Inertia::defer(fn (): int => $count->for($user, $spaceId)),
            'review' => Inertia::optional(fn (): array => $review->present($user, $spaceId)),
        ]);
    }
}
