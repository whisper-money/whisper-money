// The screenshots the public documentation embeds.
//
// Every shot is taken twice, light and dark, because a documentation page shows
// the copy that matches the reader's theme (see DocumentationMarkdown). They are
// always taken in English, whatever language the page embedding them is written
// in, and they are cropped to the component rather than the whole screen.
//
// The data comes from `demo:reset`, so the figures are plausible and the same
// every time instead of whatever happens to be in the local database.
//
// Prerequisites:
//   - `composer run dev` running.
//
// Usage:
//   node scripts/docs-screenshots.mjs                  # all of them
//   node scripts/docs-screenshots.mjs saved-filters    # just these
//   HEADLESS=0 node scripts/docs-screenshots.mjs       # watch it run
//   SKIP_SEED=1 node scripts/docs-screenshots.mjs      # reuse the seeded data

import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';

const OUT_DIR = 'public/docs/documentation';
const EMAIL = 'docs-screenshots@example.test';
const PASSWORD = 'password123456';
const ACCOUNT = 'Primary Checking';
// Narrow on purpose: a full-width filter bar shot at 1280 is a thin strip that
// reads as nothing once it is scaled into a 768px column of prose.
const VIEWPORT = { width: 900, height: 1000 };
const SCALE = 2;
const HEADLESS = process.env.HEADLESS !== '0';

// `composer run dev` serves on a random port behind a portless hostname that is
// not resolvable from here, so talk to `artisan serve` directly.
//
// The port has to belong to *this* checkout: several worktrees each run their own
// dev server, and shooting another branch's app would produce screenshots that
// look right and document code that is not there.
function detectBaseUrl() {
    if (process.env.APP_BASE_URL) {
        return process.env.APP_BASE_URL.replace(/\/$/, '');
    }

    const port = execFileSync(
        'bash',
        [
            '-c',
            `for pid in $(pgrep -f 'artisan serve --port=[0-9]'); do
                cwd=$(lsof -p "$pid" -a -d cwd -Fn 2>/dev/null | grep '^n' | cut -c2-)
                if [ "$cwd" = "$PWD" ]; then
                    ps -o command= -p "$pid" | grep -oE -- '--port=[0-9]+' | grep -oE '[0-9]+$'
                    break
                fi
            done`,
        ],
        { encoding: 'utf8' },
    ).trim();

    if (!port) {
        throw new Error(
            'No dev server for this checkout. Start it with `composer run dev` here, or pass APP_BASE_URL.',
        );
    }

    return `http://127.0.0.1:${port}`;
}

const BASE_URL = detectBaseUrl();

function artisan(args) {
    return execFileSync('php', ['artisan', ...args], {
        encoding: 'utf8',
    }).trim();
}

function log(message) {
    process.stdout.write(`  ${message}\n`);
}

function artisanJson(args) {
    const out = artisan(args);

    return JSON.parse(out.slice(out.indexOf('{')));
}

/**
 * A screenshot of the preview step is only worth taking if there is a duplicate
 * in it to be flagged, so the import file quotes a transaction the account
 * already holds: the duplicate check matches on account, date, amount and
 * description.
 */
function seededTransaction() {
    return artisanJson([
        'tinker',
        '--execute',
        `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
         $transaction = App\\Models\\Transaction::query()
             ->whereBelongsTo($user)
             ->whereHas('account', fn ($account) => $account->where('name', '${ACCOUNT}'))
             ->orderByDesc('transaction_date')
             ->firstOrFail();
         echo json_encode([
             'date' => $transaction->transaction_date->format('Y-m-d'),
             'description' => $transaction->description,
             'amount' => number_format($transaction->amount / 100, 2, '.', ''),
         ]);`,
    ]);
}

