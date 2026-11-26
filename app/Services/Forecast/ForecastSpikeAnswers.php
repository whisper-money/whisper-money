<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastExclusionReason;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastSpikeAnswer;
use App\Models\ForecastExcludedTransaction;
use App\Models\Transaction;
use App\Services\Forecast\Data\ForecastCandidate;

/**
 * Records the user's answer about a spike (see SpikeDetector). A one-off is
 * left out of the estimated spend from then on. A charge that repeats is
 * not left out, and comes back as the line it would become: yearly from the
 * charge, for the user to adjust and confirm. Nothing is created here; the
 * line is stored when the user confirms it.
 *
 * Changing the answer is allowed: saying it repeats after calling it a one-off
 * puts the charge back into the estimate. Callers check that the transaction
 * is the user's.
 *
 * The spike endpoint of a later phase calls it; until then only the tests do.
 *
 * @api
 */
class ForecastSpikeAnswers
{
    public function __construct(
        private readonly CandidateBuilder $candidates,
        private readonly DescriptionFingerprint $fingerprints,
    ) {}

    public function answer(Transaction $transaction, ForecastSpikeAnswer $answer): ?ForecastCandidate
    {
        if ($answer === ForecastSpikeAnswer::OneOff) {
            ForecastExcludedTransaction::query()->firstOrCreate(
                ['transaction_id' => $transaction->id],
                ['reason' => ForecastExclusionReason::OneOff],
            );

            return null;
        }

        ForecastExcludedTransaction::query()->where('transaction_id', $transaction->id)->delete();
        $transaction->loadMissing('account');
        $fingerprint = $this->fingerprints->ofTransaction($transaction);

        return $this->candidates->build([$transaction], ForecastFrequency::Yearly, ForecastConfidence::Review, $fingerprint !== '' ? $fingerprint : null, []);
    }
}
