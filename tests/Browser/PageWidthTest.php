<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('caps the app content width on wide screens and keeps the header aligned', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/dashboard')
        ->on()
        ->desktop()
        ->assertSee('Overview of your financial health');

    $measures = $page->script(<<<'JS'
        (() => {
            const content = document.querySelector('[data-testid="page-content"]');
            const headerRow = document.querySelector('[data-testid="page-header"]');

            return {
                available: content.parentElement.getBoundingClientRect().width,
                content: content.getBoundingClientRect().width,
                contentLeft: content.getBoundingClientRect().left,
                headerRowLeft: headerRow.getBoundingClientRect().left,
            };
        })()
    JS);

    // Guard: without room to spare the cap would not kick in and the test would prove nothing.
    expect($measures['available'])->toBeGreaterThan(1280);
    // 1280px is `--container-page` (80rem), used through the `max-w-page` utility.
    expect($measures['content'])->toEqualWithDelta(1280, 1);
    expect($measures['headerRowLeft'])->toEqualWithDelta($measures['contentLeft'], 1);

    $page->assertNoJavascriptErrors();
});
