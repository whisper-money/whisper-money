<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Mail\BankHealthAlertEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['mail.report_recipients' => ['owner@example.com']]);
});

/**
 * One connection to a named bank, owned by its own user.
 *
 * @param  array<string, mixed>  $attributes
 */
function healthConnection(string $bank, array $attributes = []): BankingConnection
{
    return BankingConnection::factory()->for(User::factory())->create([
        'aspsp_name' => $bank,
        'aspsp_country' => 'ES',
        ...$attributes,
    ]);
}

/**
 * An attempt the bank's own authorization rejected: pending, no session, and
 * soft-deleted by the error callback.
 */
function rejectedAuthorization(string $bank): BankingConnection
{
    $connection = BankingConnection::factory()->for(User::factory())->pending()->create([
        'aspsp_name' => $bank,
        'aspsp_country' => 'ES',
    ]);

    $connection->delete();

    return $connection;
}

/**
 * A live connection that has not synced for long enough to count as failing.
 */
function stalledConnection(string $bank): BankingConnection
{
    return healthConnection($bank, ['last_synced_at' => now()->subDays(5)]);
}

/**
 * The banks the last `--email` run reported, as the mailable received them.
 *
 * @return array<int, array<string, mixed>>
 */
function alertedBanks(): array
{
    $banks = [];

    Mail::assertSent(BankHealthAlertEmail::class, function (BankHealthAlertEmail $mail) use (&$banks) {
        $banks = $mail->banks;

        return true;
    });

    return $banks;
}

