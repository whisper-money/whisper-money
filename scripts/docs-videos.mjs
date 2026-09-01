// The screen recordings the public documentation embeds.
//
// Same account and same seeded data as the screenshots (see docs-media.mjs), and
// English for the same reason. Unlike the screenshots there is one copy rather
// than a light and a dark one: a video is two orders of magnitude heavier than a
// PNG, and dark reads well enough on a light page.
//
// A recording is a demo, not a test: the pointer is drawn into the page, moves
// between clicks and waits long enough for a reader to follow what happened.
//
// Prerequisites:
//   - `composer run dev` running.
//   - ffmpeg on PATH (Playwright records webm; the pages embed mp4).
//
// Usage:
//   node scripts/docs-videos.mjs                          # all of them
//   node scripts/docs-videos.mjs savings-goal-create      # just this one
//   HEADLESS=0 node scripts/docs-videos.mjs               # watch it run
//   SKIP_SEED=1 node scripts/docs-videos.mjs              # reuse the seeded data

import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';
import {
    artisan,
    detectBaseUrl,
    EMAIL,
    launchBrowser,
    log,
    login,
    OUT_DIR,
    seedAccount,
    themeCookie,
} from './docs-media.mjs';

const SAVINGS_ACCOUNT = 'Bike Savings';
const GOAL = 'Ducati Panigale V2';
const RULE = 'Ducati fund';
// 720p-ish: wide enough for the sidebar and a dialog to sit side by side, small
// enough that a minute of screen weighs about a megabyte.
const VIEWPORT = { width: 1280, height: 800 };
const BASE_URL = detectBaseUrl();

/**
 * The savings account this demo is about. `demo:reset` spreads its merchants
 * across the accounts at random, so the seeded savings account holds a coffee
 * run and a Netflix charge — nothing a rule should sweep into a goal. It is
 * emptied and given the only two things such an account really sees: the
 * standing order that feeds it and the interest it pays.
 */
function seedSavingsAccount() {
    log(`seeding ${SAVINGS_ACCOUNT}`);

    artisan([
        'tinker',
        '--execute',
        `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
         Laravel\\Pennant\\Feature::for($user)->activate(App\\Features\\SavingsGoals::class);

         // demo:reset clears labels but not goals, so a re-run would otherwise
         // pile up goals whose label no longer exists.
         App\\Models\\SavingsGoal::query()->where('user_id', $user->id)->forceDelete();
         $user->automationRules()->where('title', '${RULE}')->forceDelete();

         $account = $user->accounts()->where('type', 'savings')->firstOrFail();
         $account->update(['name' => '${SAVINGS_ACCOUNT}']);
         $account->transactions()->forceDelete();

         $transfer = $user->categories()->where('name', 'Own account')->value('id');
         $interest = $user->categories()->where('name', 'Other incoming payments')->value('id');

         // Even monthly transfers, so the goal's progress chart draws a saving
         // pace rather than the zigzag a handful of random amounts would.
         foreach (range(11, 0) as $month) {
             $account->transactions()->create([
                 'user_id' => $user->id,
                 'category_id' => $transfer,
                 'description' => 'Transfer from Primary Checking',
                 'transaction_date' => now()->subMonthsNoOverflow($month)->startOfMonth()->addDays(8),
                 'amount' => 20000,
                 'currency_code' => $user->currency_code ?? 'USD',
             ]);
         }

         foreach ([9, 6, 3, 0] as $month) {
             $account->transactions()->create([
                 'user_id' => $user->id,
                 'category_id' => $interest,
                 'description' => 'Savings interest',
                 'transaction_date' => now()->subMonthsNoOverflow($month)->startOfMonth()->addDays(2),
                 'amount' => 1450,
                 'currency_code' => $user->currency_code ?? 'USD',
             ]);
         }`,
    ]);
}

