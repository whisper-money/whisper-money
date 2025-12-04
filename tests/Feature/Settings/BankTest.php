<?php

use App\Models\Bank;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can create a new bank with name only', function () {
    actingAs($this->user);

    $response = $this->postJson(route('banks.store'), [
        'name' => 'My Custom Bank',
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure(['id', 'name', 'user_id']);
    $response->assertJson([
        'name' => 'My Custom Bank',
        'user_id' => $this->user->id,
    ]);
    assertDatabaseHas('banks', [
        'name' => 'My Custom Bank',
        'user_id' => $this->user->id,
    ]);
});

it('can create a new bank with logo', function () {
    Storage::fake('public');
    actingAs($this->user);

    $logo = UploadedFile::fake()->image('logo.png', 100, 100);

    $response = $this->postJson(route('banks.store'), [
        'name' => 'Bank With Logo',
        'logo' => $logo,
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure(['id', 'name', 'user_id', 'logo']);

    $bank = Bank::query()->where('name', 'Bank With Logo')->first();
    expect($bank)->not->toBeNull();
    expect($bank->logo)->not->toBeNull();
});

it('validates name is required', function () {
    actingAs($this->user);

    $response = $this->postJson(route('banks.store'), []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);
});

it('validates logo must be an image', function () {
    actingAs($this->user);

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->postJson(route('banks.store'), [
        'name' => 'Test Bank',
        'logo' => $file,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['logo']);
});

it('validates logo dimensions must be square', function () {
    actingAs($this->user);

    $logo = UploadedFile::fake()->image('logo.png', 200, 100);

    $response = $this->postJson(route('banks.store'), [
        'name' => 'Test Bank',
        'logo' => $logo,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['logo']);
});

it('validates logo dimensions must not exceed 500px', function () {
    actingAs($this->user);

    $logo = UploadedFile::fake()->image('logo.png', 600, 600);

    $response = $this->postJson(route('banks.store'), [
        'name' => 'Test Bank',
        'logo' => $logo,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['logo']);
});

it('validates logo file size must not exceed 500KB', function () {
    actingAs($this->user);

    $logo = UploadedFile::fake()->image('logo.png', 100, 100)->size(600);

    $response = $this->postJson(route('banks.store'), [
        'name' => 'Test Bank',
        'logo' => $logo,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['logo']);
});

it('requires authentication to create a bank', function () {
    $response = $this->postJson(route('banks.store'), [
        'name' => 'Test Bank',
    ]);

    $response->assertUnauthorized();
});
