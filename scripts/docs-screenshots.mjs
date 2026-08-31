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

import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';
import {
    artisan,
    artisanJson,
    detectBaseUrl,
    EMAIL,
    launchBrowser,
    log,
    login,
    OUT_DIR,
    seedAccount,
    themeCookie,
} from './docs-media.mjs';

const ACCOUNT = 'Primary Checking';
const GOAL_NAME = 'New kitchen';
const CONTRIBUTION = 'Transfer to savings';
// Narrow on purpose: a full-width filter bar shot at 1280 is a thin strip that
// reads as nothing once it is scaled into a 768px column of prose.
const VIEWPORT = { width: 900, height: 1000 };
const SCALE = 2;

const BASE_URL = detectBaseUrl();

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

/**
 * Splits and savings goals are both still behind a feature flag that resolves
 * false, and `demo:reset` seeds neither one, so their screens would be a 404 and
 * an empty chart. They are seeded here rather than in app/Services/Demo, which
 * is the public demo account and is governed by its own rules.
 *
 * The goal's contributions are written rather than borrowed from the seeded
 * transactions: six even monthly transfers draw a saving pace, which is the
 * thing the progress screenshot exists to show, and a handful of random
 * expenses would draw a zigzag that teaches nothing.
 */
function seedFeatures() {
    return artisan([
        'tinker',
        '--execute',
        `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
         Laravel\\Pennant\\Feature::for($user)->activate(App\\Features\\SplitTransactions::class);
         Laravel\\Pennant\\Feature::for($user)->activate(App\\Features\\SavingsGoals::class);

         // demo:reset clears labels but not goals, so a re-run would otherwise
         // pile up goals whose label no longer exists.
         App\\Models\\SavingsGoal::query()->where('user_id', $user->id)->forceDelete();

         $label = $user->labels()->create([
             'name' => '${GOAL_NAME}',
             'color' => App\\Enums\\LabelColor::Emerald->value,
             'source' => App\\Enums\\LabelSource::SavingsGoal,
         ]);
         $goal = $user->savingsGoals()->create([
             'label_id' => $label->id,
             'name' => '${GOAL_NAME}',
             'target_amount' => 600000,
             'initial_amount' => 120000,
             'target_date' => now()->addMonths(6)->toDateString(),
         ]);
         // Half a year of saving already behind it, so the chart has a line to
         // draw and the projection has a pace to extend.
         $goal->forceFill(['created_at' => now()->subMonths(6)])->save();

         $account = $user->accounts()->where('name', '${ACCOUNT}')->firstOrFail();
         $savings = $user->categories()->where('type', 'savings')->first();

         // Created through the model, events and all: no seeded automation rule
         // matches "transfer", and the category is set here, so nothing re-files
         // these. A rule provider that grows one would break that.
         foreach (range(5, 0) as $month) {
             $account->transactions()->create([
                 'user_id' => $user->id,
                 'category_id' => $savings?->id,
                 'description' => '${CONTRIBUTION}',
                 'description_iv' => null,
                 'transaction_date' => now()->subMonthsNoOverflow($month)->startOfDay(),
                 'amount' => -40000,
                 'currency_code' => $user->currency_code ?? 'USD',
             ])->labels()->attach($label->id);
         }

         // A grocery run, so that splitting it between groceries and a present
         // reads as something somebody would actually do. Split through the
         // service, so the seeded split obeys the invariants a real one does.
         $original = App\\Models\\Transaction::query()
             ->whereBelongsTo($user)
             ->whereBelongsTo($account)
             ->whereNull('split_parent_id')
             ->whereHas('category', fn ($category) => $category->where('name', 'Groceries'))
             ->where('amount', '<', -3000)
             ->orderByDesc('transaction_date')
             ->firstOrFail();
         $share = (int) round($original->amount * 0.7);
         $parts = app(App\\Services\\TransactionSplitter::class)->split($original, [
             ['amount' => $share],
             ['amount' => $original->amount - $share],
         ]);

         // The categories go straight onto the rows afterwards, through the
         // query builder so no model events fire. A demo automation rule
         // matches this merchant and files every new transaction it sees under
         // one category — which is the one thing this screenshot must not show,
         // since the whole point is that each part gets its own.
         $categories = $user->categories()->whereIn('name', ['Groceries', 'Gifts'])->pluck('id', 'name');
         foreach (['Groceries', 'Gifts'] as $index => $name) {
             App\\Models\\Transaction::whereKey($parts[$index]->id)->update([
                 'category_id' => $categories[$name] ?? null,
                 'category_source' => App\\Enums\\CategorySource::Manual->value,
                 'categorized_by_rule_id' => null,
             ]);
         }`,
    ]);
}

/**
 * The goal to shoot and the description the split parts inherited, read back
 * rather than returned by the seed so that SKIP_SEED=1 can reuse what the last
 * run left behind.
 */