/** The goal the second video starts from, as the first video leaves it. */
function seedGoal() {
    artisan([
        'tinker',
        '--execute',
        `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
         App\\Models\\SavingsGoal::query()->where('user_id', $user->id)->forceDelete();
         $user->labels()->where('name', '${GOAL}')->forceDelete();

         $label = $user->labels()->create([
             'name' => '${GOAL}',
             'color' => App\\Enums\\LabelColor::Emerald->value,
             'source' => App\\Enums\\LabelSource::SavingsGoal,
         ]);
         $user->savingsGoals()->create([
             'label_id' => $label->id,
             'name' => '${GOAL}',
             'target_amount' => 1700000,
             'initial_amount' => 1200000,
             'target_date' => now()->addYear()->endOfMonth()->toDateString(),
         ]);`,
    ]);
}

/** Everything the second video needs gone, so it can be created on camera. */
function resetRule() {
    artisan([
        'tinker',
        '--execute',
        `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
         $user->automationRules()->where('title', '${RULE}')->forceDelete();
         $goal = App\\Models\\SavingsGoal::query()->where('user_id', $user->id)->firstOrFail();
         App\\Models\\LabelTransaction::query()->where('label_id', $goal->label_id)->delete();`,
    ]);
}

/**
 * Playwright's recorder does not capture the OS cursor, so clicks would happen
 * with nothing pointing at them. This draws one and follows the mouse.
 */
function drawPointer() {
    const dot = document.createElement('div');
    dot.id = 'docs-video-pointer';
    dot.style.cssText = [
        'position:fixed',
        'left:-50px',
        'top:-50px',
        'width:22px',
        'height:22px',
        'border-radius:50%',
        'background:rgba(255,255,255,.92)',
        'box-shadow:0 0 0 2px rgba(0,0,0,.35), 0 2px 10px rgba(0,0,0,.5)',
        'pointer-events:none',
        'z-index:2147483647',
        'transform:translate(-50%,-50%)',
        'transition:width .08s, height .08s',
    ].join(';');

    const attach = () => {
        document.body.appendChild(dot);
        addEventListener(
            'mousemove',
            (event) => {
                dot.style.left = `${event.clientX}px`;
                dot.style.top = `${event.clientY}px`;
            },
            true,
        );
        addEventListener(
            'mousedown',
            () => {
                dot.style.width = dot.style.height = '14px';
            },
            true,
        );
        addEventListener(
            'mouseup',
            () => {
                dot.style.width = dot.style.height = '22px';
            },
            true,
        );
    };

    if (document.body) {
        attach();
    } else {
        addEventListener('DOMContentLoaded', attach);
    }
}

const pause = (page, ms) => page.waitForTimeout(ms);

/** Move the pointer to an element in visible steps, then click it. */
async function click(page, locator, settle = 500) {
    await locator.scrollIntoViewIfNeeded();
    const box = await locator.boundingBox();

    if (!box) {
        throw new Error('the element to click is not on screen');
    }

    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, {
        steps: 25,
    });
    await pause(page, 300);
    await page.mouse.down();
    await pause(page, 90);
    await page.mouse.up();
    await pause(page, settle);
}

/** Type at a speed a reader can follow. */
async function type(page, locator, text) {
    await click(page, locator, 200);
    await locator.fill('');
    await locator.type(text, { delay: 55 });
    await pause(page, 350);
}

/**
 * Park the pointer where it cannot leave a tooltip or a hover state open. The
 * empty stretch of the sidebar is the one spot that stays inert whatever the
 * page is showing and however far it has been scrolled.
 */
const park = (page) => page.mouse.move(120, 680, { steps: 12 });

/**
 * The poster is the last frame, which is the one worth showing as a thumbnail —
 * the goal created, the goal filled. The drawn pointer belongs in a recording
 * that is playing, not in a still, so it goes before that frame is reached.
 */
const hidePointer = (page) =>
    page.evaluate(() => document.getElementById('docs-video-pointer')?.remove());

