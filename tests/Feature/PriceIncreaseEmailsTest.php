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

const LAST_DAYS_VIEW = 'price-increase-last-days-oct-2026';
const LAST_DAYS_SUBJECT = 'Your last chance at €3.99 ends on Wednesday';

const LAST_DAYS_CANCELLING_VIEW = 'price-increase-last-days-cancelling-oct-2026';
const LAST_DAYS_CANCELLING_SUBJECT = 'Last chance to keep your old price';

const LAST_DAYS_SUBSCRIBERS_VIEW = 'price-increase-last-days-subscribers-oct-2026';
const LAST_DAYS_SUBSCRIBERS_SUBJECT = 'After 1 October, nobody else can get your price';

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

it('renders the last days email in English', function () {
    $mail = queuePriceIncreaseEmail(LAST_DAYS_VIEW, LAST_DAYS_SUBJECT, 'en');

    $mail->assertHasSubject(LAST_DAYS_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('Five days left at the old price');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('From 1 October');
    $mail->assertSeeInHtml('€8.99');
    $mail->assertSeeInHtml('€53.94');

    // The one thing the reader has to act on: this is the last chance, and it
    // ends on Wednesday.
    $mail->assertSeeInHtml('your last chance to subscribe at the price you see today');
    $mail->assertSeeInHtml('you have until Wednesday 30 September');
    $mail->assertSeeInHtml('Your price lives inside your subscription, not on the pricing page.');
    $mail->assertSeeInHtml('After 30 September at 23:59 CEST the old price is gone');

    $mail->assertSeeInHtml('Keep the €3.99 price');
    $mail->assertSeeInHtml(route('subscribe'), escape: false);

    // The free plan is not a consolation prize being withdrawn.
    $mail->assertSeeInHtml('stay on it');

    // A reminder, not a reprint: the three reasons of the first email are one
    // line here, and nothing else from it comes back.
    $mail->assertSeeInHtml('It is going up because bank connections and AI');
    $mail->assertDontSeeInHtml('There are three reasons.');
    $mail->assertDontSeeInHtml('I am raising the price on 1 October');
});

it('renders the last days email in Spanish', function () {
    $mail = queuePriceIncreaseEmail(LAST_DAYS_VIEW, LAST_DAYS_SUBJECT, 'es');

    $mail->assertHasSubject('Tu última oportunidad de pagar 3,99 € acaba el miércoles');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('Quedan cinco días con el precio de siempre');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('Desde el 1 de octubre');
    $mail->assertSeeInHtml('8,99 €');
    $mail->assertSeeInHtml('53,94 €');

    $mail->assertSeeInHtml('tu última oportunidad de suscribirte al precio que ves hoy');
    $mail->assertSeeInHtml('tienes hasta el miércoles 30 de septiembre');
    $mail->assertSeeInHtml('Tu precio vive dentro de tu suscripción, no en la página de precios.');
    $mail->assertSeeInHtml('a las 23:59 (hora peninsular española)');

    $mail->assertSeeInHtml('Quedarme con el precio de 3,99 €');
    $mail->assertSeeInHtml('Sube porque las conexiones bancarias');

    $mail->assertDontSeeInHtml('Five days left at the old price');
});

it('renders the last days cancelling email in English', function () {
    $mail = queuePriceIncreaseEmail(LAST_DAYS_CANCELLING_VIEW, LAST_DAYS_CANCELLING_SUBJECT, 'en');

    $mail->assertHasSubject(LAST_DAYS_CANCELLING_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('Reactivate it and you keep €3.99');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('From 1 October');
    $mail->assertSeeInHtml('€8.99');
    $mail->assertSeeInHtml('€53.94');

    // Letting it end loses the price for good, and this is the last email.
    $mail->assertSeeInHtml('The day it ends, that price is gone for good');
    $mail->assertSeeInHtml('there stops being a cheaper subscription to come back to');
    $mail->assertSeeInHtml('This is the last email I will send you about it.');

    // This audience's deadline is the end of their own period, which can be
    // months away, so the email must not promise them a day count.
    $mail->assertDontSeeInHtml('days left');
    $mail->assertDontSeeInHtml('Wednesday');

    $mail->assertSeeInHtml('Reactivate my subscription');
    $mail->assertSeeInHtml(route('settings.billing'), escape: false);

    // It closes by asking why, not by waving them off.
    $mail->assertSeeInHtml('if something made you cancel, reply to this email');
    $mail->assertDontSeeInHtml('that is completely fine');

    // A reminder, not a reprint of price-increase-cancelling-oct-2026.
    $mail->assertDontSeeInHtml('Before your subscription ends');
    $mail->assertDontSeeInHtml('The day it ends, that price ends with it.');
});

it('renders the last days cancelling email in Spanish', function () {
    $mail = queuePriceIncreaseEmail(LAST_DAYS_CANCELLING_VIEW, LAST_DAYS_CANCELLING_SUBJECT, 'es');

    $mail->assertHasSubject('Última oportunidad para conservar tu precio antiguo');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('Reactívala y te quedas con los 3,99 €');

    $mail->assertSeeInHtml('<table', escape: false);
    $mail->assertSeeInHtml('Desde el 1 de octubre');
    $mail->assertSeeInHtml('8,99 €');
    $mail->assertSeeInHtml('53,94 €');

    $mail->assertSeeInHtml('El día que termine, ese precio desaparece para siempre');
    $mail->assertSeeInHtml('deja de haber una suscripción más barata a la que volver');
    $mail->assertSeeInHtml('Este es el último correo que te mando sobre esto.');
    $mail->assertDontSeeInHtml('miércoles');

    $mail->assertSeeInHtml('Reactivar mi suscripción');
    $mail->assertSeeInHtml('si algo te hizo cancelar, responde a este correo');

    $mail->assertDontSeeInHtml('Reactivate it and you keep €3.99');
});

it('renders the last days subscribers email in English', function () {
    $mail = queuePriceIncreaseEmail(LAST_DAYS_SUBSCRIBERS_VIEW, LAST_DAYS_SUBSCRIBERS_SUBJECT, 'en');

    $mail->assertHasSubject(LAST_DAYS_SUBSCRIBERS_SUBJECT);
    $mail->assertSeeInHtml('Hi Ada,');
    $mail->assertSeeInHtml('Keep your subscription, keep your price');

    // Retention, not reassurance: their price will not be offered again, and
    // cancelling loses it for good.
    $mail->assertSeeInHtml('€8.99 a month, or €53.94 a year');
    $mail->assertSeeInHtml('nobody who subscribes can get the price you pay now');
    $mail->assertSeeInHtml('Your subscription keeps the price you signed up at');
    $mail->assertSeeInHtml('But if you cancel and your subscription ends, that price is gone for good.');
    $mail->assertSeeInHtml('reply to this email first');
    $mail->assertSeeInHtml('Thank you for supporting us.');
    $mail->assertDontSeeInHtml('Nothing changes for you');
    $mail->assertDontSeeInHtml('There is nothing for you to do');

    // They already know what they pay, so there is no price table and no CTA.
    // The table is checked by its header, since the mail layout itself is
    // built of tables.
    $mail->assertDontSeeInHtml('From 1 October');
    $mail->assertDontSeeInHtml(route('settings.billing'), escape: false);
    $mail->assertDontSeeInHtml(route('subscribe'), escape: false);
});

it('renders the last days subscribers email in Spanish', function () {
    $mail = queuePriceIncreaseEmail(LAST_DAYS_SUBSCRIBERS_VIEW, LAST_DAYS_SUBSCRIBERS_SUBJECT, 'es');

    $mail->assertHasSubject('Desde el 1 de octubre, nadie más podrá tener tu precio');
    $mail->assertSeeInHtml('Hola Ada,');
    $mail->assertSeeInHtml('Si mantienes tu suscripción, mantienes tu precio');

    $mail->assertSeeInHtml('8,99 € al mes, o 53,94 € al año');
    $mail->assertSeeInHtml('nadie que se suscriba podrá conseguir el precio que pagas tú');
    $mail->assertSeeInHtml('Tu suscripción mantiene el precio con el que la empezaste');
    $mail->assertSeeInHtml('ese precio desaparece para siempre');
    $mail->assertSeeInHtml('antes responde a este correo');
    $mail->assertSeeInHtml('Gracias por apoyarnos.');

    $mail->assertDontSeeInHtml('Keep your subscription, keep your price');
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
    [LAST_DAYS_VIEW, LAST_DAYS_SUBJECT],
    [LAST_DAYS_CANCELLING_VIEW, LAST_DAYS_CANCELLING_SUBJECT],
    [LAST_DAYS_SUBSCRIBERS_VIEW, LAST_DAYS_SUBSCRIBERS_SUBJECT],
]);
