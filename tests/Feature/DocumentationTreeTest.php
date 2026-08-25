<?php

use App\Support\Documentation\DocumentationTree;
use Illuminate\Support\Facades\File;

/**
 * The file layout is the navigation, so these tests are written against real
 * files: a fixture tree is written to disk, pointed at, and read back.
 */
const DOCUMENTATION_SCRATCH = 'tests/.documentation';

/**
 * @param  array<string, string>  $files  path inside the tree => contents
 */
function documentationFixture(array $files): string
{
    $root = base_path(DOCUMENTATION_SCRATCH);

    File::deleteDirectory($root);

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$root}/{$path}"));
        File::put("{$root}/{$path}", $contents);
    }

    config(['documentation.root' => $root]);

    return $root;
}

function documentationPage(string $title, string $description = 'What this page is about.'): string
{
    return "# {$title}\n\n{$description}\n\n{{TOC}}\n\n## A section\n\nBody.\n";
}

/**
 * @return array<int, string>
 */
function slugsOf(DocumentationTree $tree): array
{
    return array_column($tree->pages(), 'slug');
}

afterEach(function () {
    File::deleteDirectory(base_path(DOCUMENTATION_SCRATCH));
});

it('reads a page from its file, and its title and description from the markdown', function () {
    documentationFixture([
        'getting-started-en.md' => documentationPage('Getting started', 'How to begin.'),
    ]);

    $page = DocumentationTree::for('en')->find('getting-started');

    expect($page)->not->toBeNull()
        ->and($page['type'])->toBe('page')
        ->and($page['title'])->toBe('Getting started')
        ->and($page['description'])->toBe('How to begin.');
});

it('serves the page in the requested language when there is a file for it', function () {
    documentationFixture([
        'accounts-en.md' => documentationPage('Accounts'),
        'accounts-es.md' => documentationPage('Cuentas', 'De qué va esta página.'),
    ]);

    expect(DocumentationTree::for('es')->find('accounts')['title'])->toBe('Cuentas')
        ->and(DocumentationTree::for('en')->find('accounts')['title'])->toBe('Accounts');
});

it('falls back to English when the language has no file of its own', function () {
    documentationFixture([
        'accounts-en.md' => documentationPage('Accounts'),
    ]);

    expect(DocumentationTree::for('es')->find('accounts')['title'])->toBe('Accounts');
});

it('does not publish a page that has no English file', function () {
    documentationFixture([
        'accounts-en.md' => documentationPage('Accounts'),
        'presupuestos-es.md' => documentationPage('Presupuestos'),
    ]);

    expect(slugsOf(DocumentationTree::for('es')))->toBe(['accounts'])
        ->and(DocumentationTree::for('es')->find('presupuestos'))->toBeNull();
});

it('groups pages under the section that names them, in the language asked for', function () {
    documentationFixture([
        'your-data/section.md' => "- en: Your data\n- es: Tus datos\n",
        'your-data/accounts-en.md' => documentationPage('Accounts'),
    ]);

    $spanish = DocumentationTree::for('es')->nodes();

    expect($spanish)->toHaveCount(1)
        ->and($spanish[0]['type'])->toBe('section')
        ->and($spanish[0]['id'])->toBe('your-data')
        ->and($spanish[0]['title'])->toBe('Tus datos')
        ->and($spanish[0]['children'][0]['slug'])->toBe('accounts')
        ->and(DocumentationTree::for('en')->nodes()[0]['title'])->toBe('Your data');
});

it('names a section after its directory when it has no section file', function () {
    documentationFixture([
        'your-data/accounts-en.md' => documentationPage('Accounts'),
    ]);

    expect(DocumentationTree::for('en')->nodes()[0]['title'])->toBe('Your Data');
});

