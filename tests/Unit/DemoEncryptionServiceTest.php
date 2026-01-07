<?php

use App\Services\Demo\DemoEncryptionService;

test('generates deterministic salt from seed', function () {
    $service = new DemoEncryptionService;

    $salt1 = $service->generateSalt('demo');
    $salt2 = $service->generateSalt('demo');

    expect($salt1)->toBe($salt2);
    expect(base64_decode($salt1))->toHaveLength(16);
});

test('generates different salts for different seeds', function () {
    $service = new DemoEncryptionService;

    $salt1 = $service->generateSalt('demo');
    $salt2 = $service->generateSalt('other');

    expect($salt1)->not->toBe($salt2);
});

test('derives key from password and salt', function () {
    $service = new DemoEncryptionService;

    $salt = $service->generateSalt('demo');
    $key = $service->deriveKey('demo', $salt);

    expect(strlen($key))->toBe(32);
});

test('derives same key for same password and salt', function () {
    $service = new DemoEncryptionService;

    $salt = $service->generateSalt('demo');
    $key1 = $service->deriveKey('demo', $salt);
    $key2 = $service->deriveKey('demo', $salt);

    expect($key1)->toBe($key2);
});

test('encrypts and decrypts text correctly', function () {
    $service = new DemoEncryptionService;

    $salt = $service->generateSalt('demo');
    $key = $service->deriveKey('demo', $salt);

    $plaintext = 'Hello, world';
    $encrypted = $service->encrypt($plaintext, $key);

    expect($encrypted)->toHaveKeys(['encrypted', 'iv']);
    expect($encrypted['encrypted'])->not->toBe($plaintext);

    $decrypted = $service->decrypt($encrypted['encrypted'], $key, $encrypted['iv']);

    expect($decrypted)->toBe($plaintext);
});

test('deterministic iv produces same encrypted output', function () {
    $service = new DemoEncryptionService;

    $salt = $service->generateSalt('demo');
    $key = $service->deriveKey('demo', $salt);

    $plaintext = 'Hello, world';
    $encrypted1 = $service->encrypt($plaintext, $key, 'seed1');
    $encrypted2 = $service->encrypt($plaintext, $key, 'seed1');

    expect($encrypted1['encrypted'])->toBe($encrypted2['encrypted']);
    expect($encrypted1['iv'])->toBe($encrypted2['iv']);
});

test('different iv seeds produce different encrypted output', function () {
    $service = new DemoEncryptionService;

    $salt = $service->generateSalt('demo');
    $key = $service->deriveKey('demo', $salt);

    $plaintext = 'Hello, world';
    $encrypted1 = $service->encrypt($plaintext, $key, 'seed1');
    $encrypted2 = $service->encrypt($plaintext, $key, 'seed2');

    expect($encrypted1['encrypted'])->not->toBe($encrypted2['encrypted']);
});