async function openPlanning(page) {
    await page.goto(`${BASE_URL}/budgets`, { waitUntil: 'domcontentloaded' });
    await page.getByText('Savings Goals', { exact: true }).first().waitFor();
    await park(page);
    await pause(page, 1600);
    await click(page, page.getByText('Savings Goals', { exact: true }).first());
    await pause(page, 1400);
}

const VIDEOS = {
    /** Creating the goal: a target, what is already put aside, and a date. */
    'savings-goal-create': async (page) => {
        await openPlanning(page);

        await click(page, page.getByRole('button', { name: /^Create/ }).first());
        await pause(page, 700);
        await click(page, page.getByRole('menuitem', { name: 'Savings goal' }));
        await pause(page, 900);

        const dialog = page.getByRole('dialog');
        await type(page, dialog.getByRole('textbox', { name: 'Goal name' }), GOAL);
        await type(page, dialog.getByRole('textbox', { name: 'Target amount' }), '17000');
        await type(page, dialog.getByRole('textbox', { name: 'Already saved (optional)' }), '12000');

        const date = dialog.getByRole('textbox', { name: 'Target date (optional)' });
        await click(page, date, 200);
        await date.fill(targetDate());
        await pause(page, 1400);

        await click(page, dialog.getByRole('button', { name: 'Create savings goal' }));
        await page.waitForURL('**/savings-goals/**');
        await page.waitForLoadState('networkidle');
        await park(page);
        // Ends on the goal card and its label. The progress chart below has
        // nothing to draw yet, which is what the second video is for.
        await pause(page, 3000);
        await hidePointer(page);
        await pause(page, 1200);
    },

    /** Filling it without lifting a finger: one rule, keyed on the account. */
    'savings-goal-automate': async (page) => {
        await page.goto(`${BASE_URL}/settings/automation-rules`, {
            waitUntil: 'domcontentloaded',
        });
        await page.getByRole('button', { name: 'Create Rule' }).waitFor();
        await park(page);
        await pause(page, 1800);

        await click(page, page.getByRole('button', { name: 'Create Rule' }));
        await pause(page, 900);

        const dialog = page.getByRole('dialog');
        await type(page, dialog.getByRole('textbox', { name: 'Title' }), RULE);

        await click(page, dialog.getByRole('combobox').filter({ hasText: 'Description' }));
        await pause(page, 600);
        await click(page, page.getByRole('option', { name: 'Account Name' }));
        await pause(page, 700);

        await click(page, dialog.getByRole('combobox').filter({ hasText: 'contains' }));
        await pause(page, 600);
        await click(page, page.getByRole('option', { name: 'equals' }));
        await pause(page, 700);

        await type(page, dialog.getByRole('textbox', { name: 'Value' }), SAVINGS_ACCOUNT);
        await pause(page, 900);

        await click(page, dialog.getByTestId('label-combobox-trigger'));
        await pause(page, 900);
        await click(page, page.getByRole('option', { name: GOAL }));
        await pause(page, 1000);
        await page.keyboard.press('Escape');
        await pause(page, 800);

        await click(page, dialog.getByRole('button', { name: 'Create', exact: true }));

        const review = page.getByRole('button', { name: 'Review matches' });
        await review.waitFor();
        await pause(page, 2000);
        await click(page, review);
        await pause(page, 2600);
        await page.mouse.wheel(0, 320);
        await pause(page, 2000);

        const apply = page.getByRole('button', { name: /^Apply to \d+ transaction/ });
        await apply.waitFor();
        await click(page, apply);
        await pause(page, 3000);

        await openPlanning(page);
        await click(page, page.getByRole('link', { name: new RegExp(GOAL) }).first());
        await page.waitForLoadState('networkidle');
        await park(page);
        await pause(page, 2800);
        // Far enough that the whole list of what the rule just tagged is in
        // frame, which is the still this recording is worth showing as.
        await page.mouse.wheel(0, 880);
        await park(page);
        await pause(page, 2300);
        await hidePointer(page);
        await pause(page, 1200);
    },
};

