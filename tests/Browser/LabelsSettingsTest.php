<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Label;
use Tests\Support\PlanningFixtures;

use function Pest\Laravel\actingAs;

/**
 * Labels end to end: created and edited on /settings/labels, and carried by a
 * transaction on /transactions until the label is deleted.
 *
 * tests/Feature/LabelTest.php covers the endpoints. The half only a browser
 * shows is the badge on the transactions list, which is rendered from the page
 * props rather than from the transaction row itself — deleting the label has to
 * take the badge with it.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);
});

it('creates a label and then renames it with a different colour', function () {
    $user = PlanningFixtures::user();

    actingAs($user);

    $page = visit('/settings/labels');

    $page->assertSee('Labels settings')
        ->click('button:has-text("Create Label")')
        ->assertSee('Add a new label to tag your transactions.')
        ->fill('#name', 'Holiday')
        ->click('[role="dialog"] button:has-text("Select a color")')
        ->click('[role="option"]:has-text("blue")')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('Holiday')
        ->assertSee('BLUE')
        ->assertNoJavascriptErrors();

    $label = Label::query()->where('user_id', $user->id)->sole();

    expect($label->name)->toBe('Holiday')
        ->and($label->color)->toBe('blue');

    $page->click('button:has-text("Open menu")')
        ->click('[role="menuitem"]:has-text("Edit")')
        ->assertSee('Update the label information.')
        ->fill('#name', 'Holiday 2026')
        // The trigger already carries the current colour, so there is no
        // placeholder to click here.
        ->click('[role="dialog"] button[role="combobox"]')
        ->click('[role="option"]:has-text("teal")')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('Holiday 2026')
        ->assertSee('TEAL')
        ->assertNoJavascriptErrors();

    $label->refresh();

    expect($label->name)->toBe('Holiday 2026')
        ->and($label->color)->toBe('teal');
});

it('shows a label on the transactions list and drops it when the label is deleted', function () {
    $user = PlanningFixtures::user();
    $account = PlanningFixtures::account($user, 'Everyday Checking');
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Travel']);
    $label = Label::factory()->create([
        'user_id' => $user->id,
        'name' => 'Tokyo trip',
        'color' => 'blue',
    ]);

    PlanningFixtures::transaction($user, $account, 'Flight to Tokyo', -42010, $category->id)
        ->labels()->attach($label->id);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Flight to Tokyo')
        ->assertSee('Tokyo trip')
        ->assertNoJavascriptErrors();

    $page->navigate('/settings/labels')
        ->assertSee('Tokyo trip')
        ->click('button:has-text("Open menu")')
        ->click('[role="menuitem"]:has-text("Delete")')
        ->assertSee('Delete Label')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('No labels found.')
        ->assertNoJavascriptErrors();

    expect(Label::withTrashed()->whereKey($label->id)->value('deleted_at'))->not->toBeNull();

    $page->navigate('/transactions')
        // The transaction keeps its history, the badge goes with the label.
        ->assertSee('Flight to Tokyo')
        ->assertDontSee('Tokyo trip')
        ->assertNoJavascriptErrors();
});
