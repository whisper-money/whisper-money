<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\Money;

/**
 * Turns transactions into the rows of a CSV export.
 *
 * The header stays in English whatever the user's language: the file is meant
 * to be read by spreadsheets and other apps, and a stable header is what makes
 * it importable again.
 */
class TransactionCsvExporter
{
    /** Relations a row reads, for the caller to eager load. */
    public const RELATIONS = ['account.bank', 'category', 'labels'];

    /** @var list<string> */
    public const HEADER = [
        'Date',
        'Description',
        'Amount',
        'Currency',
        'Account',
        'Bank',
        'Category',
        'Labels',
        'Notes',
        'Creditor',
        'Debtor',
    ];

    public function __construct(private readonly CurrencyOptions $currencies) {}

    /**
     * @return list<string>
     */
    public function row(Transaction $transaction): array
    {
        $currency = $transaction->currency_code;

        return [
            $transaction->transaction_date->format('Y-m-d'),
            $this->text($transaction->description),
            // A plain decimal at the currency's own scale: no symbol and no
            // grouping, so every spreadsheet locale reads it as a number.
            number_format(Money::toMajor($transaction->amount, $currency), $this->currencies->decimals($currency), '.', ''),
            $currency,
            $this->text($transaction->account?->name),
            $this->text($transaction->account?->bank?->name),
            $this->text($transaction->category?->name),
            $this->text($transaction->labels->pluck('name')->join(', ')),
            $this->text($transaction->notes),
            $this->text($transaction->creditor_name),
            $this->text($transaction->debtor_name),
        ];
    }

    /**
     * A spreadsheet runs a cell that starts with one of these as a formula, and
     * descriptions come straight from the bank. A leading quote keeps it text.
     */
    private function text(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