// Import files are written fresh rather than committed, so a screenshot of the
// mapping step can never drift from a fixture edited for some other reason.
function writeFixtures(existing) {
    const dir = mkdtempSync(join(tmpdir(), 'docs-shots-'));

    writeFileSync(
        join(dir, 'transactions.csv'),
        [
            'Date,Description,Amount,Balance',
            `${existing.date},${existing.description},${existing.amount},2841.55`,
            '2026-07-03,SPOTIFY PREMIUM,-10.99,2830.56',
            '2026-07-05,ACME CORP PAYROLL,3200.00,6030.56',
            '2026-07-08,SHELL SERVICE STATION,-61.40,5969.16',
            '2026-07-11,CITY TRANSIT MONTHLY,-75.00,5894.16',
            '2026-07-14,BLUE BOTTLE COFFEE,-6.75,5887.41',
        ].join('\n'),
        'utf8',
    );

    writeFileSync(
        join(dir, 'balances.csv'),
        [
            'Date,Balance',
            '05/03/2026,24180.00',
            '10/04/2026,25040.55',
            '08/05/2026,25890.10',
            '03/06/2026,26315.75',
            '12/07/2026,27102.40',
        ].join('\n'),
        'utf8',
    );

    return dir;
}

/**
 * One padded rectangle covering all of these elements: it lets a shot hold a
 * control together with the menu it opened even though the menu is portalled
 * somewhere else entirely, and it gives every shot the same breathing room,
 * which locator.screenshot() has no way to add.
 */
async function union(locators, padding = 14) {
    const viewport = locators[0].page().viewportSize();
    const boxes = (
        await Promise.all(locators.map((locator) => locator.boundingBox()))
    ).filter(Boolean);

    if (boxes.length === 0) {
        throw new Error('none of the elements to be captured are on screen');
    }

    const left = Math.max(0, Math.min(...boxes.map((b) => b.x)) - padding);
    const top = Math.max(0, Math.min(...boxes.map((b) => b.y)) - padding);
    const right = Math.max(...boxes.map((b) => b.x + b.width)) + padding;
    const bottom = Math.max(...boxes.map((b) => b.y + b.height)) + padding;

    return {
        x: left,
        y: top,
        width: Math.min(right, viewport.width) - left,
        height: Math.min(bottom, viewport.height) - top,
    };
}

const dialog = (page) => page.getByRole('dialog').last();

/**
 * A bottom drawer is as tall as the viewport whatever step it is showing, so
 * capturing the element itself is mostly empty space. Take its full width — it
 * spans the screen — but only the height between its heading and the button that
 * moves the step on.
 */
async function drawerClip(page) {
    const rows = await union([
        page.locator('[data-slot="drawer-header"]').last(),
        dialog(page).getByRole('button').last(),
    ]);

    return {
        x: 0,
        y: rows.y,
        width: page.viewportSize().width,
        height: rows.height,
    };
}

async function openTransactions(page) {
    await page.goto(`${BASE_URL}/transactions`, {
        waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: 'Filters', exact: true }).waitFor();

    const dismiss = page.getByRole('button', { name: 'Dismiss' });

    if ((await dismiss.count()) > 0) {
        await dismiss.first().click();
    }

    await page.waitForTimeout(1200);
}

/**
 * The transaction importer opens on the account it will import into, so every
 * shot of a later step walks through that first.
 */
async function openImportDrawer(page) {
    await openTransactions(page);
    await page.getByRole('button', { name: 'Import transactions' }).click();
    await dialog(page).waitFor();
    await page.waitForTimeout(800);
    await page.getByRole('radio', { name: new RegExp(ACCOUNT) }).click();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByText('Upload File').waitFor();
    await page.waitForTimeout(600);
}

