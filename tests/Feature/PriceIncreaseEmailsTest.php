<?php

use App\Jobs\SendUpdateEmailJob;
use App\Mail\UpdateEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const LAST_CALL_VIEW = 'price-increase-oct-2026';
const LAST_CALL_SUBJECT = '€3.99 becomes €8.99 on 1 October';

const CANCELLING_VIEW = 'price-increase-cancelling-oct-2026';
const CANCELLING_SUBJECT = 'If your subscription ends, you lose the €3.99 price';

const SUBSCRIBERS_VIEW = 'price-increase-subscribers-oct-2026';
const SUBSCRIBERS_SUBJECT = 'Your price is not going up';

/**
 * Queue one of the price increase emails to a user with the given locale and
 * hand back the mailable, so each test asserts on exactly what that user gets.
 */
function queuePriceIncreaseEmail(string $view, string $subject, string $locale): UpdateEmail
{
    Mail::fake();

    $user = User::factory()->create(['name' => 'Ada', 'locale' => $locale]);

    (new SendUpdateEmailJob($user, $view, $view, $subject))->handle();

    // The mailable is ShouldQueue, so the fake records it as queued, not sent.
    $mail = Mail::queued(UpdateEmail::class)->first();

    expect($mail)->toBeInstanceOf(UpdateEmail::class);

    return $mail;
}

