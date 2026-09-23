<?php

namespace App\Services\Ai;

use App\Enums\CategoryCashflowDirection;
use App\Exceptions\Ai\TransientCategorizationException;
use App\Models\Transaction;
use App\Services\Ai\Contracts\CategorizationBackend;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TypeSafe AI's Jev decision model. Jev takes a single `state` per request, so
 * each transaction is its own request, sent concurrently. Its choices are
 * limited to the categories of the transaction's direction, which both keeps
 * the model from crossing spending and income and trims the billed criteria.
 *
 * A rate-limited (429), overloaded (529 or any other 5xx) or unreachable
 * request drops only its own transaction and surfaces as a
 * {@see TransientCategorizationException} carrying the rest; any other failed
 * request (401, 422) is reported and dropped.
 */
class JevCategorizationBackend implements CategorizationBackend
{
    private const string ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const string NO_CATEGORY = 'none';

    public function categorize(Collection $chunk, CategoryCatalog $catalog): array
    {
        $transactions = $chunk->values();

        $responses = Http::pool(fn (Pool $pool): array => $transactions
            ->map(fn (Transaction $transaction) => $pool->withToken((string) config('services.typesafe.key'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(30)
                ->post(self::ENDPOINT, $this->body($transaction, $catalog)))
            ->all());

        $results = [];
        $transientFailures = 0;

        foreach ($transactions as $position => $transaction) {
            $response = $responses[$position];

            if ($this->isTransient($response)) {
                $transientFailures++;

                continue;
            }

            if (! $response instanceof Response || $response->failed()) {
                report($response instanceof Throwable ? $response : $response->toException());

                continue;
            }

            $results[] = $this->result($transaction, $response);
        }

        if ($transientFailures > 0) {
            throw new TransientCategorizationException(
                "Jev dropped {$transientFailures} of {$transactions->count()} requests.",
                $results,
            );
        }

        return $results;
    }

    public function model(): string
    {
        return (string) config('services.typesafe.model');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Transaction $transaction, CategoryCatalog $catalog): array
    {
        $direction = $transaction->amount < 0 ? CategoryCashflowDirection::Outflow : CategoryCashflowDirection::Inflow;

        return [
            'model' => $this->model(),
            'state' => [
                'text' => (string) $transaction->description,
                'amount' => Money::toMajor($transaction->amount, $transaction->currency_code),
                'direction' => $direction->value,
                'creditor_name' => $transaction->creditor_name,
                'debtor_name' => $transaction->debtor_name,
            ],
            'questions' => [
                'category' => [
                    'type' => 'choice',
                    'instructions' => 'Pick the category this bank transaction of a personal-finance app user belongs to. Choose "none" if no category fits; do not guess.',
                    'criteria' => $this->criteria($catalog, $direction),
                ],
                'merchant_unambiguous' => [
                    'type' => 'noul',
                    'instructions' => 'Does this counterparty/merchant reliably map to the chosen category for every future transaction (e.g. "Netflix" is always Subscriptions, "Mercadona" always Groceries)? It does not when the right category depends on the specific purchase (e.g. "Amazon", "PayPal", a generic bank transfer, an ATM withdrawal).',
                ],
            ],
        ];
    }

    /**
     * Categories the transaction can land in, keyed by catalog index: those of
     * its own direction plus the hidden ones (transfers and the like), which
     * move money either way.
     *
     * @return array<string, string>
     */
    private function criteria(CategoryCatalog $catalog, CategoryCashflowDirection $direction): array
    {
        $criteria = [];

        foreach ($catalog->options() as $option) {
            if (in_array($option['direction'], [$direction->value, CategoryCashflowDirection::Hidden->value], true)) {
                $criteria[(string) $option['index']] = $option['path'];
            }
        }

        $criteria[self::NO_CATEGORY] = 'No category fits';

        return $criteria;
    }

    /**
     * @return array{ref: string, category_index?: int, confidence: float, merchant_unambiguous: bool}
     */
    private function result(Transaction $transaction, Response $response): array
    {
        $category = $response->json('answers.category');
        $result = [
            'ref' => $transaction->id,
            'confidence' => (float) ($category['confidence'] ?? 0.0),
            'merchant_unambiguous' => (float) $response->json('answers.merchant_unambiguous.noul', 0.0)
                >= (float) config('ai_categorization.jev_unambiguous_threshold'),
        ];

        $choice = $category['choice'] ?? self::NO_CATEGORY;

        if (is_numeric($choice)) {
            $result['category_index'] = (int) $choice;
        }

        return $result;
    }

    private function isTransient(mixed $response): bool
    {
        return $response instanceof ConnectionException
            || ($response instanceof Response && ($response->tooManyRequests() || $response->serverError()));
    }
}
