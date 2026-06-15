<?php

use PHPUnit\Framework\Assert;

/**
 * Localization completeness tests.
 *
 * Spanish is the reference locale and is strictly enforced: any English
 * string without a Spanish translation fails the suite, preventing
 * untranslated content from reaching production.
 *
 * French is an optional, community-maintained locale. Its gaps only emit a
 * warning — an incomplete French translation never blocks CI, but the run
 * still surfaces exactly which keys are missing so they can be filled in.
 */

/**
 * Locales whose translation gaps must fail the suite.
 *
 * @var array<string>
 */
const ENFORCED_LOCALES = ['es'];

/**
 * Locales whose translation gaps only emit a warning.
 *
 * @var array<string>
 */
const OPTIONAL_LOCALES = ['fr'];

/**
 * Recursively flattens a nested PHP translation array using dot notation.
 *
 * @param  array<string, mixed>  $array
 * @param  array<string, string>  $result
 * @return array<string, string>
 */
function flattenTranslations(array $array, string $prefix = '', array $result = []): array
{
    foreach ($array as $key => $value) {
        $fullKey = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;

        if (is_array($value)) {
            $result = flattenTranslations($value, $fullKey, $result);
        } else {
            $result[$fullKey] = (string) $value;
        }
    }

    return $result;
}

/**
 * Extracts all static string keys passed to the __() i18n function from
 * TypeScript/TSX source files. Only literal string arguments are captured;
 * dynamic expressions are intentionally skipped.
 *
 * @return array<string>
 */
function extractI18nKeysFromSource(): array
{
    $resourcesPath = base_path('resources/js');
    $keys = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resourcesPath, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], strict: true)) {
            continue;
        }

        $content = file_get_contents($file->getPathname());

        if ($content === false) {
            continue;
        }

        // Match __('single-quoted key') and __("double-quoted key")
        // Skips template literals and variable references by design.
        preg_match_all("/__\(\s*'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'\s*[,)]/", $content, $singleQuoted);
        preg_match_all('/__\(\s*"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"\s*[,)]/', $content, $doubleQuoted);

        $keys = array_merge($keys, $singleQuoted[1], $doubleQuoted[1]);
    }

    return array_unique($keys);
}

/**
 * Computes the PHP translation keys present in lang/en but missing from the
 * given locale, grouped by filename. A wholly absent file is reported as the
 * sentinel "__missing_file__".
 *
 * @return array<string, array<string>>
 */
function missingPhpTranslationKeys(string $locale): array
{
    $englishDir = lang_path('en');
    $missingByFile = [];

    /** @var SplFileInfo $file */
    foreach (new FilesystemIterator($englishDir, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $filename = $file->getFilename();
        $localePath = lang_path("{$locale}/{$filename}");

        if (! file_exists($localePath)) {
            $missingByFile[$filename] = ['__missing_file__'];

            continue;
        }

        $englishKeys = flattenTranslations(require $file->getPathname());
        $localeKeys = flattenTranslations(require $localePath);
        $missingKeys = array_keys(array_diff_key($englishKeys, $localeKeys));

        if ($missingKeys !== []) {
            $missingByFile[$filename] = $missingKeys;
        }
    }

    return $missingByFile;
}

/**
 * Renders a human-readable report of missing PHP translation keys.
 *
 * @param  array<string, array<string>>  $missingByFile
 */
function formatPhpTranslationReport(string $locale, array $missingByFile): string
{
    return implode("\n", array_map(
        fn (string $f, array $keys) => "lang/{$locale}/{$f}:\n  - ".implode("\n  - ", $keys),
        array_keys($missingByFile),
        $missingByFile,
    ));
}

/**
 * Computes the __() source keys missing from the given locale's JSON file.
 * A missing or malformed file is reported via a sentinel entry.
 *
 * @return array<string>
 */
function missingJsonTranslationKeys(string $locale): array
{
    $jsonPath = lang_path("{$locale}.json");

    if (! file_exists($jsonPath)) {
        return ['__missing_file__'];
    }

    $json = json_decode(file_get_contents($jsonPath), associative: true);

    if (! is_array($json)) {
        return ['__invalid_json__'];
    }

    $sourceKeys = extractI18nKeysFromSource();
    $missingKeys = array_values(array_filter(
        $sourceKeys,
        fn (string $key) => ! array_key_exists($key, $json)
    ));

    sort($missingKeys);

    return $missingKeys;
}

describe('enforced PHP translations', function () {
    it('has every English PHP key translated', function (string $locale) {
        $missingByFile = missingPhpTranslationKeys($locale);

        expect($missingByFile)->toBeEmpty(
            "{$locale} PHP translation files have missing keys:\n".formatPhpTranslationReport($locale, $missingByFile)
        );
    })->with(ENFORCED_LOCALES);
});

describe('enforced JSON translations', function () {
    it('has every __() source key translated', function (string $locale) {
        expect(lang_path("{$locale}.json"))->toBeFile("Missing lang/{$locale}.json translation file");

        $missingKeys = missingJsonTranslationKeys($locale);

        expect($missingKeys)->toBeEmpty(
            count($missingKeys)." key(s) used in source via __() are missing from lang/{$locale}.json:\n  - ".implode("\n  - ", $missingKeys)
        );
    })->with(ENFORCED_LOCALES);
});

describe('optional PHP translations', function () {
    it('warns about untranslated PHP keys', function (string $locale) {
        $missingByFile = missingPhpTranslationKeys($locale);

        if ($missingByFile !== []) {
            // Optional locale: surface the gap as an incomplete test so it stays
            // visible in the run summary without failing CI (no failOnIncomplete
            // is configured).
            Assert::markTestIncomplete(
                "Optional locale '{$locale}' has missing PHP translation keys (not blocking):\n"
                .formatPhpTranslationReport($locale, $missingByFile)
            );
        }

        expect($missingByFile)->toBeEmpty();
    })->with(OPTIONAL_LOCALES);
});

describe('optional JSON translations', function () {
    it('warns about __() source keys missing from the locale JSON', function (string $locale) {
        $missingKeys = missingJsonTranslationKeys($locale);

        if ($missingKeys !== []) {
            // Optional locale: surface the gap as an incomplete test so it stays
            // visible in the run summary without failing CI (no failOnIncomplete
            // is configured).
            Assert::markTestIncomplete(
                count($missingKeys)." key(s) used in source via __() are missing from lang/{$locale}.json (not blocking):\n  - "
                .implode("\n  - ", $missingKeys)
            );
        }

        expect($missingKeys)->toBeEmpty();
    })->with(OPTIONAL_LOCALES);
});
