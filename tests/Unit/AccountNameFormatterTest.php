<?php

use App\Services\Banking\Formatters\AccountNameFormatter;

/**
 * The payload Banco de Sabadell actually returns: `name` is the holder, and it
 * is the same holder on all five accounts of the connection.
 */
function sabadellAccount(array $overrides = []): array
{
    return array_replace([
        'name' => 'Nombre Apellido1 Apellido2',
        'product' => 'CUENTA AVANZA',
        'currency' => 'EUR',
        'account_id' => ['iban' => 'ES6200810602620003333338'],
    ], $overrides);
}

it('names the account after the product rather than the holder', function () {
    expect(AccountNameFormatter::format(sabadellAccount(), 'Fallback'))
        ->toBe('Cuenta Avanza');
});

it('leaves a name the bank already cased alone', function () {
    expect(AccountNameFormatter::format(sabadellAccount(['product' => 'Cuenta Nómina']), 'Fallback'))
        ->toBe('Cuenta Nómina');
});

it('falls back to the holder name when the bank sends no product', function () {
    expect(AccountNameFormatter::format(sabadellAccount(['product' => null]), 'Fallback'))
        ->toBe('Nombre Apellido1 Apellido2');
});

it('falls back to the iban, uppercase intact, when the bank names nothing', function () {
    expect(AccountNameFormatter::format(sabadellAccount(['product' => null, 'name' => '  ']), 'Fallback'))
        ->toBe('ES6200810602620003333338');
});

it('falls back to the caller last of all', function () {
    expect(AccountNameFormatter::format(['currency' => 'EUR'], 'Sabadell Account'))
        ->toBe('Sabadell Account');
});
