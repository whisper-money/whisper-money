<?php

test('service worker exists', function () {
    expect(file_exists(public_path('sw.js')))->toBeTrue();
});

test('web manifest starts at dashboard with standalone display', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    expect($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['display'])->toBe('standalone');
});

test('app template includes pwa meta tags and service worker registration', function () {
    $response = $this->get('/');

    $response->assertStatus(200)
        ->assertSee('apple-mobile-web-app-capable', false)
        ->assertSee('apple-mobile-web-app-status-bar-style', false)
        ->assertSee('content="default"', false)
        ->assertDontSee('black-translucent', false)
        ->assertSee('serviceWorker', false)
        ->assertSee("navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch", false)
        ->assertSee('viewport-fit=cover', false)
        ->assertSee("try {\n                    chartScheme = localStorage.getItem('chart-color-scheme')", false);
});

test('the manifest paints the splash and the system bars with the light background', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // A manifest is baked into the WebAPK at install time and cannot react to the
    // theme, so it carries the light background — the default — for both.
    $this->withUnencryptedCookie('appearance', 'light')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="theme-color" content="'.$manifest['theme_color'].'">', false);

    expect($manifest['background_color'])->toBe($manifest['theme_color']);
});

test('the --background tokens every hard-coded mirror was derived from have not drifted', function () {
    // The blade, the manifest and use-appearance.tsx carry hex mirrors of these
    // tokens, because neither a manifest nor a meta tag parses oklch. When this
    // fails, re-derive every mirror: oklch(1 0 0) is #ffffff, oklch(0.225 0 0)
    // is #1c1c1c.
    expect(file_get_contents(resource_path('css/app.css')))
        ->toContain('--background: oklch(1 0 0);')
        ->toContain('--background: oklch(0.225 0 0);');
});