test('reports a bank whose connections are all syncing as healthy', function () {
    healthConnection('Working Bank');
    healthConnection('Working Bank');

    artisan('banking:health')
        ->expectsOutputToContain('1 bank(s) on record, 0 broken for everyone who uses them.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('alerts about a bank that has never let anybody in', function () {
    rejectedAuthorization('Never Works Bank');
    rejectedAuthorization('Never Works Bank');

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('Alerted about 1 bank(s): Never Works Bank (ES).')
        ->assertSuccessful();

    expect(alertedBanks())->toHaveCount(1)
        ->and(alertedBanks()[0]['notify_command'])
        ->toBe('php artisan banking:notify-connect-failure "Never Works Bank" --country=ES')
        ->and(alertedBanks()[0]['reason'])->toContain('Never connected once: 2 authorization(s) rejected');
});

test('alerts about a bank whose every live connection has stopped syncing', function () {
    stalledConnection('Dead Bank');
    stalledConnection('Dead Bank');

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('Alerted about 1 bank(s): Dead Bank (ES).')
        ->assertSuccessful();

    expect(alertedBanks()[0]['notify_command'])
        ->toBe('php artisan banking:notify-outage "Dead Bank" --country=ES')
        ->and(alertedBanks()[0]['reason'])->toContain('All 2 of its 2 connection(s) that should be syncing have stopped');
});

test('counts a connection stuck on an error as failing, however fresh its clock', function () {
    healthConnection('Erroring Bank', ['status' => BankingConnectionStatus::Error]);
    healthConnection('Erroring Bank', ['status' => BankingConnectionStatus::Error]);

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    expect(alertedBanks()[0]['display_name'])->toBe('Erroring Bank (ES)');
});

test('leaves a bank alone while only some of its connections are failing', function () {
    stalledConnection('Flaky Bank');
    stalledConnection('Flaky Bank');
    healthConnection('Flaky Bank');

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('will not call a bank broken on one person\'s evidence alone', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        BankingConnection::factory()->for($user)->create([
            'aspsp_name' => 'One User Bank',
            'aspsp_country' => 'ES',
            'last_synced_at' => now()->subDays(5),
        ]);
    }

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('does not read a rate limited connection as a bank-side outage', function () {
    // The 429 lands after the transactions import and the discarded run never
    // writes last_synced_at, so these look permanently stale while working fine.
    healthConnection('Trade Republic', [
        'last_synced_at' => null,
        'rate_limited_until' => now()->addHour(),
    ]);
    healthConnection('Trade Republic', [
        'last_synced_at' => null,
        'rate_limited_until' => now()->addHour(),
    ]);

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('still alerts when a throttled connection sits beside connections that are genuinely down', function () {
    healthConnection('Half Throttled Bank', ['last_synced_at' => null]);
    healthConnection('Half Throttled Bank', ['last_synced_at' => null]);
    // Throttled, and the only connection to this bank with a recent sync.
    healthConnection('Half Throttled Bank', [
        'last_synced_at' => now()->subHour(),
        'rate_limited_until' => now()->addHour(),
    ]);

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    expect(alertedBanks()[0]['reason'])
        ->toContain('All 2 of its 2 connection(s) that should be syncing have stopped')
        ->toContain('A further 1 are rate limited and left out of that count')
        // The throttled connection is the one that synced an hour ago. Quoting it
        // here would make the alert read better than the failing set really is.
        ->toContain('not one of them has ever synced')
        ->not->toContain('1 hour ago');
});

test('ignores connections the user has to reconnect themselves', function () {
    healthConnection('Expired Bank', ['status' => BankingConnectionStatus::Expired, 'valid_until' => now()->subDay()]);
    healthConnection('Expired Bank', ['status' => BankingConnectionStatus::Revoked]);
    healthConnection('Expired Bank', [
        'status' => BankingConnectionStatus::Error,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES,
    ]);

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('stays quiet about a bank it has already reported inside the window', function () {
    stalledConnection('Dead Bank');
    stalledConnection('Dead Bank');

    artisan('banking:health', ['--email' => true])->assertSuccessful();
    Mail::assertSent(BankHealthAlertEmail::class, 1);

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('All 1 broken bank(s) were already alerted about in the last 7 day(s).')
        ->assertSuccessful();

    Mail::assertSent(BankHealthAlertEmail::class, 1);
});

test('reports the same bank again once the window has elapsed', function () {
    stalledConnection('Dead Bank');
    stalledConnection('Dead Bank');

    artisan('banking:health', ['--email' => true, '--repeat-after' => 2])->assertSuccessful();

    $this->travel(3)->days();

    artisan('banking:health', ['--email' => true, '--repeat-after' => 2])->assertSuccessful();

    Mail::assertSent(BankHealthAlertEmail::class, 2);
});

test('the staleness window decides how long a quiet connection gets', function () {
    healthConnection('Slow Bank', ['last_synced_at' => now()->subHours(6)]);
    healthConnection('Slow Bank', ['last_synced_at' => now()->subHours(6)]);

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone.')
        ->assertSuccessful();

    artisan('banking:health', ['--email' => true, '--stale-hours' => 4])->assertSuccessful();

    expect(alertedBanks()[0]['display_name'])->toBe('Slow Bank (ES)');
});

test('renders the report without sending anything when --email is left off', function () {
    stalledConnection('Dead Bank');
    stalledConnection('Dead Bank');

    artisan('banking:health')
        ->expectsOutputToContain('1 bank(s) on record, 1 broken for everyone who uses them.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('refuses to alert with no recipients configured', function () {
    config(['mail.report_recipients' => []]);
    stalledConnection('Dead Bank');
    stalledConnection('Dead Bank');

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('REPORT_RECIPIENTS is not configured.')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('says so when there is no Enable Banking connection at all', function () {
    BankingConnection::factory()->for(User::factory())->indexaCapital()->create();

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No Enable Banking connection on record.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('escapes a bank name a shell would otherwise expand', function () {
    rejectedAuthorization('Bank "$HOME"');
    rejectedAuthorization('Bank "$HOME"');

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    expect(alertedBanks()[0]['notify_command'])
        ->toBe('php artisan banking:notify-connect-failure "Bank \\"\\$HOME\\"" --country=ES');
});

test('a bank that connects for others is not broken for the users it rejected', function () {
    healthConnection('Cancelled At Bank');
    rejectedAuthorization('Cancelled At Bank');
    rejectedAuthorization('Cancelled At Bank');

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('names the provider when too many banks fail at once to blame the banks', function () {
    foreach (['Bank A', 'Bank B', 'Bank C'] as $bank) {
        stalledConnection($bank);
        stalledConnection($bank);
    }

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    Mail::assertSent(BankHealthAlertEmail::class, fn (BankHealthAlertEmail $mail) => $mail->looksLikeProviderOutage);
});

test('blames the bank when it is the only one failing', function () {
    stalledConnection('Dead Bank');
    stalledConnection('Dead Bank');
    healthConnection('Healthy Bank');
    healthConnection('Another Healthy Bank');

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    Mail::assertSent(BankHealthAlertEmail::class, fn (BankHealthAlertEmail $mail) => ! $mail->looksLikeProviderOutage);
});
