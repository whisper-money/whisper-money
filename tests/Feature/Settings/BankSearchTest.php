<?php

use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

it('requires at least 3 characters to search', function () {
    $response = $this->getJson(route('banks.search', ['query' => 'ab']));

    $response->assertSuccessful()
        ->assertJson([
            'banks' => [],
            'message' => 'Type at least 3 characters to search',
        ]);
});

it('prioritizes exact matches first', function () {
    Bank::factory()->create(['name' => 'ABC Banking']);
    Bank::factory()->create(['name' => 'ING']);
    Bank::factory()->create(['name' => 'Test ING Bank']);
    Bank::factory()->create(['name' => 'Other Company']);

    $response = $this->getJson(route('banks.search', ['query' => 'ING']));

    $response->assertSuccessful();

    $banks = $response->json('banks');

    expect($banks)->toHaveCount(3);
    expect($banks[0]['name'])->toBe('ING');
});

it('prioritizes names starting with query second', function () {
    Bank::factory()->create(['name' => 'ABC Bank']);
    Bank::factory()->create(['name' => 'Test Bank']);
    Bank::factory()->create(['name' => 'Bank of America']);
    Bank::factory()->create(['name' => 'Banking Solutions']);

    $response = $this->getJson(route('banks.search', ['query' => 'Bank']));

    $response->assertSuccessful();

    $banks = $response->json('banks');

    expect($banks)->toHaveCount(4);
    expect($banks[0]['name'])->toBe('Bank of America');
    expect($banks[1]['name'])->toBe('Banking Solutions');
    expect($banks[2]['name'])->toBe('ABC Bank');
    expect($banks[3]['name'])->toBe('Test Bank');
});

it('sorts results alphabetically within priority groups', function () {
    Bank::factory()->create(['name' => 'Zebra ING Bank']);
    Bank::factory()->create(['name' => 'ING Direct']);
    Bank::factory()->create(['name' => 'ING']);
    Bank::factory()->create(['name' => 'Alpha ING Bank']);
    Bank::factory()->create(['name' => 'ING Bank']);

    $response = $this->getJson(route('banks.search', ['query' => 'ING']));

    $response->assertSuccessful();

    $banks = $response->json('banks');

    expect($banks)->toHaveCount(5);
    expect($banks[0]['name'])->toBe('ING');
    expect($banks[1]['name'])->toBe('ING Bank');
    expect($banks[2]['name'])->toBe('ING Direct');
    expect($banks[3]['name'])->toBe('Alpha ING Bank');
    expect($banks[4]['name'])->toBe('Zebra ING Bank');
});

it('is case insensitive', function () {
    Bank::factory()->create(['name' => 'ING']);
    Bank::factory()->create(['name' => 'ing direct']);
    Bank::factory()->create(['name' => 'Test ing Bank']);

    $response = $this->getJson(route('banks.search', ['query' => 'ing']));

    $response->assertSuccessful();

    $banks = $response->json('banks');

    expect($banks)->toHaveCount(3);
    expect($banks[0]['name'])->toBe('ING');
});

it('includes both user banks and global banks', function () {
    Bank::factory()->create(['name' => 'User Bank', 'user_id' => $this->user->id]);
    Bank::factory()->create(['name' => 'Global Bank', 'user_id' => null]);
    Bank::factory()->create(['name' => 'Other User Bank', 'user_id' => User::factory()->create()->id]);

    $response = $this->getJson(route('banks.search', ['query' => 'Bank']));

    $response->assertSuccessful();

    $banks = $response->json('banks');
    $bankNames = array_column($banks, 'name');

    expect($banks)->toHaveCount(2);
    expect($bankNames)->toContain('User Bank');
    expect($bankNames)->toContain('Global Bank');
});
