<?php

declare(strict_types=1);

use App\Enums\AccountType;
use Tests\Support\PlanningFixtures;

use function Pest\Laravel\actingAs;

/**
 * Taking an account off the board, in the two ways the app offers: archiving
 * it, which stops it counting anywhere, and hiding it, which only takes its
 * card off the dashboard.
 *
 * tests/Feature/AccountControllerTest.php covers both endpoints. What a browser
 * adds is that the account really does leave the surfaces the archive dialog
 * promises it will — the accounts page and the dashboard, which is what #880
 * was about — and that the settings list is still a way back.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);
});

it('archives an account off the accounts page and the dashboard, and brings it back', function () {
    $user = PlanningFixtures::user();
    $kept = PlanningFixtures::account($user, 'Everyday Checking');
    $archived = PlanningFixtures::account($user, 'Old Savings', AccountType::Savings);

    PlanningFixtures::balance($kept, 250000);
    PlanningFixtures::balance($archived, 500000);

    actingAs($user);

    $page = visit("/accounts/{$archived->id}");

    $page->assertSee('Old Savings')
        ->click('[aria-label="More options"]')
        ->click('[role="menuitem"]:has-text("Archive account")')
        ->assertSee('Archive account')
        ->assertSee('It disappears from the dashboard and from the accounts page.')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('Archived')
        ->assertNoJavascriptErrors();

    expect($archived->fresh()->archived_at)->not->toBeNull();

    $page->navigate('/accounts')
        ->assertSee('Everyday Checking')
        ->assertDontSee('Old Savings')
        ->assertNoJavascriptErrors();

    $page->navigate('/dashboard')
        ->assertSee('Everyday Checking')
        ->assertDontSee('Old Savings')
        ->assertNoJavascriptErrors();

    // The settings list is the one place that keeps an archived account, so it
    // can be brought back. Archived rows sort below the live ones, so the
    // second menu on the page is this account's.
    $page->navigate('/settings/accounts')
        ->assertSee('Old Savings')
        ->assertSee('Archived')
        ->click('button[aria-label="Open menu"] >> nth=1')
        ->click('[role="menuitem"]:has-text("Unarchive")')
        ->assertDontSee('Archived')
        ->assertNoJavascriptErrors();

    expect($archived->fresh()->archived_at)->toBeNull();
});

it('hides an account from the dashboard without taking it off the accounts page', function () {
    $user = PlanningFixtures::user();
    $kept = PlanningFixtures::account($user, 'Everyday Checking');
    $hidden = PlanningFixtures::account($user, 'Holiday Fund', AccountType::Savings);

    PlanningFixtures::balance($kept, 250000);
    PlanningFixtures::balance($hidden, 120000);

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Holiday Fund')
        ->click('[aria-label="Edit accounts"]')
        ->assertSee('Toggle visibility and drag to reorder.')
        // Accounts are listed by name, so "Holiday Fund" is the second row.
        ->click('[aria-label="Hide from dashboard"] >> nth=1')
        // The eye flips locally and nothing else on screen moves when the
        // request comes back, so waiting for the network to go quiet is what
        // keeps the reload below from racing the PATCH.
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors();

    expect($hidden->fresh()->hidden_on_dashboard)->toBeTrue();

    $page->navigate('/dashboard')
        ->assertSee('Everyday Checking')
        ->assertDontSee('Holiday Fund')
        ->assertNoJavascriptErrors();

    // Hiding is only about the dashboard: the account is still there.
    $page->navigate('/accounts')
        ->assertSee('Holiday Fund')
        ->assertNoJavascriptErrors();
});