async function openAccount(page) {
    await page.goto(`${BASE_URL}/accounts`, { waitUntil: 'domcontentloaded' });
    await page.getByText(ACCOUNT).first().click();
    await page.waitForURL(/\/accounts\//);
    await page.waitForTimeout(1500);
}

/**
 * Each shot leaves the app on the screen it wants and returns what to capture:
 * the elements to frame, or a rectangle when the element's own box is not what
 * should be in the picture.
 */
const SHOTS = {
    'transaction-filters': async (page) => {
        await openTransactions(page);
        await page
            .getByRole('button', { name: 'Filters', exact: true })
            .click();
        await page.locator('[data-test="transaction-filters-panel"]').waitFor();
        await page.waitForTimeout(500);

        return [
            page.locator('[data-test="transaction-filters"]'),
            page.locator('[data-test="transaction-filters-panel"]'),
        ];
    },

    'saved-filters': async (page) => {
        await openTransactions(page);
        // The trigger is addressed by attribute rather than by role: an open
        // Radix menu marks the rest of the page aria-hidden, which takes the
        // button it was opened from out of the accessibility tree.
        const trigger = page.locator('[aria-label="Saved filters"]');

        await trigger.click();
        await page.getByRole('menu').waitFor();
        await page.waitForTimeout(400);

        return [trigger, page.getByRole('menu')];
    },

    'bulk-actions-bar': async (page) => {
        // The bar stops shrinking before 900px and clips its own controls, so it
        // is the one shot taken at a desktop width.
        await page.setViewportSize({ width: 1280, height: 900 });
        await openTransactions(page);

        // Only the body's checkboxes: the one in the header selects everything,
        // which is a different state of the bar.
        const checkboxes = page.locator('tbody').getByRole('checkbox');
        const count = Math.min(await checkboxes.count(), 3);

        for (let index = 0; index < count; index++) {
            await checkboxes.nth(index).click();
        }

        const bar = page.locator('[data-test="bulk-actions-bar"]');
        await bar.waitFor();
        await page.waitForTimeout(600);

        return [bar];
    },

    'create-transaction-dialog': async (page) => {
        await openTransactions(page);
        await page.getByRole('button', { name: 'Add transaction' }).click();
        await dialog(page).waitFor();
        // The account is deliberately left unchosen: the picker renders every
        // account as "[Encrypted]" (accounts.encrypted is true on rows whose
        // name_iv is null), and a screenshot should not teach that as normal.
        await page
            .getByPlaceholder('Transaction description')
            .fill('Blue Bottle Coffee');
        await page.getByPlaceholder('25.00').fill('-6.75');
        await page.waitForTimeout(500);

        return [dialog(page)];
    },

    'import-transactions-upload': async (page, fixtures) => {
        await openImportDrawer(page);
        await page
            .locator('input[type="file"]')
            .setInputFiles(join(fixtures, 'transactions.csv'));
        await page.waitForTimeout(1200);

        return drawerClip(page);
    },

    'import-transactions-mapping': async (page, fixtures) => {
        await openImportDrawer(page);
        await page
            .locator('input[type="file"]')
            .setInputFiles(join(fixtures, 'transactions.csv'));
        await page.waitForTimeout(1200);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByText('Map Columns').waitFor();
        await page.waitForTimeout(1200);

        return drawerClip(page);
    },

    'import-transactions-preview': async (page, fixtures) => {
        await openImportDrawer(page);
        await page
            .locator('input[type="file"]')
            .setInputFiles(join(fixtures, 'transactions.csv'));
        await page.waitForTimeout(1200);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByText('Map Columns').waitFor();
        await page.waitForTimeout(1000);
        await page.getByRole('button', { name: 'Preview Transactions' }).click();
        await page.waitForTimeout(2000);

        return drawerClip(page);
    },

    'import-balances-mapping': async (page, fixtures) => {
        await openAccount(page);
        await page.getByRole('button', { name: 'Import balances' }).click();
        await dialog(page).waitFor();
        await page.waitForTimeout(800);
        await page
            .locator('input[type="file"]')
            .setInputFiles(join(fixtures, 'balances.csv'));
        await page.waitForTimeout(1200);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByText('Map Columns').waitFor();
        await page.waitForTimeout(1200);

        return drawerClip(page);
    },

    'balances-modal': async (page) => {
        await openAccount(page);
        await page.getByRole('button', { name: 'More options' }).click();
        await page.getByRole('menuitem', { name: /See balances/ }).click();
        await dialog(page).waitFor();
        await page.waitForTimeout(1500);

        return [dialog(page)];
    },

    'labels-settings': async (page) => {
        await page.goto(`${BASE_URL}/settings/labels`, {
            waitUntil: 'domcontentloaded',
        });

        const table = page.locator('[data-test="labels-table"]');
        await table.waitFor();
        await page.waitForTimeout(1000);

        return [table];
    },
};

async function login(page) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('textbox', { name: 'Email address' }).fill(EMAIL);
    await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 20000,
    });
}

