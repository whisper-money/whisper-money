<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Mail\BankHealthAlertEmail;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Models\User;
use Carbon\CarbonInterface;
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
 * A run the sync job recorded against a connection.
 */
function syncRun(BankingConnection $connection, BankingSyncLogStatus $status, CarbonInterface $at): void
{
    BankingSyncLog::create([
        'banking_connection_id' => $connection->id,
        'status' => $status,
        'created_at' => $at,
    ]);
}

/**
 * One failed run per scheduled cycle, counting back from now.
 */
function failedRuns(BankingConnection $connection, int $cycles): void
{
    foreach (range(1, $cycles) as $cycle) {
        syncRun($connection, BankingSyncLogStatus::Failed, now()->subHours($cycle * 6));
    }
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
    // A connection whose every run 429s never writes last_synced_at, so it looks
    // permanently stale while the transactions it did import are fine.
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

test('does not blame a bank for the connections of users who deleted their accounts', function () {
    // Every failing connection in the production report was one of these: nothing
    // syncs them, so their clocks are frozen at whenever their owner left and they
    // read as permanently down. Ten banks were being reported as degraded on them.
    stalledConnection('Abandoned Bank')->user->delete();
    stalledConnection('Abandoned Bank')->user->delete();

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('reports a bank whose every run failed, on one user\'s evidence', function () {
    // Renta 4 Banco's shape: its balances answer, so the connection's clock keeps
    // being written and every snapshot column reads it as healthy, while its
    // transactions call has 400ed on every attempt for months. One user, so the
    // two-user floor would hide it forever.
    failedRuns(healthConnection('Renta 4 Banco'), 4);

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    expect(alertedBanks())->toHaveCount(1)
        ->and(alertedBanks()[0]['display_name'])->toBe('Renta 4 Banco (ES)')
        ->and(alertedBanks()[0]['notify_command'])
        ->toBe('php artisan banking:notify-outage "Renta 4 Banco" --country=ES')
        ->and(alertedBanks()[0]['reason'])
        ->toContain('4 failed run(s) out of 4 in the last 7 day(s)')
        ->toContain('not one has worked in that whole window');
});

test('reports a bank still failing every run while some of its connections look fresh', function () {
    // Openbank during its six-day outage: only 4 of its 13 connections had been
    // quiet long enough for the snapshot to call them failing, so the report saw a
    // bank that was merely degraded and never alerted.
    $stopped = healthConnection('Openbank', ['last_synced_at' => now()->subDays(5)]);
    $stillFresh = healthConnection('Openbank');

    failedRuns($stopped, 8);
    failedRuns($stillFresh, 8);
    syncRun($stillFresh, BankingSyncLogStatus::Success, now()->subDays(4));

    artisan('banking:health', ['--email' => true])->assertSuccessful();

    expect(alertedBanks()[0]['reason'])
        ->toContain('16 failed run(s) out of 17 in the last 7 day(s)')
        ->toContain('the last one that worked was 4 days ago');
});

test('keeps a recovered outage legible in the report', function () {
    // The bank is answering again, so every snapshot column calls it healthy on
    // the morning it recovers. The window is the only place the six days it spent
    // down are still visible.
    $connection = healthConnection('Recovered Bank');
    failedRuns($connection, 8);
    syncRun($connection, BankingSyncLogStatus::Success, now()->subHour());

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('11% of 9')
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('does not read a throttled bank as an outage from its history', function () {
    // Trade Republic FR: 24 failed runs, not one success, and working fine. The
    // 429 fails the run that hits it, and every run after it is skipped until the
    // backoff elapses, so a rate limit reads exactly like a dead connector.
    failedRuns(healthConnection('Trade Republic', ['rate_limited_until' => now()->addHour()]), 8);

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('does not call a single bad cycle a drought', function () {
    failedRuns(healthConnection('Blip Bank'), 3);

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('a bank that synced inside the staleness window is not down', function () {
    $connection = healthConnection('Flaky But Alive Bank');
    failedRuns($connection, 8);
    syncRun($connection, BankingSyncLogStatus::Success, now()->subHours(12));

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('a run nobody attempted is evidence in neither direction', function () {
    $connection = healthConnection('Skipped Bank');

    foreach (range(1, 8) as $cycle) {
        syncRun($connection, BankingSyncLogStatus::Skipped, now()->subHours($cycle * 6));
    }

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('the history window decides how far back the runs are read', function () {
    $connection = healthConnection('Old News Bank');

    foreach (range(1, 8) as $cycle) {
        syncRun($connection, BankingSyncLogStatus::Failed, now()->subDays(10)->subHours($cycle));
    }

    artisan('banking:health', ['--email' => true])
        ->expectsOutputToContain('No bank is broken for everyone. Nothing to alert about.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();

    artisan('banking:health', ['--email' => true, '--history-days' => 14])->assertSuccessful();

    expect(alertedBanks()[0]['display_name'])->toBe('Old News Bank (ES)');
});
