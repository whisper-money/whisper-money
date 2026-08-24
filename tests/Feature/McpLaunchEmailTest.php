<?php

use App\Jobs\SendUpdateEmailJob;
use App\Mail\UpdateEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const MCP_LAUNCH_VIEW = 'mcp-launch-aug-2026';
const MCP_LAUNCH_SUBJECT = 'Ask ChatGPT how much you spent this month';

/**
 * Queue the launch email to a user with the given locale and hand back the
 * mailable, so each test asserts on exactly what that user receives.
 */
function queueMcpLaunchEmail(string $locale): UpdateEmail
{
    Mail::fake();

    $user = User::factory()->create(['name' => 'Ada', 'locale' => $locale]);

    (new SendUpdateEmailJob($user, MCP_LAUNCH_VIEW, MCP_LAUNCH_VIEW, MCP_LAUNCH_SUBJECT))->handle();

    // The mailable is ShouldQueue, so the fake records it as queued, not sent.
    $mail = Mail::queued(UpdateEmail::class)->first();

    expect($mail)->toBeInstanceOf(UpdateEmail::class);

    return $mail;
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

it('renders the launch email in Spanish without the video language note', function () {
    $mail = queueMcpLaunchEmail('es');

    $mail->assertHasSubject('Pregúntale a ChatGPT cuánto has gastado este mes');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('Solo quería saber un número');
    $mail->assertSeeInHtml('El problema nunca fueron los datos.');
    $mail->assertSeeInHtml('¿Cuánto me he gastado este mes en restaurantes?');
    $mail->assertSeeInHtml('Va incluido en Pro, por cierto.');
    $mail->assertSeeInHtml('https://youtu.be/QSSd6z5UZ_M', escape: false);

    // The video is already in Spanish, so the heads-up only ships elsewhere.
    $mail->assertDontSeeInHtml('The video is in Spanish.');
});

it('only promises what the connector actually does', function () {
    $mail = queueMcpLaunchEmail('en');

    // Write access over an OAuth connection is what makes this claim true;
    // see WriteTool and tests/Feature/Mcp/McpOAuthTest.php.
    $mail->assertSeeInHtml('note down 40 euros on groceries');

    // Whisper Money has no screen to revoke an OAuth connection, so the email
    // must not promise one.
    $mail->assertDontSeeInHtml('you can undo it whenever you want');
    $mail->assertSeeInHtml('remove Whisper Money from the connected apps inside ChatGPT');
});
