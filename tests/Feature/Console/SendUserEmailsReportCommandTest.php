<?php

use App\Mail\UserEmailsReportEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config(['mail.report_recipients' => ['owner-one@example.com', 'owner-two@example.com']]);
});

test('command emails a csv with every non-deleted user email', function () {
    Mail::fake();

    User::factory()->create(['email' => 'first@example.com', 'created_at' => now()->subDay()]);
    User::factory()->create(['email' => 'second@example.com', 'created_at' => now()]);
    User::factory()->create(['email' => 'deleted@example.com'])->delete();

    artisan('email:user-emails-report')
        ->expectsOutputToContain('Sent 2 user email(s)')
        ->assertSuccessful();

    $fileName = 'user-emails-'.now()->format('Y-m-d').'.csv';

    Mail::assertSent(UserEmailsReportEmail::class, function (UserEmailsReportEmail $mail) use ($fileName) {
        $mail->assertHasAttachedData("email\nfirst@example.com\nsecond@example.com\n", $fileName, ['mime' => 'text/csv']);

        return $mail->userCount === 2
            && $mail->fileName === $fileName
            && $mail->hasTo('owner-one@example.com')
            && $mail->hasTo('owner-two@example.com');
    });
});

test('command still sends the report when there are no users', function () {
    Mail::fake();

    artisan('email:user-emails-report')
        ->expectsOutputToContain('Sent 0 user email(s)')
        ->assertSuccessful();

    Mail::assertSent(UserEmailsReportEmail::class, function (UserEmailsReportEmail $mail) {
        return $mail->userCount === 0 && $mail->csv === "email\n";
    });
});

test('command fails loudly when no recipients are configured', function () {
    Mail::fake();

    config(['mail.report_recipients' => []]);

    User::factory()->create();

    artisan('email:user-emails-report')
        ->expectsOutputToContain('REPORT_RECIPIENTS is not configured.')
        ->assertFailed();

    Mail::assertNothingSent();
});
