<?php

return [
    /*
     * Where the pages live. The directory layout under here is the navigation:
     * `<name>-<lang>.md` is a page, a directory holding a `section.md` is a
     * titled group, and a directory named after a page holds its children. A
     * leading `10-` orders an entry and never reaches its URL.
     */
    'root' => resource_path('docs/documentation'),

    'default' => 'getting-started',
    'fallback_locale' => 'en',

    'locales' => [
        'en' => 'English',
        'es' => 'Español',
    ],

    'toc' => [
        'placeholder' => '{{TOC}}',
        'levels' => [2, 3],
    ],
];
