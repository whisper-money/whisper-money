<?php

use App\Models\Bank;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

function logoImage(int $width, int $height, string $format = 'png'): string
{
    $im = imagecreatetruecolor($width, $height);
    ob_start();
    $format === 'jpeg' ? imagejpeg($im) : imagepng($im);
    $contents = ob_get_clean();

    return (string) $contents;
}

test('downloads and stores a square image as the bank logo', function (string $format, string $url) {
    Storage::fake('public');

    $bank = Bank::factory()->create(['logo' => null]);

    Http::fake([
        $url => Http::response(logoImage(300, 300, $format), 200, ['Content-Type' => "image/{$format}"]),
    ]);

    artisan('banks:set-logo', ['bank' => $bank->id, 'url' => $url])
        ->expectsOutputToContain('Image downloaded (300×300px).')
        ->expectsOutputToContain('Resized to 250×250px.')
        ->expectsOutputToContain("Logo updated for \"{$bank->name}\".")
        ->assertSuccessful();

    Storage::disk('public')->assertExists("banks/logos/{$bank->id}.png");
    expect($bank->fresh()->logo)->not->toBeNull();
})->with([
    'png' => ['png', 'https://example.test/logo.png'],
    'jpeg' => ['jpeg', 'https://example.test/logo.jpg'],
]);

test('does not resize images already within 250px', function () {
    Storage::fake('public');

    $bank = Bank::factory()->create(['logo' => null]);
    $squarePng = logoImage(100, 100);

    Http::fake([
        'https://example.test/logo.png' => Http::response($squarePng, 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('banks:set-logo', ['bank' => $bank->id, 'url' => 'https://example.test/logo.png'])
        ->expectsOutputToContain('Image downloaded (100×100px).')
        ->doesntExpectOutputToContain('Resized to')
        ->assertSuccessful();

    Storage::disk('public')->assertExists("banks/logos/{$bank->id}.png");
});

test('fails when bank uuid does not exist', function () {
    artisan('banks:set-logo', ['bank' => 'non-existent-uuid', 'url' => 'https://example.test/logo.png'])
        ->expectsOutputToContain('Bank not found: non-existent-uuid')
        ->assertFailed();
});

test('fails when http request returns an error status', function () {
    $bank = Bank::factory()->create();

    Http::fake([
        'https://example.test/logo.png' => Http::response('', 404),
    ]);

    artisan('banks:set-logo', ['bank' => $bank->id, 'url' => 'https://example.test/logo.png'])
        ->expectsOutputToContain('Failed to download image: HTTP 404')
        ->assertFailed();
});

test('fails when url does not point to an image', function () {
    $bank = Bank::factory()->create();

    Http::fake([
        'https://example.test/page' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    artisan('banks:set-logo', ['bank' => $bank->id, 'url' => 'https://example.test/page'])
        ->expectsOutputToContain('URL does not point to an image')
        ->assertFailed();
});

test('fails when image is not square', function () {
    $bank = Bank::factory()->create(['logo' => null]);
    $rectangularPng = logoImage(400, 200);

    Http::fake([
        'https://example.test/logo.png' => Http::response($rectangularPng, 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('banks:set-logo', ['bank' => $bank->id, 'url' => 'https://example.test/logo.png'])
        ->expectsOutputToContain('Image is not square (400×200) — skipping.')
        ->assertFailed();

    expect($bank->fresh()->logo)->toBeNull();
});

test('fails when downloaded content is not a valid image', function () {
    $bank = Bank::factory()->create();

    Http::fake([
        'https://example.test/logo.png' => Http::response('not-an-image', 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('banks:set-logo', ['bank' => $bank->id, 'url' => 'https://example.test/logo.png'])
        ->expectsOutputToContain('Downloaded file is not a valid image')
        ->assertFailed();
});

test('prompts for bank uuid and url when no arguments are provided', function () {
    Storage::fake('public');

    $bank = Bank::factory()->create(['logo' => null]);
    $squarePng = logoImage(100, 100);

    Http::fake([
        'https://example.test/logo.png' => Http::response($squarePng, 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('banks:set-logo')
        ->expectsQuestion('Which bank UUID should the logo be set for?', $bank->id)
        ->expectsQuestion('What is the image URL to download?', 'https://example.test/logo.png')
        ->assertSuccessful();

    Storage::disk('public')->assertExists("banks/logos/{$bank->id}.png");
    expect($bank->fresh()->logo)->not->toBeNull();
});