async function launchBrowser() {
    try {
        return await chromium.launch({ headless: HEADLESS });
    } catch {
        // The bundled headless shell is a slow download on a fresh machine;
        // the system Chrome is already there.
        return await chromium.launch({ headless: HEADLESS, channel: 'chrome' });
    }
}

async function main() {
    const wanted = process.argv.slice(2);
    const names = wanted.length > 0 ? wanted : Object.keys(SHOTS);

    for (const name of names) {
        if (!SHOTS[name]) {
            throw new Error(
                `Unknown shot "${name}". Known: ${Object.keys(SHOTS).join(', ')}`,
            );
        }
    }

    if (process.env.SKIP_SEED !== '1') {
        log(`seeding ${EMAIL}`);
        artisan(['demo:reset', `--email=${EMAIL}`, `--password=${PASSWORD}`]);
        // The screenshots are always English, whatever the reader's language.
        artisan([
            'tinker',
            '--execute',
            `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
             $user->update(['locale' => 'en']);
             App\\Models\\SavedFilter::query()->where('user_id', $user->id)->delete();
             foreach ([
                 'Coffee shops' => ['search' => 'coffee'],
                 'Groceries' => ['search' => 'market'],
                 'Subscriptions' => ['search' => 'spotify'],
             ] as $name => $filters) {
                 App\\Models\\SavedFilter::query()->create([
                     'user_id' => $user->id,
                     'name' => $name,
                     'filters' => $filters,
                 ]);
             }`,
        ]);
    }

    mkdirSync(OUT_DIR, { recursive: true });

    const fixtures = writeFixtures(seededTransaction());
    const browser = await launchBrowser();
    const failures = [];

    for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({
            viewport: VIEWPORT,
            deviceScaleFactor: SCALE,
            colorScheme: theme,
            locale: 'en-US',
            // The app resolves its theme from this cookie before it consults the
            // operating system, so setting both leaves nothing to chance.
            storageState: {
                cookies: [
                    {
                        name: 'appearance',
                        value: theme,
                        domain: '127.0.0.1',
                        path: '/',
                        expires: -1,
                        httpOnly: false,
                        secure: false,
                        sameSite: 'Lax',
                    },
                ],
                origins: [],
            },
        });

        const page = await context.newPage();
        await login(page);

        for (const name of names) {
            const suffix = theme === 'dark' ? '-dark' : '';
            const path = `${OUT_DIR}/${name}${suffix}.png`;

            try {
                await page.setViewportSize(VIEWPORT);

                const target = await SHOTS[name](page, fixtures);
                const clip = Array.isArray(target)
                    ? await union(target)
                    : target;

                await page.screenshot({ path, clip });

                log(`${theme.padEnd(5)} ${path}`);
            } catch (error) {
                failures.push(`${name} (${theme}): ${error.message}`);
                log(`${theme.padEnd(5)} FAILED ${name}: ${error.message}`);
            }
        }

        await context.close();
    }

    await browser.close();

    if (failures.length > 0) {
        process.stdout.write(`\n${failures.length} shot(s) failed:\n`);
        failures.forEach((failure) => process.stdout.write(`  - ${failure}\n`));
        process.exitCode = 1;
    }
}

await main();
