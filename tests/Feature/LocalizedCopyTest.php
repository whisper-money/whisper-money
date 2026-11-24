<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

/**
 * The registration form's errors read the field the way the form names it,
 * not the raw input name: "El campo contraseña…", never "El campo password…".
 */
it('names the password field in the reader language', function (string $locale, string $minMessage, string $confirmedMessage) {
    App::setLocale($locale);

    $errors = Validator::make(
        ['password' => 'short', 'password_confirmation' => 'other'],
        ['password' => ['min:8', 'confirmed']],
    )->errors()->get('password');

    expect($errors)->toBe([$minMessage, $confirmedMessage]);
})->with([
    'es' => ['es', 'El campo contraseña debe tener al menos 8 caracteres.', 'La confirmación del campo contraseña no coincide.'],
    'fr' => ['fr', 'Le champ mot de passe doit contenir au moins 8 caractères.', 'La confirmation du champ mot de passe ne correspond pas.'],
]);

it('names the name and email fields in the reader language', function (string $locale, string $name, string $email) {
    App::setLocale($locale);

    $errors = Validator::make([], ['name' => 'required', 'email' => 'required'])->errors();

    expect($errors->first('name'))->toContain($name)
        ->and($errors->first('email'))->toContain($email);
})->with([
    'es' => ['es', 'nombre', 'correo electrónico'],
    'fr' => ['fr', 'nom', 'adresse e-mail'],
]);

it('calls an account balance "saldo" and an amount "importe" in Spanish', function () {
    App::setLocale('es');

    expect(__('Balance'))->toBe('Saldo')
        ->and(__('Current Balance'))->toBe('Saldo Actual')
        ->and(__('Save Balance'))->toBe('Guardar Saldo')
        ->and(__('Amount'))->toBe('Importe');

    $spanishValues = json_decode(file_get_contents(lang_path('es.json')), true);

    expect(array_filter($spanishValues, fn (string $value): bool => preg_match('/(?<!:)\bbalances?\b/i', $value) === 1))->toBeEmpty();
});
