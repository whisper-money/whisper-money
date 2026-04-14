<?php

use App\Models\UserLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

/**
 * Build a serialized ModelIdentifier command string for a UserLead.
 *
 * Uses Illuminate\Contracts\Database\ModelIdentifier (a real Laravel class) so that
 * queue:retry can safely unserialize it when refreshing retryUntil without hitting
 * any undefined-class errors. The string matches our extractLeadId() regex patterns.
 */
function serializedMailLeadCommand(string $leadId): string
{
    return sprintf(
        'O:45:"Illuminate\Contracts\Database\ModelIdentifier":5:{s:5:"class";s:19:"App\Models\UserLead";s:2:"id";s:36:"%s";s:9:"relations";a:0:{}s:10:"connection";s:5:"mysql";s:15:"collectionClass";N;}',
        $leadId
    );
}

function serializedNotificationLeadCommand(string $leadId): string
{
    return sprintf(
        'O:45:"Illuminate\Contracts\Database\ModelIdentifier":5:{s:5:"class";s:19:"App\Models\UserLead";s:2:"id";a:1:{i:0;s:36:"%s";}s:9:"relations";a:0:{}s:10:"connection";s:5:"mysql";s:15:"collectionClass";N;}',
        $leadId
    );
}

/**
 * Insert a fake failed mailable job into failed_jobs, referencing the given lead UUID.
 */
function insertFailedMailJob(string $displayName, string $leadId): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'emails',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $displayName,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'Illuminate\Mail\SendQueuedMailable',
                'command' => serializedMailLeadCommand($leadId),
            ],
        ]),
        'exception' => 'Too Many Requests',
        'failed_at' => now(),
    ]);

    return $uuid;
}

/**
 * Insert a fake failed VerifyUserLeadEmailNotification job, referencing the given lead UUID.
 */
function insertFailedNotificationJob(string $leadId): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'emails',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => 'App\Notifications\VerifyUserLeadEmailNotification',
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'Illuminate\Notifications\SendQueuedNotifications',
                'command' => serializedNotificationLeadCommand($leadId),
            ],
        ]),
        'exception' => 'Too Many Requests',
        'failed_at' => now(),
    ]);

    return $uuid;
}

test('leads:retry-failed-jobs does nothing when no failed jobs exist', function () {
    artisan('leads:retry-failed-jobs')
        ->expectsOutputToContain('No failed email jobs found.')
        ->assertSuccessful();
});

test('leads:retry-failed-jobs forgets jobs for deleted leads', function () {
    $deletedLeadId = (string) Str::uuid();
    insertFailedMailJob('App\Mail\WaitlistWelcome', $deletedLeadId);

    artisan('leads:retry-failed-jobs')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('leads:retry-failed-jobs retries jobs for verified leads', function () {
    $lead = UserLead::factory()->create();
    insertFailedMailJob('App\Mail\WaitlistOvertaken', $lead->id);

    artisan('leads:retry-failed-jobs')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('leads:retry-failed-jobs forgets waitlist jobs for unverified leads', function () {
    $lead = UserLead::factory()->unverified()->create();
    insertFailedMailJob('App\Mail\WaitlistOvertaken', $lead->id);

    artisan('leads:retry-failed-jobs')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('leads:retry-failed-jobs retries VerifyUserLeadEmailNotification for unverified leads', function () {
    $lead = UserLead::factory()->unverified()->create();
    insertFailedNotificationJob($lead->id);

    artisan('leads:retry-failed-jobs')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('leads:retry-failed-jobs handles a mix of job types correctly', function () {
    $verifiedLead = UserLead::factory()->create();
    $unverifiedLead = UserLead::factory()->unverified()->create();
    $deletedLeadId = (string) Str::uuid();

    insertFailedMailJob('App\Mail\WaitlistOvertaken', $verifiedLead->id);        // retry
    insertFailedMailJob('App\Mail\WaitlistReferralNotification', $verifiedLead->id); // retry
    insertFailedMailJob('App\Mail\WaitlistWelcome', $deletedLeadId);             // forget
    insertFailedMailJob('App\Mail\WaitlistOvertaken', $unverifiedLead->id);      // forget
    insertFailedNotificationJob($unverifiedLead->id);                            // retry

    artisan('leads:retry-failed-jobs')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('leads:retry-failed-jobs --dry-run does not modify failed_jobs', function () {
    $lead = UserLead::factory()->create();
    $deletedLeadId = (string) Str::uuid();

    insertFailedMailJob('App\Mail\WaitlistOvertaken', $lead->id);
    insertFailedMailJob('App\Mail\WaitlistWelcome', $deletedLeadId);

    artisan('leads:retry-failed-jobs', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    // Nothing should be touched in dry-run mode
    expect(DB::table('failed_jobs')->count())->toBe(2);
});
