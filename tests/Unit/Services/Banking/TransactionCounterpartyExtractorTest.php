<?php

use App\Services\Banking\TransactionCounterpartyExtractor;

it('normalizes semicolon separated counterparty names', function () {
    $counterparties = TransactionCounterpartyExtractor::fromPayload([
        'creditor' => ['name' => 'VICTOR;FALCON;RUIZ'],
        'debtor' => ['name' => 'ACME;;PAYROLL'],
    ]);

    expect($counterparties['creditor_name'])->toBe('VICTOR FALCON RUIZ')
        ->and($counterparties['debtor_name'])->toBe('ACME PAYROLL');
});

it('ignores masked counterparty names', function () {
    $counterparties = TransactionCounterpartyExtractor::fromPayload([
        'creditor' => ['name' => '*****'],
        'debtor' => ['name' => ' ; * ; * ; '],
    ]);

    expect($counterparties['creditor_name'])->toBeNull()
        ->and($counterparties['debtor_name'])->toBeNull();
});
