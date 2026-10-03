<?php

test('service worker exists', function () {
    expect(file_exists(public_path('sw.js')))->toBeTrue();
});

test('web manifest starts at dashboard with standalone display', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // Standalone, not fullscreen: an installed Android PWA in standalone paints
    // the status bar from the page's theme-color meta, which follows the app's
    // appearance and changes at runtime. Fullscreen hides the status bar and
    // leaves the camera cutout as a black band at the top of the screen.
    expect($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['display'])->toBe('standalone');
});

test('the landing page detects an installed app in the display mode the manifest asks for', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // The landing page redirects installed users to the dashboard, and the
    // display-mode media feature only matches the mode that was actually applied.
    expect(file_get_contents(resource_path('js/pages/welcome.tsx')))
        ->toContain('(display-mode: '.$manifest['display'].')');
});

test('the landing page still detects the Android installs made under the fullscreen manifest', function () {
    // A WebAPK keeps the display mode it was minted with until Chrome updates it
    // to the current manifest, so those installs still run in fullscreen.
    expect(file_get_contents(resource_path('js/pages/welcome.tsx')))
        ->toContain('(display-mode: fullscreen)');
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

test('the manifest leaves the theme colour to the page and paints the splash with the light background', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // A manifest is baked into the WebAPK at install time and cannot react to the
    // theme. From Chrome 156 an installed Android PWA paints its bottom navigation
    // bar with the manifest theme_color, one fixed colour whatever the app or the
    // phone is set to. Without one, Chrome keeps that bar on the phone's light or
    // dark theme, and the status bar follows the theme-color meta either way.
    expect($manifest)->not->toHaveKey('theme_color');

    // background_color only paints the splash screen, so it carries the light
    // background, the default.
    $this->withUnencryptedCookie('appearance', 'light')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="theme-color" content="'.$manifest['background_color'].'">', false);
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
