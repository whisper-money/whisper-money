<?php

namespace Database\Factories;

use App\Enums\ImportConfigType;
use App\Models\Account;
use App\Models\AccountImportConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountImportConfig>
 */
class AccountImportConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'type' => ImportConfigType::Transaction,
            'config' => [
                'columnMapping' => [
                    'transaction_date' => 'Date',
                    'description' => 'Description',
                    'amount' => 'Amount',
                    'balance' => null,
                    'creditor_name' => null,
                    'debtor_name' => null,
                ],
                'dateFormat' => 'YYYY-MM-DD',
            ],
        ];
    }
}
