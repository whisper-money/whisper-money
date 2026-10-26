<?php

use App\Models\Account;
use App\Models\AccountImportConfig;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

$transactionConfig = [
    'columnMapping' => [
        'transaction_date' => 'Date',
        'description' => 'Concept',
        'amount' => 'Amount',
        'balance' => null,
        'creditor_name' => null,
        'debtor_name' => null,
    ],
    'dateFormat' => 'DD-MM-YYYY',
];

test('import config endpoints require authentication', function () {
    auth()->logout();

    $this->getJson("/api/accounts/{$this->account->id}/import-config?type=transaction")
        ->assertUnauthorized();
});

test('returns null when no config is saved yet', function () {
    $this->getJson("/api/accounts/{$this->account->id}/import-config?type=transaction")
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('saves and returns an import config for an account', function () use ($transactionConfig) {
    $this->putJson("/api/accounts/{$this->account->id}/import-config", [
        'type' => 'transaction',
        'config' => $transactionConfig,
    ])
        ->assertOk()
        ->assertJsonPath('data.dateFormat', 'DD-MM-YYYY')
        ->assertJsonPath('data.columnMapping.description', 'Concept');

    $this->assertDatabaseHas('account_import_configs', [
        'account_id' => $this->account->id,
        'type' => 'transaction',
    ]);

    $this->getJson("/api/accounts/{$this->account->id}/import-config?type=transaction")
        ->assertOk()
        ->assertJsonPath('data.dateFormat', 'DD-MM-YYYY');
});

test('keeps the type column and its expense values in the saved mapping', function () use ($transactionConfig) {
    $config = $transactionConfig;
    $config['columnMapping']['transaction_type'] = 'Tipo de transacción';
    $config['columnMapping']['expense_types'] = ['Gasto', 'Ajuste de saldo'];

    $this->putJson("/api/accounts/{$this->account->id}/import-config", [
        'type' => 'transaction',
        'config' => $config,
    ])->assertOk();

    $this->getJson("/api/accounts/{$this->account->id}/import-config?type=transaction")
        ->assertOk()
        ->assertJsonPath('data.columnMapping.transaction_type', 'Tipo de transacción')
        ->assertJsonPath('data.columnMapping.expense_types', ['Gasto', 'Ajuste de saldo']);
});

test('upserts the config instead of creating duplicates', function () use ($transactionConfig) {
    $url = "/api/accounts/{$this->account->id}/import-config";

    $this->putJson($url, ['type' => 'transaction', 'config' => $transactionConfig])->assertOk();

    $updated = $transactionConfig;
    $updated['dateFormat'] = 'YYYY-MM-DD';
    $this->putJson($url, ['type' => 'transaction', 'config' => $updated])->assertOk();

    expect(AccountImportConfig::where('account_id', $this->account->id)->count())->toBe(1);
    $this->getJson("{$url}?type=transaction")
        ->assertJsonPath('data.dateFormat', 'YYYY-MM-DD');
});

test('transaction and balance configs are stored independently', function () use ($transactionConfig) {
    $url = "/api/accounts/{$this->account->id}/import-config";
    $balanceConfig = [
        'columnMapping' => ['balance_date' => 'Date', 'balance' => 'Saldo', 'invested_amount' => null],
        'dateFormat' => 'YYYY-MM-DD',
    ];

    $this->putJson($url, ['type' => 'transaction', 'config' => $transactionConfig])->assertOk();
    $this->putJson($url, ['type' => 'balance', 'config' => $balanceConfig])->assertOk();

    expect(AccountImportConfig::where('account_id', $this->account->id)->count())->toBe(2);
    $this->getJson("{$url}?type=balance")
        ->assertJsonPath('data.columnMapping.balance', 'Saldo');
});

test('cannot read the import config of another user account', function () {
    $otherAccount = Account::factory()->create();

    $this->getJson("/api/accounts/{$otherAccount->id}/import-config?type=transaction")
        ->assertForbidden();
});

test('cannot write the import config of another user account', function () use ($transactionConfig) {
    $otherAccount = Account::factory()->create();

    $this->putJson("/api/accounts/{$otherAccount->id}/import-config", [
        'type' => 'transaction',
        'config' => $transactionConfig,
    ])->assertForbidden();
});

test('keeps whether the saved mapping is the AI suggestion', function (bool $aiSuggested) use ($transactionConfig) {
    $this->putJson("/api/accounts/{$this->account->id}/import-config", [
        'type' => 'transaction',
        'config' => [...$transactionConfig, 'aiSuggested' => $aiSuggested],
    ])->assertOk();

    $this->getJson("/api/accounts/{$this->account->id}/import-config?type=transaction")
        ->assertOk()
        ->assertJsonPath('data.aiSuggested', $aiSuggested);
})->with([true, false]);

test('saves a config without the AI suggestion flag', function () use ($transactionConfig) {
    $this->putJson("/api/accounts/{$this->account->id}/import-config", [
        'type' => 'transaction',
        'config' => $transactionConfig,
    ])->assertOk()->assertJsonMissingPath('data.aiSuggested');
});

test('rejects an invalid config', function (array $payload, string $invalidField) {
    $this->putJson("/api/accounts/{$this->account->id}/import-config", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidField);
})->with([
    'unknown config type' => [['type' => 'nonsense', 'config' => $transactionConfig], 'type'],
    'config without a column mapping' => [['type' => 'transaction', 'config' => ['dateFormat' => 'YYYY-MM-DD']], 'config.columnMapping'],
    'non-boolean AI suggestion flag' => [['type' => 'transaction', 'config' => [...$transactionConfig, 'aiSuggested' => 'maybe']], 'config.aiSuggested'],
]);