/** A year out, so the goal has a pace to be measured against. */
function targetDate() {
    const date = new Date();
    date.setFullYear(date.getFullYear() + 1);

    return date.toISOString().slice(0, 10);
}

/**
 * Playwright writes VP8 webm, which Safari will not play. Re-encode to H.264 at
 * a quality that keeps the type readable and the file inside the repo's means.
 */
function toMp4(webm, destination) {
    execFileSync(
        'ffmpeg',
        // prettier-ignore
        [
            '-hide_banner', '-loglevel', 'error', '-y',
            '-i', webm,
            '-c:v', 'libx264', '-preset', 'slow', '-crf', '30',
            '-pix_fmt', 'yuv420p', '-an', '-movflags', '+faststart',
            destination,
        ],
        { stdio: 'inherit' },
    );
}

/**
 * The still the page shows before the video is played (see
 * DocumentationMarkdown::poster): the last frame, which is the result the
 * recording works towards rather than the empty screen it starts from.
 */
function writePoster(mp4, destination) {
    execFileSync(
        'ffmpeg',
        // prettier-ignore
        [
            '-hide_banner', '-loglevel', 'error', '-y',
            '-sseof', '-0.6', '-i', mp4,
            '-frames:v', '1', '-q:v', '4',
            destination,
        ],
        { stdio: 'inherit' },
    );
}

async function main() {
    const wanted = process.argv.slice(2);
    const names = wanted.length > 0 ? wanted : Object.keys(VIDEOS);

    for (const name of names) {
        if (!VIDEOS[name]) {
            throw new Error(
                `Unknown video "${name}". Known: ${Object.keys(VIDEOS).join(', ')}`,
            );
        }
    }

    if (process.env.SKIP_SEED !== '1') {
        seedAccount();
        seedSavingsAccount();
    }

    mkdirSync(OUT_DIR, { recursive: true });

    const browser = await launchBrowser();
    const failures = [];

    for (const name of names) {
        const dir = mkdtempSync(join(tmpdir(), 'docs-videos-'));

        // Each recording starts from the state the one before it ends in, so
        // that the two play as one story however they are run.
        if (name === 'savings-goal-create') {
            artisan([
                'tinker',
                '--execute',
                `$user = App\\Models\\User::where('email', '${EMAIL}')->firstOrFail();
                 App\\Models\\SavingsGoal::query()->where('user_id', $user->id)->forceDelete();
                 $user->labels()->where('name', '${GOAL}')->forceDelete();`,
            ]);
        } else {
            seedGoal();
            resetRule();
        }

        const context = await browser.newContext({
            viewport: VIEWPORT,
            colorScheme: 'dark',
            locale: 'en-US',
            storageState: themeCookie('dark'),
            recordVideo: { dir, size: VIEWPORT },
        });
        await context.addInitScript(drawPointer);

        const page = await context.newPage();
        page.setDefaultTimeout(30000);

        try {
            await login(page, BASE_URL);
            await VIDEOS[name](page);

            const video = page.video();
            await context.close();

            const mp4 = `${OUT_DIR}/${name}.mp4`;
            toMp4(await video.path(), mp4);
            writePoster(mp4, `${OUT_DIR}/${name}-poster.jpg`);
            log(mp4);
        } catch (error) {
            failures.push(`${name}: ${error.message}`);
            log(`FAILED ${name}: ${error.message}`);
            await context.close();
        } finally {
            rmSync(dir, { recursive: true, force: true });
        }
    }

    await browser.close();

    if (failures.length > 0) {
        process.stdout.write(`\n${failures.length} video(s) failed:\n`);
        failures.forEach((failure) => process.stdout.write(`  - ${failure}\n`));
        process.exitCode = 1;
    }
}

await main();
