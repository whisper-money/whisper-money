<?php

test('popover content keeps a safe top collision padding', function () {
    $popover = file_get_contents(resource_path('js/components/ui/popover.tsx'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($popover)->toContain('collisionPadding={collisionPadding ?? { top: safeAreaTopPadding, right: 8, bottom: 8, left: 8 }}')
        ->and($css)->toContain('--safe-area-top: env(safe-area-inset-top, 0px);');
});
