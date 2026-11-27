<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forecast\ShowForecastRequest;
use App\Models\ForecastItem;
use App\Models\ForecastReviewCompletion;
use App\Services\Forecast\ForecastPresenter;
use App\Services\Forecast\ForecastReviewPresenter;
use App\Services\Forecast\ForecastSuggestionCount;
use App\Services\Forecast\RecurringChargesPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash-flow forecast ("Previsión") of one account, or of every account
 * together, behind the Forecast flag. Users without Pro reach it too, and
 * see the next month only.
 *
 * `reviewPrompt` says whether the user has any line yet and whether they
 * answered the review in this space: with neither, the review opens on its
 * own. How many suggestions wait for an answer
 * (`newSuggestionCount`) comes right after the page, since working it out
 * when it is not cached reads 18 months of history; the review itself
 * (`review`), always of every included account whichever one the page
 * shows, is only sent when the page asks for it. The "Recurring
 * transactions" card (`recurringCharges`) runs the recurring detector live,
 * so it comes after the page too, on its own.
 */
class ForecastController extends Controller
{
    public function index(ShowForecastRequest $request, ForecastPresenter $presenter, ForecastReviewPresenter $review, ForecastSuggestionCount $count, RecurringChargesPresenter $recurring): Response
    {
        $user = $request->user();
        $spaceId = $user->activeSpace()->id;

        $page = $presenter->present($user, $spaceId, $request->account(), $request->horizon());

        return Inertia::render('forecast/index', [
            ...$page,
            'reviewPrompt' => [
                'hasLines' => ForecastItem::query()->where('user_id', $user->id)->forSpace($spaceId)->notArchived()->exists(),
                'reviewed' => ForecastReviewCompletion::query()->where('user_id', $user->id)->forSpace($spaceId)->exists(),
            ],
            'newSuggestionCount' => Inertia::defer(fn (): int => $count->for($user, $spaceId)),
            'recurringCharges' => Inertia::defer(fn (): array => $recurring->present($user, $spaceId), 'recurring-charges'),
            'review' => Inertia::optional(fn (): array => $review->present($user, $spaceId)),
        ]);
    }
}
