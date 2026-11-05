<?php

use App\Mail\UpdateEmail;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\PriceTiers;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

const BLACK_FRIDAY_LAUNCH = ['black-friday-2026', '40% off Whisper Money until 1 December', '2026-11-27 09:00'];
const BLACK_FRIDAY_LAST_DAY = ['black-friday-last-day-2026', 'Last day: 40% off ends tonight', '2026-12-01 09:00'];

function blackFridayEvent(string $view): Event
{
    return collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, "email:update '{$view}'"));
}

/**
 * The artisan line the scheduler would run, without the PHP binary in front.
 */
function scheduledArtisanLine(Event $event): string
{
    return Str::after((string) $event->command, 'email:update');
}

function runsAt(Event $event, string $madridTime): bool
{
    Carbon::setTestNow(Carbon::parse($madridTime, 'Europe/Madrid'));

    return $event->isDue(app()) && $event->filtersPass(app());
}

/**
 * Mail faked, subscriptions on, and the high-tier price IDs the audience filter
 * resolves seeded into the cache, so no test reaches Stripe.
 */
function prepareBlackFridaySend(): void
{
    Mail::fake();
    config(['subscriptions.enabled' => true]);

    foreach (PriceTiers::plansFor('high') as $plan) {
        Cache::put("stripe_price_id:{$plan['stripe_lookup_key']}", 'price_'.$plan['stripe_lookup_key'], now()->addHour());
    }
}

dataset('black friday sends', [
    'launch' => [BLACK_FRIDAY_LAUNCH],
    'last day' => [BLACK_FRIDAY_LAST_DAY],
]);

afterEach(fn () => Carbon::setTestNow());

it('is scheduled once, at 09:00 Madrid on its own day of 2026', function (array $send) {
    [$view, $subject, $sendsAt] = $send;
    $event = blackFridayEvent($view);

    expect($event->command)
        ->toContain("--subject='{$subject}'")
        ->toContain("--audience='unsubscribed'")
        ->toContain('--exclude-demo')
        ->toContain('--no-interaction')
        ->not->toContain('--force')
        ->not->toContain('--operational')
        ->and(runsAt($event, $sendsAt))->toBeTrue()
        ->and(runsAt($event, Carbon::parse($sendsAt, 'Europe/Madrid')->subHour()->toDateTimeString()))->toBeFalse()
        ->and(runsAt($event, Carbon::parse($sendsAt, 'Europe/Madrid')->addDay()->toDateTimeString()))->toBeFalse()
        ->and(runsAt($event, Carbon::parse($sendsAt, 'Europe/Madrid')->addYear()->toDateTimeString()))->toBeFalse();
})->with('black friday sends');

it('mails free users who kept offers on, and nobody else, when the schedule runs it', function (array $send) {
    [$view, $subject] = $send;
    prepareBlackFridaySend();

    $free = User::factory()->create(['locale' => 'es']);
    $optedOut = User::factory()->create();
    UserSetting::factory()->for($optedOut)->create(['notify_marketing' => false]);
    $demo = User::factory()->create(['email' => config('app.demo.email')]);
    $subscriber = User::factory()->create();
    $subscriber->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.$subscriber->id,
        'stripe_status' => 'active',
        'stripe_price' => 'price_whatever',
    ]);

    // Exactly the line the scheduler runs, so a misspelt option or audience
    // fails here rather than at 09:00 on the day.
    expect(Artisan::call('email:update'.scheduledArtisanLine(blackFridayEvent($view))))->toBe(0);

    Mail::assertQueued(UpdateEmail::class, 1);
    Mail::assertQueued(UpdateEmail::class, fn (UpdateEmail $mail): bool => $mail->hasTo($free->email)
        && $mail->viewName === $view
        && $mail->emailSubject === $subject
        && $mail->marketing);
    expect([$optedOut, $demo, $subscriber])->each(fn ($user) => Mail::assertNotQueued(
        UpdateEmail::class,
        fn (UpdateEmail $mail): bool => $mail->hasTo($user->value->email),
    ));

    // Running it again, as a retried schedule would, sends nobody a second copy.
    Artisan::call('email:update'.scheduledArtisanLine(blackFridayEvent($view)));
    Mail::assertQueued(UpdateEmail::class, 1);
})->with('black friday sends');

