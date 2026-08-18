<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('caps the app content width on wide screens', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/dashboard')->on()->desktop();

    $measures = $page->script(<<<'JS'
        (() => {
            const content = document.querySelector('[data-testid="page-content"]');

            return {
                viewport: window.innerWidth,
                content: content.getBoundingClientRect().width,
            };
        })()
    JS);

    expect($measures['viewport'])->toBeGreaterThan(1280);
    expect($measures['content'])->toBeLessThanOrEqual(1280);
});