it('nests the pages of a directory named after a page under that page', function () {
    documentationFixture([
        'your-data/section.md' => "- en: Your data\n",
        'your-data/accounts-en.md' => documentationPage('Accounts'),
        'your-data/accounts/account-types-en.md' => documentationPage('Account types'),
        'your-data/accounts/account-types/savings-en.md' => documentationPage('Savings'),
    ]);

    $accounts = DocumentationTree::for('en')->nodes()[0]['children'][0];

    expect($accounts['slug'])->toBe('accounts')
        ->and($accounts['children'])->toHaveCount(1)
        ->and($accounts['children'][0]['slug'])->toBe('accounts/account-types')
        ->and($accounts['children'][0]['children'][0]['slug'])->toBe('accounts/account-types/savings');
});

it('keeps sections out of the slug, because a section only groups the sidebar', function () {
    documentationFixture([
        '20-your-data/section.md' => "- en: Your data\n",
        '20-your-data/10-accounts-en.md' => documentationPage('Accounts'),
        '20-your-data/10-accounts/10-balances-en.md' => documentationPage('Balances'),
    ]);

    expect(slugsOf(DocumentationTree::for('en')))->toBe(['accounts', 'accounts/balances']);
});

it('orders entries by their numeric prefix and keeps the prefix out of the slug', function () {
    documentationFixture([
        '10-getting-started-en.md' => documentationPage('Getting started'),
        '30-analysis/section.md' => "- en: Analysis\n",
        '30-analysis/10-cashflow-en.md' => documentationPage('Cashflow'),
        '20-your-data/section.md' => "- en: Your data\n",
        '20-your-data/20-transactions-en.md' => documentationPage('Transactions'),
        '20-your-data/10-accounts-en.md' => documentationPage('Accounts'),
    ]);

    expect(slugsOf(DocumentationTree::for('en')))
        ->toBe(['getting-started', 'accounts', 'transactions', 'cashflow']);
});

it('sorts entries without a prefix by name, after the ones that asked for a position', function () {
    documentationFixture([
        'zebra-en.md' => documentationPage('Zebra'),
        'apple-en.md' => documentationPage('Apple'),
        '10-first-en.md' => documentationPage('First'),
    ]);

    expect(slugsOf(DocumentationTree::for('en')))->toBe(['first', 'apple', 'zebra']);
});

it('flattens nested pages in reading order, so neighbours are neighbours', function () {
    documentationFixture([
        '10-getting-started-en.md' => documentationPage('Getting started'),
        '20-your-data/section.md' => "- en: Your data\n",
        '20-your-data/10-accounts-en.md' => documentationPage('Accounts'),
        '20-your-data/10-accounts/10-types-en.md' => documentationPage('Types'),
        '20-your-data/20-transactions-en.md' => documentationPage('Transactions'),
    ]);

    expect(slugsOf(DocumentationTree::for('en')))
        ->toBe(['getting-started', 'accounts', 'accounts/types', 'transactions']);
});

it('ignores files that are not language-suffixed markdown', function () {
    documentationFixture([
        'accounts-en.md' => documentationPage('Accounts'),
        'README.md' => "# Not a page\n",
        'notes.txt' => 'Not markdown.',
    ]);

    expect(slugsOf(DocumentationTree::for('en')))->toBe(['accounts']);
});

it('has an English file for every page that ships', function () {
    $english = slugsOf(DocumentationTree::for('en'));

    expect($english)->not->toBeEmpty();

    foreach (array_keys((array) config('documentation.locales')) as $locale) {
        expect(slugsOf(DocumentationTree::for((string) $locale)))->toBe($english);
    }
});

it('gives every page that ships a slug of its own', function () {
    $slugs = slugsOf(DocumentationTree::for('en'));

    expect($slugs)->toBe(array_values(array_unique($slugs)));
});

it('has a title and a description for every page that ships', function () {
    foreach (array_keys((array) config('documentation.locales')) as $locale) {
        foreach (DocumentationTree::for((string) $locale)->pages() as $page) {
            expect($page['title'])->not->toBe('')
                ->and($page['description'])->not->toBe('');
        }
    }
});
