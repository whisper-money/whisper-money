<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\StoreForecastSpikeAnswerRequest;
use App\Models\ForecastItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\NewForecastLine;
use App\Services\Forecast\ForecastEditWindow;
use App\Services\Forecast\ForecastItemWriter;
use App\Services\Forecast\ForecastSpikeAnswers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * "¿Fue puntual o se repite?": a one-off leaves the charge out of the
 * estimate, and one that repeats becomes a yearly line from it, unless the
 * user already has that line. Without Pro a line only lands within the
 * months the user sees, like any other.
 */
class ForecastSpikeController extends Controller
{
    public function store(StoreForecastSpikeAnswerRequest $request, Transaction $transaction, ForecastSpikeAnswers $answers, ForecastItemWriter $writer, ForecastEditWindow $window): RedirectResponse
    {
        $candidate = $answers->answer($transaction, $request->answer());

        if ($candidate === null || $this->alreadyPlanned($request->user(), $candidate)) {
            return back();
        }

        if (! $window->allowsNewDate($request->user(), $candidate->nextDate)) {
            throw ValidationException::withMessages(['answer' => __('With the free plan you can plan the next month only.')]);
        }

        $writer->create($request->user(), $request->user()->activeSpace()->id, NewForecastLine::fromCandidate($candidate));

        return back();
    }

    private function alreadyPlanned(User $user, ForecastCandidate $candidate): bool
    {
        return ForecastItem::query()
            ->where('user_id', $user->id)
            ->notArchived()
            ->where('account_id', $candidate->accountId)
            ->where('frequency', $candidate->frequency)
            ->when(
                $candidate->fingerprint !== null,
                fn ($query) => $query->where('fingerprint', $candidate->fingerprint),
                fn ($query) => $query->whereDate('anchor_date', $candidate->nextDate),
            )
            ->exists();
    }
}
