<?php

use App\Mail\UserEmailsReportEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

test('command emails a csv with every non-deleted user email', function () {
    Mail::fake();

    $first = User::factory()->create(['email' => 'first@example.com', 'created_at' => now()->subDay()]);
    User::factory()->create(['email' => 'second@example.com', 'created_at' => now()]);
    User::factory()->create(['email' => 'deleted@example.com'])->delete();

    artisan('stats:user-emails')
        ->expectsOutputToContain('Sent 2 user email(s)')
        ->assertSuccessful();

    Mail::assertSent(UserEmailsReportEmail::class, function (UserEmailsReportEmail $mail) use ($first) {
        expect($mail->userCount)->toBe(2)
            ->and($mail->fileName)->toBe('user-emails-'.now()->format('Y-m-d').'.csv')
            ->and($mail->csv)->toBe("email\n{$first->email}\nsecond@example.com\n")
            ->and($mail->attachments())->toHaveCount(1);

        return $mail->hasTo('victoor89@gmail.com') && $mail->hasTo('invernovah@gmail.com');
    });
});

test('command still sends the report when there are no users', function () {
    Mail::fake();

    artisan('stats:user-emails')
        ->expectsOutputToContain('Sent 0 user email(s)')
        ->assertSuccessful();

    Mail::assertSent(UserEmailsReportEmail::class, function (UserEmailsReportEmail $mail) {
        return $mail->userCount === 0 && $mail->csv === "email\n";
    });
});
