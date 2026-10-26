<?php

const LAST_CALL_VIEW = 'price-increase-oct-2026';
const LAST_CALL_SUBJECT = '€3.99 becomes €8.99 on 1 October';

const CANCELLING_VIEW = 'price-increase-cancelling-oct-2026';
const CANCELLING_SUBJECT = 'If your subscription ends, you lose the €3.99 price';

const SUBSCRIBERS_VIEW = 'price-increase-subscribers-oct-2026';
const SUBSCRIBERS_SUBJECT = 'Your price is not going up';

const LAST_DAYS_VIEW = 'price-increase-last-days-oct-2026';
const LAST_DAYS_SUBJECT = 'Your last chance at €3.99 ends on Wednesday';

const LAST_DAYS_CANCELLING_VIEW = 'price-increase-last-days-cancelling-oct-2026';
const LAST_DAYS_CANCELLING_SUBJECT = 'Last chance to keep your old price';

const LAST_DAYS_SUBSCRIBERS_VIEW = 'price-increase-last-days-subscribers-oct-2026';
const LAST_DAYS_SUBSCRIBERS_SUBJECT = 'After 1 October, nobody else can get your price';

const EXTENSION_VIEW = 'price-increase-extension-oct-2026';
const EXTENSION_SUBJECT = 'My mistake: the €3.99 price is extended to Thursday';

it('has a Spanish translation for every line of the template and its subject', function (string $view, string $subject) {
    $translations = json_decode(file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);

    preg_match_all("/__\('(.+?)'/", file_get_contents(resource_path("views/mail/updates/{$view}.blade.php")), $matches);

    expect($matches[1])->not->toBeEmpty();
    expect(array_values(array_diff([...$matches[1], $subject], array_keys($translations))))->toBe([]);
})->with([
    [LAST_CALL_VIEW, LAST_CALL_SUBJECT],
    [CANCELLING_VIEW, CANCELLING_SUBJECT],
    [SUBSCRIBERS_VIEW, SUBSCRIBERS_SUBJECT],
    [LAST_DAYS_VIEW, LAST_DAYS_SUBJECT],
    [LAST_DAYS_CANCELLING_VIEW, LAST_DAYS_CANCELLING_SUBJECT],
    [LAST_DAYS_SUBSCRIBERS_VIEW, LAST_DAYS_SUBSCRIBERS_SUBJECT],
    [EXTENSION_VIEW, EXTENSION_SUBJECT],
]);
