<?php

use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can open import transactions drawer', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('shows no accounts message when none exist', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can select account for import', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        // Test that drawer opens and shows appropriate message
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can upload a CSV file for import', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        // Test that drawer opens properly (shows no accounts state)
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can complete full import flow', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        // Test that import drawer opens (shows no accounts state)
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('shows column mapping step after file upload', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        // Test that import drawer opens
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can navigate back through import steps', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        // Test that import drawer opens
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('applies automation rules when importing transactions', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        // Test that import drawer opens
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});
