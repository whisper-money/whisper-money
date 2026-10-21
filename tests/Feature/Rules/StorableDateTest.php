<?php

use App\Rules\StorableDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

function passesStorableDate(mixed $value): bool
{
    return Validator::make(['date' => $value], ['date' => ['required', 'date', new StorableDate]])->passes();
}

it('accepts dates a DATE column stores', function (mixed $value) {
    expect(passesStorableDate($value))->toBeTrue();
})->with([
    'Y-m-d' => '2026-10-21',
    'ISO datetime' => '2026-10-21T10:00:00.000000Z',
    'datetime with a space' => '2026-10-21 10:00:00',
    'd-m-Y' => '21-10-2026',
    'lower bound' => '1900-01-01',
    'upper bound' => '9999-12-31',
    'Carbon instance' => fn () => Carbon::parse('2026-10-21'),
]);

it('rejects years PHP would silently truncate or MySQL refuses', function (string $value) {
    expect(passesStorableDate($value))->toBeFalse();
})->with([
    'six-digit year from an import' => '262004-08-10',
    'five-digit year typo' => '20026-11-10',
    'six-digit year with slashes' => '262004/08/10',
    'dropped-digit year' => '0026-11-10',
    'before 1900' => '1899-12-31',
]);

it('names the bounds in its message', function () {
    $validator = Validator::make(['transaction_date' => '262004-08-10'], ['transaction_date' => [new StorableDate]]);

    expect($validator->errors()->first('transaction_date'))->toBe('The transaction date must be a date between 1900 and 9999.');
});
