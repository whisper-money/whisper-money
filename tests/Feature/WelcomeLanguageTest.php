<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the landing shares the language it is written in, apart from the format region', function () {
    // An English browser reading the Spanish landing: the copy (and the hero
    // video) follow the page language, while `locale` keeps the browser region.
    $this->withHeader('Accept-Language', 'en-US')
        ->get('/?lang=es')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('language', 'es')
            ->where('locale', 'en-US'));
});

test('the landing language follows the browser when nothing overrides it', function () {
    $this->withHeader('Accept-Language', 'fr-FR')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('language', 'fr'));
});
