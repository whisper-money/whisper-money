<?php

use App\Jobs\SendUpdateEmailJob;
use App\Mail\UpdateEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const MCP_LAUNCH_VIEW = 'mcp-launch-aug-2026';
const MCP_LAUNCH_SUBJECT = 'Ask ChatGPT how much you spent this month';

/**
 * Queue the launch email to a user with the given locale and hand back the
 * mailable the fake captured, so each test asserts on what that user receives.
 */
function queueMcpLaunchEmail(string $locale): UpdateEmail
{
    Mail::fake();

    $user = User::factory()->create(['name' => 'Ada', 'locale' => $locale]);

    (new SendUpdateEmailJob($user, MCP_LAUNCH_VIEW, MCP_LAUNCH_VIEW, MCP_LAUNCH_SUBJECT))->handle();

    $queued = null;

    // The mailable is ShouldQueue, so the fake records it as queued, not sent.
    Mail::assertQueued(UpdateEmail::class, function (UpdateEmail $mail) use (&$queued): bool {
        $queued = $mail;

        return true;
    });

    return $queued;
}

it('renders the launch email in English', function () {
    $mail = queueMcpLaunchEmail('en');

    $mail->assertHasSubject(MCP_LAUNCH_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('I just wanted to know one number');
    $mail->assertSeeInHtml('The data was never the problem.');
    $mail->assertSeeInHtml('How much did I spend on restaurants this month?');
    $mail->assertSeeInHtml('It comes with Pro, by the way.');
    $mail->assertSeeInHtml('https://youtu.be/QSSd6z5UZ_M', escape: false);
    $mail->assertSeeInHtml('The video is in Spanish.');
});

it('renders the launch email in Spanish, video language warning aside', function () {
    $mail = queueMcpLaunchEmail('es');

    $mail->assertHasSubject('Pregúntale a ChatGPT cuánto has gastado este mes');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('Solo quería saber un número');
    $mail->assertSeeInHtml('El problema nunca fueron los datos.');
    $mail->assertSeeInHtml('¿Cuánto me he gastado este mes en restaurantes?');
    $mail->assertSeeInHtml('Va incluido en Pro, por cierto.');
    $mail->assertSeeInHtml('https://youtu.be/QSSd6z5UZ_M', escape: false);

    // The video is already in Spanish, so the heads-up only ships in other locales.
    $mail->assertDontSeeInHtml('The video is in Spanish.');
});

it('leaves an untranslated subject untouched', function () {
    Mail::fake();

    $user = User::factory()->create(['locale' => 'es']);

    (new SendUpdateEmailJob($user, MCP_LAUNCH_VIEW, MCP_LAUNCH_VIEW, 'Update from Whisper Money'))->handle();

    Mail::assertQueued(UpdateEmail::class, function (UpdateEmail $mail): bool {
        $mail->assertHasSubject('Update from Whisper Money');

        return true;
    });
});
