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

it('prioritizes exact and prefix bank matches when searching', function () {
    actingAs($this->user);

    Bank::factory()->create(['name' => 'Online Banking Demo', 'user_id' => null]);
    Bank::factory()->create(['name' => 'ING Wholesale Banking', 'user_id' => null]);
    Bank::factory()->create(['name' => 'ING', 'user_id' => null]);
    Bank::factory()->create(['name' => 'My Savings Bank', 'user_id' => null]);

    $response = $this->getJson(route('banks.index', ['search' => 'ING']));

    $response->assertSuccessful();

    expect(collect($response->json('data'))->pluck('name')->take(4)->all())
        ->toBe([
            'ING',
            'ING Wholesale Banking',
            'My Savings Bank',
            'Online Banking Demo',
        ]);
});

it('returns all matching banks without truncating search results', function () {
    actingAs($this->user);

    foreach (range(1, 25) as $index) {
        Bank::factory()->create([
            'name' => sprintf('ING Result %02d', $index),
            'user_id' => null,
        ]);
    }

    $response = $this->getJson(route('banks.index', ['search' => 'ING Result']));

    $response->assertSuccessful();

    expect($response->json('data'))
        ->toHaveCount(25);
});

it('serializes banks with a standard field set and no timestamps', function () {
    actingAs($this->user);

    Bank::factory()->create(['name' => 'ING', 'user_id' => null]);

    $response = $this->getJson(route('banks.index'));

    $response->assertSuccessful();

    expect(array_keys($response->json('data.0')))
        ->toEqualCanonicalizing(['id', 'name', 'logo', 'user_id']);
});

it('includes matching user banks in search results', function () {
    actingAs($this->user);

    Bank::factory()->create(['name' => 'ING', 'user_id' => null]);
    Bank::factory()->create(['name' => 'ING Personal Vault', 'user_id' => $this->user->id]);
    Bank::factory()->create(['name' => 'ING Hidden', 'user_id' => User::factory()->create()->id]);

    $response = $this->getJson(route('banks.index', ['search' => 'ING']));

    $response->assertSuccessful();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toContain('ING', 'ING Personal Vault')
        ->not->toContain('ING Hidden');
});