it('renders the last call email in English', function () {
    $mail = queuePriceIncreaseEmail(LAST_CALL_VIEW, LAST_CALL_SUBJECT, 'en');

    $mail->assertHasSubject(LAST_CALL_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('I am raising the price on 1 October');

    // The price contrast is the argument, so it is a table rather than prose.
    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('From 1 October');
    $mail->assertSeeInHtml('€8.99');
    $mail->assertSeeInHtml('€53.94');
    $mail->assertSeeInHtml('That is 125% more. More than double.');
    $mail->assertSeeInHtml('that is €30.06');

    $mail->assertSeeInHtml('before 30 September at 23:59 CEST');
    $mail->assertSeeInHtml('Subscribe before 1 October');
    $mail->assertSeeInHtml(route('subscribe'), escape: false);

    // The increase is justified, not just announced: three reasons, who "we"
    // are, and a thank you that names paying and free users both.
    $mail->assertSeeInHtml('There are three reasons.');
    $mail->assertSeeInHtml('Bank connections are what make this app worth opening');
    $mail->assertSeeInHtml('AI does more of your work every month.');
    $mail->assertSeeInHtml('this has to pay for itself to last');
    $mail->assertSeeInHtml('We are two people.');
    $mail->assertSeeInHtml('Álvaro and me');
    $mail->assertSeeInHtml('whether you pay for a plan or use the free one');
});

it('renders the last call email in Spanish', function () {
    $mail = queuePriceIncreaseEmail(LAST_CALL_VIEW, LAST_CALL_SUBJECT, 'es');

    $mail->assertHasSubject('3,99 € pasan a 8,99 € el 1 de octubre');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('El 1 de octubre subo el precio');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('Desde el 1 de octubre');
    $mail->assertSeeInHtml('8,99 €');
    $mail->assertSeeInHtml('53,94 €');
    $mail->assertSeeInHtml('Es un 125 % más. Más del doble.');
    $mail->assertSeeInHtml('son 30,06 €');

    $mail->assertSeeInHtml('antes del 30 de septiembre a las 23:59 (hora peninsular española)');
    $mail->assertSeeInHtml('Suscribirme antes del 1 de octubre');

    $mail->assertSeeInHtml('Hay tres motivos.');
    $mail->assertSeeInHtml('Las conexiones bancarias son lo que hace');
    $mail->assertSeeInHtml('La IA hace cada mes más trabajo por ti.');
    $mail->assertSeeInHtml('tiene que pagarse solo para durar');
    $mail->assertSeeInHtml('Somos dos personas.');
    $mail->assertSeeInHtml('Álvaro y yo');
    $mail->assertSeeInHtml('tanto si pagas un plan como si usas la versión gratis');

    // The mail layout's own footer, English in every Spanish email until now.
    $mail->assertSeeInHtml('Todos los derechos reservados.');

    $mail->assertDontSeeInHtml('I am raising the price on 1 October');
});

it('renders the cancelling email in English', function () {
    $mail = queuePriceIncreaseEmail(CANCELLING_VIEW, CANCELLING_SUBJECT, 'en');

    $mail->assertHasSubject(CANCELLING_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('Before your subscription ends');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('From 1 October');
    $mail->assertSeeInHtml('That is 125% more. More than double.');
    $mail->assertSeeInHtml('The day it ends, that price ends with it.');
    $mail->assertSeeInHtml('€8.99 a month, or €53.94 a year');
    $mail->assertSeeInHtml('after 30 September at 23:59 CEST');

    // Shorter than email A, but the reasoning and the thank you still ship.
    $mail->assertSeeInHtml('Why it is going up:');
    $mail->assertSeeInHtml('We are two people, Álvaro and me');
    $mail->assertSeeInHtml('Thank you for having paid for this.');
    $mail->assertSeeInHtml('the people on the free plan');

    // Reactivating happens in the Stripe portal, reached from the billing page.
    $mail->assertSeeInHtml('Reactivate my subscription');
    $mail->assertSeeInHtml(route('settings.billing'), escape: false);
});

it('renders the cancelling email in Spanish', function () {
    $mail = queuePriceIncreaseEmail(CANCELLING_VIEW, CANCELLING_SUBJECT, 'es');

    $mail->assertHasSubject('Si tu suscripción termina, pierdes el precio de 3,99 €');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('Antes de que acabe tu suscripción');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('Desde el 1 de octubre');
    $mail->assertSeeInHtml('Es un 125 % más. Más del doble.');
    $mail->assertSeeInHtml('El día que termine, ese precio termina con ella.');
    $mail->assertSeeInHtml('8,99 € al mes o 53,94 € al año');

    $mail->assertSeeInHtml('Por qué sube:');
    $mail->assertSeeInHtml('Somos dos personas, Álvaro y yo');
    $mail->assertSeeInHtml('Gracias por haber pagado por esto.');
    $mail->assertSeeInHtml('la gente que la usa gratis');

    $mail->assertSeeInHtml('Reactivar mi suscripción');

    $mail->assertDontSeeInHtml('Before your subscription ends');
});

it('renders the subscribers email in English', function () {
    $mail = queuePriceIncreaseEmail(SUBSCRIBERS_VIEW, SUBSCRIBERS_SUBJECT, 'en');

    $mail->assertHasSubject(SUBSCRIBERS_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('Your price is not going up');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('From 1 October');
    $mail->assertSeeInHtml('goes up, for new subscriptions');
    $mail->assertSeeInHtml('Yours is not one of them.');
    $mail->assertSeeInHtml('There is one way to lose it: cancel.');
    $mail->assertSeeInHtml('There is nothing for you to do.');
    $mail->assertSeeInHtml('Thank you for paying for this.');

    // Nothing for them to act on, so no CTA button and no reason to send them
    // to a page that would only invite them to change their plan.
    $mail->assertDontSeeInHtml(route('settings.billing'), escape: false);
    $mail->assertDontSeeInHtml(route('subscribe'), escape: false);
});

it('renders the subscribers email in Spanish', function () {
    $mail = queuePriceIncreaseEmail(SUBSCRIBERS_VIEW, SUBSCRIBERS_SUBJECT, 'es');

    $mail->assertHasSubject('Tu precio no sube');
    $mail->assertSeeInHtml('Hola Ada,');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('Desde el 1 de octubre');
    $mail->assertSeeInHtml('para las suscripciones nuevas');
    $mail->assertSeeInHtml('La tuya no es una de ellas.');
    $mail->assertSeeInHtml('Solo hay una forma de perderlo: cancelar.');
    $mail->assertSeeInHtml('No tienes que hacer nada.');
    $mail->assertSeeInHtml('Gracias por pagar por esto.');

    $mail->assertDontSeeInHtml('Your price is not going up');
});

/**
 * LocalizationTest only scans resources/js, so a Blade line or a subject left
 * out of lang/es.json ships as English inside a Spanish email. This is the only
 * thing that catches it.
 */
it('has a Spanish translation for every line of the template and its subject', function (string $view, string $subject) {
    $translations = json_decode(file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);

    preg_match_all("/__\('(.+?)'/", file_get_contents(resource_path("views/mail/updates/{$view}.blade.php")), $matches);

    expect($matches[1])->not->toBeEmpty();
    expect(array_values(array_diff([...$matches[1], $subject], array_keys($translations))))->toBe([]);
})->with([
    [LAST_CALL_VIEW, LAST_CALL_SUBJECT],
    [CANCELLING_VIEW, CANCELLING_SUBJECT],
    [SUBSCRIBERS_VIEW, SUBSCRIBERS_SUBJECT],
]);
