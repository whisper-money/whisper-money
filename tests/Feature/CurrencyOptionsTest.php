<?php

use App\Services\CurrencyOptions;

test('exposes Nigerian Naira as a primary and account currency', function () {
    $options = app(CurrencyOptions::class);

    expect($options->primaryCodes())->toContain('NGN')
        ->and($options->accountCodes())->toContain('NGN');
});
