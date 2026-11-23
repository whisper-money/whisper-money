<?php

test('the roadmap page is gone', function () {
    $this->get('/roadmap')->assertNotFound();
});

test('nothing public points at the roadmap page any more', function () {
    expect($this->get('/sitemap.xml')->assertSuccessful()->getContent())
        ->not->toContain('/roadmap')
        ->and($this->get(route('llms'))->assertOk()->getContent())
        ->not->toContain('/roadmap');
});