it('leaves out of the last-day email whoever subscribed after the launch email', function () {
    prepareBlackFridaySend();

    $converted = User::factory()->create();
    $stillFree = User::factory()->create();

    Artisan::call('email:update'.scheduledArtisanLine(blackFridayEvent(BLACK_FRIDAY_LAUNCH[0])));

    $converted->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.$converted->id,
        'stripe_status' => 'active',
        'stripe_price' => 'price_yearly',
    ]);

    Artisan::call('email:update'.scheduledArtisanLine(blackFridayEvent(BLACK_FRIDAY_LAST_DAY[0])));

    Mail::assertQueued(UpdateEmail::class, fn (UpdateEmail $mail): bool => $mail->hasTo($converted->email) && $mail->viewName === BLACK_FRIDAY_LAUNCH[0]);
    Mail::assertNotQueued(UpdateEmail::class, fn (UpdateEmail $mail): bool => $mail->hasTo($converted->email) && $mail->viewName === BLACK_FRIDAY_LAST_DAY[0]);
    Mail::assertQueued(UpdateEmail::class, fn (UpdateEmail $mail): bool => $mail->hasTo($stillFree->email) && $mail->viewName === BLACK_FRIDAY_LAST_DAY[0]);
});

it('has a translation for every line of the email, its offer and its subject', function (array $send, string $locale) {
    [$view, $subject] = $send;
    $translations = json_decode(file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    preg_match_all(
        "/__\\('(.+?)'/",
        file_get_contents(resource_path("views/mail/updates/{$view}.blade.php"))
            .file_get_contents(resource_path('views/mail/partials/black-friday-2026-offer.blade.php')),
        $matches,
    );

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_diff([...$matches[1], $subject], array_keys($translations))))->toBe([]);
})->with('black friday sends')->with(['es', 'fr']);

it('quotes the prices checkout charges, with the 40% the code takes off', function () {
    $monthly = config('subscriptions.plans.monthly.price');
    $yearly = config('subscriptions.plans.yearly.price');

    expect([$monthly, $yearly])->toBe([8.99, 53.94])
        ->and(round($monthly * 0.6, 2))->toBe(5.39)
        ->and(round($yearly * 0.6, 2))->toBe(32.36)
        ->and(round($yearly * 0.6 / 12, 2))->toBe(2.7);
});

it('renders the code, the prices and the unsubscribe link in each language', function (array $send, string $locale, array $expected) {
    [$view, $subject] = $send;
    $mail = (new UpdateEmail(User::factory()->create(['name' => 'Ana']), $view, $subject))->locale($locale);

    $html = $mail->render();

    expect($html)->toContain('BLACKFRIDAY40')
        ->toContain('Ana')
        ->toContain('unsubscribe/marketing')
        ->toContain(route('subscribe'))
        // Signed by both founders, so nothing may speak for one of them alone.
        ->toContain(e('Álvaro & Víctor'))
        ->not->toContain('solo developer')
        ->not->toContain('en solitario');
    foreach ($expected as $text) {
        expect($html)->toContain(e($text));
    }
})->with('black friday sends')->with([
    'en' => ['en', ['€32.36 a year', '€5.39 a month for 12 months', '€53.94 a year', 'Stop receiving news and offers']],
    'es' => ['es', ['32,36 € al año', '5,39 € al mes durante 12 meses', '53,94 € al año', 'Dejar de recibir novedades y ofertas']],
    'fr' => ['fr', ['32,36 € par an', '5,39 € par mois pendant 12 mois', '53,94 € par an', 'Ne plus recevoir les nouveautés et offres']],
]);

it('sends the subject in the reader\'s language', function (array $send, string $locale, string $expected) {
    [$view, $subject] = $send;
    app()->setLocale($locale);

    expect((new UpdateEmail(User::factory()->create(), $view, $subject))->envelope()->subject)->toBe($expected);
})->with([
    'launch, en' => [BLACK_FRIDAY_LAUNCH, 'en', '40% off Whisper Money until 1 December'],
    'launch, es' => [BLACK_FRIDAY_LAUNCH, 'es', 'Whisper Money con un 40% de descuento hasta el 1 de diciembre'],
    'last day, en' => [BLACK_FRIDAY_LAST_DAY, 'en', 'Last day: 40% off ends tonight'],
    'last day, es' => [BLACK_FRIDAY_LAST_DAY, 'es', 'Último día: el 40% de descuento acaba esta noche'],
]);