function seededFeatures() {
    return artisanJson([
        'tinker',
        '--execute',
        `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
         echo json_encode([
             'goal' => App\\Models\\SavingsGoal::query()->where('user_id', $user->id)->value('id'),
             'split' => App\\Models\\Transaction::query()
                 ->whereBelongsTo($user)
                 ->whereNotNull('split_parent_id')
                 ->value('description'),
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

/**
 * The mark a row carries when it is one part of a split, and the trigger for
 * the popover listing the rest of them.
 */
const SPLIT_MARK = '[aria-label="This transaction is part of a split"]';

/**
 * Give the next part of a split its category. The label picker beside it is a
 * combobox too, so the category ones are told apart by the placeholder they are
 * still showing — which also means each call lands on the next part along.
 */
async function pickCategory(page, name) {
    await dialog(page)
        .getByRole('combobox')
        .filter({ hasText: 'Uncategorized' })
        .first()
        .click();
    await page.getByPlaceholder('Search categories...').fill(name);
    await page
        .getByRole('option', { name: new RegExp(name) })
        .first()
        .click();
    await page.waitForTimeout(300);
}

async function openSavingsGoal(page, features) {
    await page.goto(`${BASE_URL}/savings-goals/${features.goal}`, {
        waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: 'Link transactions' }).waitFor();
    // The pointer lands mid-page on navigation, which is over the progress
    // chart, and the chart answers with a tooltip that has no business being in
    // the picture.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(1200);
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

    'split-transaction-dialog': async (page) => {
        // The row menu the split is reached from lives in a column the table
        // drops below desktop width.
        await page.setViewportSize({ width: 1280, height: 900 });
        await openTransactions(page);

        // The table groups rows under a date heading that is a row of its own,
        // so a row is only a transaction if it carries the actions menu. And it
        // must not already be a part: the menu on a part offers merging the
        // split back, not splitting again.
        const row = page
            .locator('tbody tr')
            .filter({ has: page.getByRole('button', { name: 'Open menu' }) })
            .filter({ hasNot: page.locator(SPLIT_MARK) })
            .filter({ hasText: 'Groceries' })
            .first();

        await row.getByRole('button', { name: 'Open menu' }).click();
        await page.getByRole('menuitem', { name: 'Split', exact: true }).click();
        await dialog(page).waitFor();
        await page.waitForTimeout(600);

        await pickCategory(page, 'Groceries');
        await pickCategory(page, 'Gifts');

        // Typing one amount and handing over the remainder is what makes the
        // shot balanced without the script having to know what the row was
        // worth: the dialog does that arithmetic itself.
        await page.locator('#split-amount-0').fill('20.00');
        await page
            .getByRole('button', { name: /Give the rest to split/ })
            .click();
        await page.waitForTimeout(500);

        return [dialog(page)];
    },

    'split-parts-list': async (page, fixtures, features) => {
        // The row is wider than 900px and would be cut off mid-description.
        await page.setViewportSize({ width: 1280, height: 900 });
        await openTransactions(page);

        const row = page
            .locator('tbody tr')
            .filter({ has: page.locator(SPLIT_MARK) })
            .first();
        await row.waitFor();
        await row.locator(SPLIT_MARK).click();

        // Only the row that was clicked, not both parts: the popover opens
        // downwards over whatever follows, so framing the pair would mean
        // framing one row and the thing covering the other.
        const popover = page.locator('[data-slot="popover-content"]');
        await popover.waitFor();
        await page.waitForTimeout(500);

        log(`split parts of "${features.split}"`);

        // Clipped rather than framed: rows sit flush against each other, so the
        // padding union() adds would put a sliced-off half of the row above
        // into the picture.
        const framed = await union([row, popover]);
        const top = (await row.boundingBox()).y;

        return { ...framed, y: top, height: framed.y + framed.height - top };
    },

    'savings-goal-progress': async (page, fixtures, features) => {
        await openSavingsGoal(page, features);

        const cards = page.locator('[data-slot="card"]');
        await cards.nth(1).waitFor();
        // The chart animates its line in, so it is given time to settle before
        // the shutter rather than caught halfway.
        await page.waitForTimeout(2500);

        return [cards.nth(0), cards.nth(1)];
    },

    'savings-goal-link-transactions': async (page, fixtures, features) => {
        // The dialog grows to fill the viewport, and a full 1000px of tick
        // boxes is a tower of repeated rows by the time it reaches a column of
        // prose. Eight rows say the same thing.
        await page.setViewportSize({ width: 900, height: 720 });
        await openSavingsGoal(page, features);
        await page.getByRole('button', { name: 'Link transactions' }).click();
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
        seedAccount();
        artisan([
            'tinker',
            '--execute',
            `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
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

        log('seeding a savings goal and a split');
        seedFeatures();
    }

    mkdirSync(OUT_DIR, { recursive: true });

    const fixtures = writeFixtures(seededTransaction());
    const features = seededFeatures();
    const browser = await launchBrowser();
    const failures = [];

    for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({
            viewport: VIEWPORT,
            deviceScaleFactor: SCALE,
            colorScheme: theme,
            locale: 'en-US',
            storageState: themeCookie(theme),
        });

        const page = await context.newPage();
        await login(page, BASE_URL);

        for (const name of names) {
            const suffix = theme === 'dark' ? '-dark' : '';
            const path = `${OUT_DIR}/${name}${suffix}.png`;

            try {
                await page.setViewportSize(VIEWPORT);

                const target = await SHOTS[name](page, fixtures, features);
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
