<?php

use App\Contracts\BankingProviderInterface;
use App\Support\Marketing\IntegrationsPage;
use App\Support\Marketing\MarketingContent;
use Inertia\Testing\AssertableInertia;

/**
 * Names quoted in the frontend registry, which is the source of truth for what
 * the connect flow actually offers.
 *
 * @return list<string>
 */
function connectProviderNames(): array
{
    $registry = file_get_contents(base_path('resources/js/lib/connect-providers.tsx'));

    preg_match_all("/institution: \{\s*name: '([^']+)'/", (string) $registry, $matches);

    return $matches[1];
}

/**
 * Country codes the frontend connect flow offers.
 *
 * @return list<string>
 */
function connectCountryCodes(): array
{
    $flow = file_get_contents(base_path('resources/js/hooks/use-connect-flow.ts'));

    preg_match('/CONNECT_COUNTRIES = \[(.*?)\] as const;/s', (string) $flow, $block);
    preg_match_all("/code: '([A-Z]{2})'/", $block[1] ?? '', $matches);

    return $matches[1];
}

/**
 * A provider that only knows two Spanish banks, for the sync command tests.
 */
function fakeInstitutions(): void
{
    test()->mock(BankingProviderInterface::class)
        ->shouldReceive('getInstitutions')
        ->andReturnUsing(fn (string $country): array => $country === 'ES' ? [
            ['name' => 'BBVA', 'country' => 'ES', 'logo' => null, 'maximum_consent_validity' => null],
            ['name' => 'BBVA', 'country' => 'ES', 'logo' => null, 'maximum_consent_validity' => null],
            ['name' => 'Abanca', 'country' => 'ES', 'logo' => null, 'maximum_consent_validity' => null],
            ['name' => IntegrationsPage::TEST_INSTITUTION, 'country' => 'ES', 'logo' => null, 'maximum_consent_validity' => null],
        ] : []);
}

test('the integrations page renders in its own language', function (string $locale) {
    $this->get('/'.IntegrationsPage::path($locale))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations')
            ->where('pageLocale', $locale)
            ->where('content.heading', MarketingContent::integrations($locale)['heading'])
            ->has('countries')
            ->has('providers', count(MarketingContent::apiProviders()))
            ->has('labels', 4)
        );
})->with(MarketingContent::LOCALES);

test('each language of the page links the other with hreflang and a canonical', function (string $locale) {
    $this->get('/'.IntegrationsPage::path($locale))
        ->assertSuccessful()
        ->assertInertia(function (AssertableInertia $page) use ($locale) {
            $alternates = $page->toArray()['props']['alternates'];

            expect(array_keys($alternates))->toEqualCanonicalizing(MarketingContent::LOCALES)
                ->and($alternates[$locale])->toBe(IntegrationsPage::url($locale));
        });
})->with(MarketingContent::LOCALES);

test('the page declares canonical and hreflang as http headers too', function (string $locale) {
    $link = $this->get('/'.IntegrationsPage::path($locale))
        ->assertSuccessful()
        ->headers->get('Link');

    $canonical = IntegrationsPage::url($locale);

    expect($link)->toContain('<'.$canonical.'>; rel="canonical"')
        ->and($link)->toContain('rel="alternate"; hreflang="x-default"')
        ->and($link)->toContain('<'.$canonical.'.md>; rel="alternate"; type="text/markdown"');

    foreach (IntegrationsPage::alternates() as $alternateLocale => $url) {
        expect($link)->toContain('<'.$url.'>; rel="alternate"; hreflang="'.$alternateLocale.'"');
    }
})->with(MarketingContent::LOCALES);

test('the asset preload Link header survives alongside the seo one', function () {
    // Laravel appends its own Link header for preloaded assets; replacing rather
    // than appending ours would silently drop it.
    $links = $this->get('/'.IntegrationsPage::path('en'))
        ->assertSuccessful()
        ->headers->all('Link');

    expect($links)->not->toBeEmpty()
        ->and(implode(', ', $links))->toContain('rel="canonical"');
});

test('the page has a markdown twin canonicalised to the html page', function (string $locale) {
    $response = $this->get('/'.IntegrationsPage::path($locale).'.md')->assertSuccessful();

    expect($response->headers->get('Content-Type'))->toContain('text/markdown')
        ->and($response->headers->get('Link'))->toBe('<'.IntegrationsPage::url($locale).'>; rel="canonical"')
        ->and($response->content())->toStartWith('# ')
        ->and($response->content())->toContain(MarketingContent::integrations($locale)['banks_title']);
})->with(MarketingContent::LOCALES);

test('pinning the page language leaves the visitor session locale alone', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/'.IntegrationsPage::path('en'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pageLocale', 'en'));

    expect(session('locale'))->toBe('es');
});

test('the spanish and english pages are distinct translations of each other', function () {
    expect(MarketingContent::integrations('en')['heading'])
        ->not->toBe(MarketingContent::integrations('es')['heading'])
        ->and(IntegrationsPage::path('en'))->toBe('integrations')
        ->and(IntegrationsPage::path('es'))->toBe('integraciones');
});

test('the copy names the same api providers as the connect flow', function () {
    expect(array_keys(MarketingContent::apiProviders()))
        ->toEqualCanonicalizing(connectProviderNames());
});

