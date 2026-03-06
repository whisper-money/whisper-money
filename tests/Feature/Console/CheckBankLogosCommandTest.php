<?php

use App\Mail\BrokenBankLogosReportEmail;
use App\Models\Bank;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

test('command clears broken logos and emails admin report', function () {
    Mail::fake();

    config(['mail.admin_email' => 'admin@example.com']);

    $validBank = Bank::factory()->create([
        'name' => 'Valid Bank',
        'logo' => 'https://bank-valid.test/logo.png',
    ]);

    $brokenBank = Bank::factory()->create([
        'name' => 'Broken Bank',
        'logo' => 'https://bank-broken.test/logo.png',
    ]);

    Http::fake([
        'https://bank-valid.test/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
        'https://bank-broken.test/*' => Http::response('', 404),
    ]);

    artisan('banks:check-logos')
        ->expectsOutputToContain('Cleared broken logos for 1 bank(s).')
        ->expectsOutputToContain('Sent broken logo report to admin@example.com.')
        ->assertSuccessful();

    expect($validBank->fresh()->logo)->toBe('https://bank-valid.test/logo.png');
    expect($brokenBank->fresh()->logo)->toBeNull();

    Mail::assertSent(BrokenBankLogosReportEmail::class, function (BrokenBankLogosReportEmail $mail) use ($brokenBank) {
        return $mail->updatedBanks === [[
            'id' => $brokenBank->id,
            'name' => 'Broken Bank',
            'previous_logo' => 'https://bank-broken.test/logo.png',
        ]];
    });
});

test('command does not send report when no broken logos are found', function () {
    Mail::fake();

    config(['mail.admin_email' => 'admin@example.com']);

    $bank = Bank::factory()->create([
        'logo' => 'https://bank-valid.test/logo.png',
    ]);

    Http::fake([
        'https://bank-valid.test/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('banks:check-logos')
        ->expectsOutputToContain('All bank logos are valid.')
        ->assertSuccessful();

    expect($bank->fresh()->logo)->toBe('https://bank-valid.test/logo.png');
    Mail::assertNothingSent();
});

test('command clears broken logos without emailing when ADMIN_EMAIL is missing', function () {
    Mail::fake();

    config(['mail.admin_email' => null]);

    $brokenBank = Bank::factory()->create([
        'name' => 'Broken Bank',
        'logo' => 'https://bank-broken.test/logo.png',
    ]);

    Http::fake([
        'https://bank-broken.test/*' => Http::response('', 404),
    ]);

    artisan('banks:check-logos')
        ->expectsOutputToContain('Cleared broken logos for 1 bank(s).')
        ->expectsOutputToContain('ADMIN_EMAIL is not configured. Skipping report email.')
        ->assertSuccessful();

    expect($brokenBank->fresh()->logo)->toBeNull();
    Mail::assertNothingSent();
});

test('command falls back to get request when head request is not allowed', function () {
    Mail::fake();

    config(['mail.admin_email' => null]);

    $bank = Bank::factory()->create([
        'logo' => 'https://bank-head-fallback.test/logo.png',
    ]);

    Http::fake([
        'https://bank-head-fallback.test/*' => function (Request $request) {
            if ($request->method() === 'HEAD') {
                return Http::response('', 405);
            }

            return Http::response('', 200, ['Content-Type' => 'image/png']);
        },
    ]);

    artisan('banks:check-logos')
        ->expectsOutputToContain('All bank logos are valid.')
        ->assertSuccessful();

    expect($bank->fresh()->logo)->toBe('https://bank-head-fallback.test/logo.png');
    Mail::assertNothingSent();
});
