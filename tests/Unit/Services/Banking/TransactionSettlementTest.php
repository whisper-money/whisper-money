<?php

use App\Services\Banking\TransactionSettlement;

test('a delivery the bank has not settled is reported as unsettled', function (string $status) {
    expect(TransactionSettlement::isUnsettled(['status' => $status]))->toBeTrue();
})->with(['PDNG', 'HOLD', 'SCHD', 'CNCL', 'RJCT']);

test('a settled, unknown or absent status is reported as settled', function (?string $status) {
    $data = $status === null ? [] : ['status' => $status];

    expect(TransactionSettlement::isUnsettled($data))->toBeFalse();
})->with([
    'booked' => 'BOOK',
    'other' => 'OTHR',
    'absent' => null,
]);

test('a lowercase status is not mistaken for an unsettled one', function () {
    expect(TransactionSettlement::isUnsettled(['status' => 'pdng']))->toBeFalse();
});

test('the un-posted card code is canonicalized to its settled form', function () {
    expect(TransactionSettlement::canonicalCardCode(['MCRD', 'UPCT']))->toBe(['CCRD', 'POSD']);
});

test('any other card code keeps discriminating', function (array $code) {
    expect(TransactionSettlement::canonicalCardCode($code))->toBe($code);
})->with([
    'already settled' => [['CCRD', 'POSD']],
    'unrelated' => [['PMNT', 'RCDT']],
    'code matches, sub_code does not' => [['MCRD', 'RCDT']],
    'sub_code matches, code does not' => [['PMNT', 'UPCT']],
    'absent' => [['', '']],
]);
