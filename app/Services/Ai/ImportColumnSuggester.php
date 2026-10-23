<?php

namespace App\Services\Ai;

use App\Enums\ImportConfigType;
use Illuminate\Http\Client\Response;

/**
 * Asks TypeSafe AI's Jev which column of an imported file holds each field.
 * One request per file, one `choice` question per field, whose options are the
 * file's columns plus "none". Jev only ever sees each column's header and its
 * computed shape, never a cell value.
 */
class ImportColumnSuggester
{
    private const string NO_COLUMN = 'none';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(private JevClient $jev) {}

    /**
     * The fields to place, each with its instructions to Jev. Their names are
     * the keys of the client's `ColumnMapping` (resources/js/types/import.ts)
     * and `BalanceColumnMapping` (resources/js/types/balance-import.ts).
     *
     * @return array<string, string>
     */
    private function fields(ImportConfigType $kind): array
    {
        return match ($kind) {
            ImportConfigType::Transaction => [
                'transaction_date' => 'Pick the column holding the date each bank transaction happened. When the file has both a booking/operation date and a value date, prefer the booking/operation date.',
                'description' => 'Pick the column holding the free-text description, concept or reference of each transaction. Not the counterparty name column when a separate description exists.',
                'amount' => 'Pick the column holding the amount of money each transaction moved (signed or unsigned). Not the running balance, a fee or a tax column.',
                'currency' => 'Pick the column holding the currency code or symbol of each transaction amount (e.g. EUR, USD, €).',
                'balance' => 'Pick the column holding the account balance after each transaction (the running balance). Not the transaction amount.',
                'creditor_name' => 'Pick the column holding the name of who received the money (payee, beneficiary, merchant).',
                'debtor_name' => 'Pick the column holding the name of who sent the money (payer, ordering party).',
                'transaction_type' => 'Pick the column saying only whether each transaction is money in or money out (e.g. debit/credit, Af/Bij, in/out, D/C). Not a column describing the kind of operation (card payment, transfer).',
            ],
            ImportConfigType::Balance => [
                'balance_date' => 'Pick the column holding the date each balance was recorded.',
                'balance' => 'Pick the column holding the account balance or market value on that date. Not a contribution or invested amount.',
                'invested_amount' => 'Pick the column holding the amount of money invested or contributed so far (cost basis). Not the current balance or market value.',
            ],
        };
    }

    /**
     * @param  list<array{header: ?string, shape: string}>  $columns
     * @return array<string, array{column: ?string, index: ?int, confidence: float}>|null the header (and position, which tells apart repeated headers) suggested per field, or null when Jev could not answer
     */
    public function suggest(ImportConfigType $kind, array $columns): ?array
    {
        $response = $this->jev->send($this->body($kind, $columns), self::TIMEOUT_SECONDS);

        if ($response === null) {
            return null;
        }

        if ($response->failed()) {
            report($response->toException());

            return null;
        }

        return $this->suggestions($kind, $columns, $response);
    }

    /**
     * @param  list<array{header: ?string, shape: string}>  $columns
     * @return array<string, mixed>
     */
    private function body(ImportConfigType $kind, array $columns): array
    {
        $criteria = [];
        $state = [];

        foreach ($columns as $index => $column) {
            $criteria[(string) $index] = $this->label($column);
            $state[] = ['index' => $index, 'header' => $column['header'], 'shape' => $column['shape']];
        }

        $criteria[self::NO_COLUMN] = 'No column holds this';

        $questions = [];

        foreach ($this->fields($kind) as $field => $instructions) {
            $questions[$field] = [
                'type' => 'choice',
                'instructions' => "These are the columns of a bank export file a personal-finance app user is importing, each with its header and the shape of its values. {$instructions} Choose \"none\" if no column fits; do not guess.",
                'criteria' => $criteria,
            ];
        }

        return [
            'model' => $this->jev->model(),
            'state' => ['columns' => $state],
            'questions' => $questions,
        ];
    }

    /**
     * @param  array{header: ?string, shape: string}  $column
     */
    private function label(array $column): string
    {
        return filled($column['header']) ? (string) $column['header'] : "(unnamed column, {$column['shape']})";
    }

    /**
     * @param  list<array{header: ?string, shape: string}>  $columns
     * @return array<string, array{column: ?string, index: ?int, confidence: float}>
     */
    private function suggestions(ImportConfigType $kind, array $columns, Response $response): array
    {
        $minConfidence = (float) config('ai_import_mapping.min_confidence');
        $suggestions = [];

        foreach (array_keys($this->fields($kind)) as $field) {
            $choice = $response->json("answers.{$field}.choice");
            $confidence = (float) $response->json("answers.{$field}.confidence", 0.0);
            $index = is_numeric($choice) && isset($columns[(int) $choice]) && $confidence >= $minConfidence
                ? (int) $choice
                : null;

            $suggestions[$field] = [
                'column' => $index === null ? null : $columns[$index]['header'],
                'index' => $index,
                'confidence' => $confidence,
            ];
        }

        return $suggestions;
    }
}
