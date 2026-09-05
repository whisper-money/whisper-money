<?php

test('the status bar colour follows the appearance preference, not the OS one', function (string $appearance, string $expected) {
    $this->withUnencryptedCookie('appearance', $appearance)
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="theme-color" content="'.$expected.'">', false);
})->with([
    'light' => ['light', '#ffffff'],
    'dark' => ['dark', '#1c1c1c'],
]);

test('the OS preference still drives the status bar when the appearance is system', function () {
    $response = $this->withUnencryptedCookie('appearance', 'system')
        ->get(route('login'))
        ->assertOk();

    $response->assertSee('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', false)
        ->assertSee('<meta name="theme-color" content="#1c1c1c" media="(prefers-color-scheme: dark)">', false);
});
