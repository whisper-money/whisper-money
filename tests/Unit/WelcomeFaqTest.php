<?php

$directBankConnectionQuestion = 'Can I connect my bank accounts directly?';
$directBankConnectionAnswer = 'Yes. Bank connections use secure Open Banking and never require your bank credentials, so transactions can sync automatically. This is a Pro feature; free users can import everything, but only through the CSV/Excel importer.';
$legacyDirectBankConnectionAnswer = 'No. We never ask for your bank credentials. You import transactions by exporting a CSV or XLS file from your bank and uploading it to Whisper Money. This keeps your bank account secure.';

it('keeps the welcome FAQ honest about direct bank connections', function () use ($directBankConnectionQuestion, $directBankConnectionAnswer, $legacyDirectBankConnectionAnswer) {
    $welcomePage = file_get_contents(dirname(__DIR__, 2).'/resources/js/pages/welcome.tsx');

    expect($welcomePage)->toBeString();
    expect($welcomePage)
        ->toContain($directBankConnectionQuestion)
        ->toContain($directBankConnectionAnswer)
        ->not->toContain($legacyDirectBankConnectionAnswer);
});

it('translates the direct bank connection FAQ in Spanish', function () use ($directBankConnectionQuestion, $directBankConnectionAnswer) {
    $translationsJson = file_get_contents(dirname(__DIR__, 2).'/lang/es.json');

    expect($translationsJson)->toBeString();

    $translations = json_decode($translationsJson, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($translations)->toBeArray()
        ->toHaveKey($directBankConnectionQuestion)
        ->toHaveKey($directBankConnectionAnswer)
        ->and($translations[$directBankConnectionQuestion])->toBe('¿Puedo conectar mis cuentas bancarias directamente?')
        ->and($translations[$directBankConnectionAnswer])->toBe('Sí. Las conexiones bancarias usan Open Banking seguro y nunca requieren tus credenciales bancarias, por lo que las transacciones se sincronizan automáticamente. Es una función Pro; los usuarios gratuitos pueden importarlo todo, pero solo mediante el importador CSV/Excel.');
});
