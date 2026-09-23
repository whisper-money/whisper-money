<?php

namespace App\Services\Ai;

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Models\Transaction;
use App\Services\Ai\Contracts\CategorizationBackend;
use App\Support\Money;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;

/**
 * The default backend: the whole chunk in one structured-output prompt to the
 * configured laravel/ai provider (Gemini unless overridden).
 */
class GeminiCategorizationBackend implements CategorizationBackend
{
    public function categorize(Collection $chunk, CategoryCatalog $catalog): array
    {
        $items = $chunk->map(fn (Transaction $transaction): array => [
            'ref' => $transaction->id,
            'text' => (string) $transaction->description,
            'amount' => Money::toMajor($transaction->amount, $transaction->currency_code),
            'direction' => $transaction->amount < 0 ? 'outflow' : 'inflow',
            'creditor_name' => $transaction->creditor_name,
            'debtor_name' => $transaction->debtor_name,
        ])->values()->all();

        $payload = json_encode([
            'transactions' => $items,
            'categories' => $catalog->options(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = (new TransactionCategorizationAgent)->prompt(
            $payload,
            provider: Lab::from((string) config('ai_categorization.provider')),
            model: $this->model(),
        );

        $results = $response['results'] ?? [];

        return is_array($results) ? array_values($results) : [];
    }

    public function model(): string
    {
        return (string) config('ai_categorization.model');
    }
}
