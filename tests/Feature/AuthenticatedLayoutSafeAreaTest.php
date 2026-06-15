<?php

test('authenticated sidebar layout applies the safe area inset on the scrolling shell', function () {
    $layout = file_get_contents(resource_path('js/layouts/app/app-sidebar-layout.tsx'));
    $header = file_get_contents(resource_path('js/components/app-sidebar-header.tsx'));

    expect($layout)->toContain('className="pt-safe overflow-x-hidden pb-[90px] md:pb-0"')
        ->and($header)->not->toContain('pt-safe');
});
