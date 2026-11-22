<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the design system page opens for a guest', function () {
    $this->get('/design-system')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('design-system'));
});

test('the design system page opens for a signed-in user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/design-system')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('design-system'));
});

test('the design system page tells search engines not to index it', function () {
    $this->get('/design-system')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('the design system page keeps the noindex header on an Inertia visit', function () {
    $this->get('/design-system', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('the design system page is left out of the sitemap', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertDontSee('design-system');
});