test('every api provider is described in every language', function () {
    foreach (MarketingContent::apiProviders() as $name => $descriptions) {
        foreach (MarketingContent::LOCALES as $locale) {
            expect($descriptions[$locale] ?? '')->not->toBeEmpty("{$name} has no {$locale} description");
        }
    }
});

test('the countries offered on the page are the ones the connect flow offers', function () {
    expect(array_keys(MarketingContent::COUNTRIES))->toEqualCanonicalizing(connectCountryCodes());
});

test('every country is named in every language', function () {
    foreach (MarketingContent::COUNTRIES as $code => $names) {
        foreach (MarketingContent::LOCALES as $locale) {
            expect($names[$locale] ?? '')->not->toBeEmpty("{$code} has no {$locale} name");
        }
    }
});

test('the committed catalogue is a real one, deduplicated per country', function () {
    $catalogue = json_decode((string) file_get_contents(base_path(IntegrationsPage::CATALOGUE)), true);

    expect($catalogue['source'] ?? '')->toContain('/aspsps')
        ->and($catalogue['countries'] ?? [])->not->toBeEmpty();

    foreach ($catalogue['countries'] as $code => $banks) {
        expect(MarketingContent::COUNTRIES)->toHaveKey($code)
            ->and($banks)->toBe(array_values(array_unique($banks)), "{$code} lists a bank twice")
            ->and($banks)->not->toContain(IntegrationsPage::TEST_INSTITUTION);
    }
});

test('the served page carries the bank names, which is the point of it', function (string $locale) {
    $countries = IntegrationsPage::countries($locale);
    $banks = collect($countries)->flatMap(fn (array $country): array => $country['banks']);
    $markdown = $this->get('/'.IntegrationsPage::path($locale).'.md')->assertSuccessful()->content();

    expect($banks)->not->toBeEmpty();

    foreach ($banks->take(25) as $bank) {
        expect($markdown)->toContain($bank);
    }

    foreach ($countries as $country) {
        expect($markdown)->toContain($country['name']);
    }
})->with(MarketingContent::LOCALES);

test('the prose counts match the catalogue rendered under it', function (string $locale) {
    $content = IntegrationsPage::content($locale);
    $countries = IntegrationsPage::countries($locale);
    $banks = array_sum(array_map(fn (array $country): int => count($country['banks']), $countries));

    expect($content['banks_intro'])->toContain(number_format($banks))
        ->and($content['banks_intro'])->toContain((string) count($countries))
        ->and($content['banks_intro'])->not->toContain(':count');
})->with(MarketingContent::LOCALES);

test('the page is sent only the chrome labels it uses, none with a stray placeholder', function () {
    $labels = IntegrationsPage::labels('en');

    expect(array_keys($labels))->toEqualCanonicalizing(['cta', 'pricing', 'back', 'other_language']);

    foreach ($labels as $key => $label) {
        expect($label)->not->toContain(':rival', "{$key} still carries a comparison placeholder");
    }
});

test('the filter counter keeps the placeholder the browser fills', function (string $locale) {
    // The server-side substitution walks every string, so a counter template
    // sharing :count with the prose would arrive pre-filled with the total and
    // never move off it, however the visitor filtered.
    $content = IntegrationsPage::content($locale);

    expect($content['matches'])->toContain(':matches')
        ->and($content['matches'])->not->toContain(':count')
        ->and($content['matches_one'])->not->toContain(':');
})->with(MarketingContent::LOCALES);

test('the sitemap lists both languages with their alternates, and no markdown twin', function () {
    $content = $this->get('/sitemap.xml')->assertSuccessful()->content();

    foreach (IntegrationsPage::alternates() as $locale => $url) {
        expect($content)->toContain("<loc>{$url}</loc>")
            ->and($content)->toContain('hreflang="'.$locale.'" href="'.$url.'"')
            ->and($content)->not->toContain("<loc>{$url}.md</loc>");
    }
});

test('llms txt lists the page in every language', function () {
    $content = $this->get('/llms.txt')->assertSuccessful()->content();

    foreach (MarketingContent::LOCALES as $locale) {
        expect($content)->toContain(IntegrationsPage::url($locale).'.md');
    }
});

test('the landing footer links the page in the visitor language', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrationsLink.path', '/integraciones')
            ->where('integrationsLink.heading', MarketingContent::integrations('es')['heading'])
        );
});

test('the sync command deduplicates, drops the provider test bank and writes provenance', function () {
    fakeInstitutions();

    $relative = 'storage/framework/testing/catalogue.json';

    $this->artisan('banks:sync-institutions', ['--path' => $relative, '--force' => true])->assertSuccessful();

    $written = json_decode((string) file_get_contents(base_path($relative)), true);

    expect($written['countries']['ES'])->toBe(['Abanca', 'BBVA'])
        ->and($written['countries']['GB'])->toBe([])
        ->and($written['source'])->toContain('/aspsps')
        ->and($written['generated_by'])->toContain('banks:sync-institutions')
        ->and($written['generated_at'])->not->toBeEmpty();

    unlink(base_path($relative));
});

test('the sync command refuses to overwrite when a country loses most of its banks', function () {
    // What a restricted provider app looks like: it answers, with almost nothing.
    fakeInstitutions();

    $relative = 'storage/framework/testing/catalogue.json';

    $this->artisan('banks:sync-institutions', ['--path' => $relative])
        ->expectsOutputToContain('Refusing to write')
        ->assertFailed();

    expect(is_file(base_path($relative)))->toBeFalse();
});
