<?php

/**
 * Localization completeness tests.
 *
 * These tests ensure every translatable string used in the application
 * has a corresponding Spanish translation, preventing untranslated content
 * from reaching production.
 */

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

describe('Spanish PHP translation files', function () {
    it('has every key from the English PHP files translated', function () {
        $englishDir = lang_path('en');
        $missingByFile = [];

        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator($englishDir, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilename();
            $spanishPath = lang_path("es/{$filename}");

            if (! file_exists($spanishPath)) {
                $missingByFile[$filename] = ['__missing_file__'];

                continue;
            }

            $englishKeys = flattenTranslations(require $file->getPathname());
            $spanishKeys = flattenTranslations(require $spanishPath);
            $missingKeys = array_keys(array_diff_key($englishKeys, $spanishKeys));

            if ($missingKeys !== []) {
                $missingByFile[$filename] = $missingKeys;
            }
        }

        $report = implode("\n", array_map(
            fn (string $f, array $keys) => "lang/es/{$f}:\n  - ".implode("\n  - ", $keys),
            array_keys($missingByFile),
            $missingByFile,
        ));

        expect($missingByFile)->toBeEmpty(
            "Spanish PHP translation files have missing keys:\n{$report}"
        );
    });
});

describe('Spanish JSON translations', function () {
    it('has every __() key from TypeScript source files translated in es.json', function () {
        $spanishJsonPath = lang_path('es.json');

        expect($spanishJsonPath)->toBeFile('Missing lang/es.json translation file');

        $spanishJson = json_decode(file_get_contents($spanishJsonPath), associative: true);

        expect($spanishJson)->toBeArray('lang/es.json must contain a valid JSON object');

        $sourceKeys = extractI18nKeysFromSource();
        $missingKeys = array_values(array_filter(
            $sourceKeys,
            fn (string $key) => ! array_key_exists($key, $spanishJson)
        ));

        sort($missingKeys);

        expect($missingKeys)
            ->toBeEmpty(
                count($missingKeys)." key(s) used in source via __() are missing from lang/es.json:\n  - ".implode("\n  - ", $missingKeys)
            );
    });
});
