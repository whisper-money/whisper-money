<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Category;
use Tests\Support\PlanningFixtures;

use function Pest\Laravel\actingAs;

/**
 * The Planning card's title shares its header row with a badge. On a phone the
 * title has to take every pixel up to that badge, and only a name that really
 * does not fit may truncate, without ever widening the card.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);
});

it('only truncates a planning card title that does not fit on a phone', function () {
    $user = PlanningFixtures::user();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Dining']);
    $longName = str_repeat('Restaurantes', 8);

    Budget::factory()->monthly()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Restaurantes',
    ]);
    Budget::factory()->monthly()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => $longName,
    ]);

    actingAs($user);

    $page = visit('/budgets')
        ->on()
        ->iPhone14Pro()
        ->navigate('/budgets');

    $page->assertSee('Restaurantes');

    $titles = $page->script(<<<'JS'
        (() => {
            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return {
                pageOverflow: main.scrollWidth - main.clientWidth,
                titles: [...document.querySelectorAll('[data-slot="card-title"] a')].map((link) => {
                    const card = link.closest('[data-slot="card"]').getBoundingClientRect();
                    const column = link.closest('[data-slot="card-title"]').parentElement;
                    const badge = column.nextElementSibling.getBoundingClientRect();

                    return {
                        name: link.textContent,
                        truncated: link.scrollWidth > link.clientWidth,
                        gapToBadge: badge.left - column.getBoundingClientRect().right,
                        withinCard: link.getBoundingClientRect().right <= card.right,
                    };
                }),
            };
        })()
    JS);

    $short = collect($titles['titles'])->firstWhere('name', 'Restaurantes');
    $long = collect($titles['titles'])->firstWhere('name', $longName);

    // Both title columns run up to the badge, only the row's gap-2 apart, so the
    // room a title gets never depends on how wide its own text measures.
    expect($short['truncated'])->toBeFalse()
        ->and($short['gapToBadge'])->toEqual(8)
        ->and($long['truncated'])->toBeTrue()
        ->and($long['withinCard'])->toBeTrue()
        ->and($long['gapToBadge'])->toEqual(8)
        ->and($titles['pageOverflow'])->toBeLessThanOrEqual(0);

    $page->assertNoJavascriptErrors();
});
