<?php

use App\Enums\BankingProvider;
use App\Enums\DripEmailType;
use App\Mail\BankNoticeEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Mail::fake();

    $this->notices = sys_get_temp_dir().'/notices-'.Str::random(8);
    File::ensureDirectoryExists($this->notices);
});

afterEach(function () {
    File::deleteDirectory($this->notices);
});

/**
 * Writes one notice file, and returns the base path the command is given.
 *
 * @param  array<string, string>  $bodies  Markdown keyed by locale
 */
function notice(array $bodies, string $name = 'a-notice'): string
{
    $base = test()->notices.'/'.$name;

    foreach ($bodies as $locale => $body) {
        File::put("{$base}.{$locale}.md", $body);
    }

    return $base;
}

/**
 * The notice used wherever the copy itself is not what is being tested.
 */
function englishNotice(string $name = 'a-notice'): string
{
    return notice(['en' => "# Heads up about your bank\n\nHi :name, here is the news."], $name);
}

/**
 * A user with one Enable Banking connection to "QA Notice Bank" (ES).
 *
 * @param  array<string, mixed>  $connection
 */
function noticeUser(string $email, array $connection = [], array $attributes = []): User
{
    $user = User::factory()->create(['email' => $email, ...$attributes]);

    BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'QA Notice Bank',
        'aspsp_country' => 'ES',
        ...$connection,
    ]);

    return $user;
}

test('notifies every user connected to the bank', function () {
    $first = noticeUser('first@example.com');
    $second = noticeUser('second@example.com');

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice(), '--force' => true])
        ->expectsOutputToContain('2 user(s) connected to QA Notice Bank (ES) to notify.')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 2);

    foreach ([$first, $second] as $user) {
        Mail::assertQueued(BankNoticeEmail::class, fn (BankNoticeEmail $mail) => $mail->hasTo($user->email));
    }
});

test('notifies a bank present in several countries in all of them', function () {
    noticeUser('es@example.com');
    noticeUser('de@example.com', ['aspsp_country' => 'DE']);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice(), '--force' => true])
        ->expectsOutputToContain('2 user(s) connected to QA Notice Bank (DE, ES) to notify.')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 2);
});

test('the country option narrows a send to one country', function () {
    $spanish = noticeUser('es@example.com');
    noticeUser('de@example.com', ['aspsp_country' => 'DE']);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice(), '--country' => 'es', '--force' => true])
        ->expectsOutputToContain('1 user(s) connected to QA Notice Bank (ES) to notify.')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 1);
    Mail::assertQueued(BankNoticeEmail::class, fn (BankNoticeEmail $mail) => $mail->hasTo($spanish->email));
});

test('leaves users of other banks alone', function () {
    noticeUser('other@example.com', ['aspsp_name' => 'CaixaBank']);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice(), '--force' => true])
        ->expectsOutputToContain('No Enable Banking connection to QA Notice Bank.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('ignores connections a notice has no business reaching', function (string $case) {
    $user = noticeUser('skipped@example.com', $case === 'other provider' ? ['provider' => BankingProvider::Wise] : []);

    match ($case) {
        'deleted connection' => $user->bankingConnections()->first()->delete(),
        'deleted account' => $user->delete(),
        default => null,
    };

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice(), '--force' => true])
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
})->with(['other provider', 'deleted connection', 'deleted account']);

test('sends a single email to a user with several connections to the same bank', function () {
    $user = noticeUser('multi@example.com');
    BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'QA Notice Bank',
        'aspsp_country' => 'ES',
    ]);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice(), '--force' => true])
        ->expectsOutputToContain('1 user(s) connected to QA Notice Bank (ES) to notify.')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 1);
});

test('the subject is the heading the notice opens with', function () {
    noticeUser('subject@example.com');
    $base = notice(['en' => "# Something happened at your bank\n\nHi :name."]);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true])
        ->expectsOutputToContain('Something happened at your bank')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, fn (BankNoticeEmail $mail) => $mail->envelope()->subject === 'Something happened at your bank');
});

test('refuses a notice with no English file', function () {
    noticeUser('no-english@example.com');
    $base = notice(['es' => "# Aviso\n\nHola :name."]);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true])
        ->expectsOutputToContain('No English notice at')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('refuses a notice that does not open with a heading', function (string $locale) {
    noticeUser('no-heading@example.com');
    $base = notice(['en' => "# Fine\n\nHi :name.", $locale => "No heading here.\n\nHola :name."]);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true])
        ->expectsOutputToContain('does not start with a heading')
        ->assertFailed();

    Mail::assertNothingOutgoing();
})->with(['en', 'es']);

test('refuses a notice that is not there at all', function () {
    noticeUser('missing@example.com');

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $this->notices.'/nope', '--force' => true])
        ->expectsOutputToContain('No English notice at')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('takes the path of any of the locale files, or of none of them', function (string $suffix) {
    noticeUser('path@example.com');
    $base = notice(['en' => "# Fine\n\nHi :name.", 'es' => "# Bien\n\nHola :name."]);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base.$suffix, '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 1);
})->with(['', '.en.md', '.es.md']);

test('a dry run sends nothing, and says who would have received what', function () {
    noticeUser('dry@example.com', attributes: ['locale' => 'es']);
    $base = notice(['en' => "# Heads up\n\nHi :name.", 'es' => "# Atención\n\nHola :name."]);

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--dry-run' => true])
        ->expectsOutputToContain('dry@example.com')
        ->expectsOutputToContain('[dry-run] No emails sent.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
});

test('a declined confirmation sends nothing', function () {
    noticeUser('declined@example.com');

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice()])
        ->expectsConfirmation('Send "Heads up about your bank" to 1 user(s) connected to QA Notice Bank (ES)?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('records a mail log naming both the bank and the notice', function () {
    $user = noticeUser('logged@example.com');

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice('july-outage'), '--force' => true])
        ->assertSuccessful();

    assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::BankNotice->value,
        'email_identifier' => 'qa-notice-bank-july-outage',
    ]);
});

test('does not send the same notice to the same user twice', function () {
    noticeUser('once@example.com');
    $base = englishNotice();

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true])->assertSuccessful();

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true])
        ->expectsOutputToContain('everyone connected already got this notice')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 1);
    assertDatabaseCount('user_mail_logs', 1);
});

test('a second notice to the same bank is not suppressed by the first', function () {
    noticeUser('twice@example.com');

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice('first-notice'), '--force' => true])->assertSuccessful();
    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => englishNotice('second-notice'), '--force' => true])->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 2);
    assertDatabaseCount('user_mail_logs', 2);
});

test('resend asks for confirmation even with force, and sends again once given', function () {
    noticeUser('again@example.com');
    $base = englishNotice();

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true])->assertSuccessful();

    artisan('banking:notify-users', ['aspsp' => 'QA Notice Bank', 'notice' => $base, '--force' => true, '--resend' => true])
        ->expectsConfirmation('Send "Heads up about your bank" to 1 user(s) connected to QA Notice Bank (ES)?', 'yes')
        ->assertSuccessful();

    Mail::assertQueued(BankNoticeEmail::class, 2);
    assertDatabaseCount('user_mail_logs', 1);
});

test('each recipient reads the notice in their own language, and English when they have none', function (string $locale, string $expected) {
    $user = User::factory()->create(['locale' => $locale, 'name' => 'Marc']);
    $notices = ['en' => "# Heads up\n\nHi :name.", 'es' => "# Atención\n\nHola :name."];

    App::setLocale($user->preferredLocale());

    expect((new BankNoticeEmail($user, $notices))->body())->toContain($expected);
})->with([
    'own language' => ['es', 'Hola Marc.'],
    'no file of their own' => ['fr', 'Hi Marc.'],
]);

test('renders the file as markdown, and never as blade', function () {
    $user = User::factory()->create(['name' => 'Marc']);
    $notices = ['en' => "# Heads up\n\nHi :name, this is **important**.\n\n{{ 40 + 2 }}"];

    $html = (string) (new BankNoticeEmail($user, $notices))->render();

    expect($html)
        ->toContain('Heads up')
        ->toContain('important</strong>')
        ->toContain('Hi Marc,')
        // An operator-written file is data, not a template: it must not be able
        // to run code just by being sent.
        ->toContain('{{ 40 + 2 }}')
        ->not->toContain('>42<');
});
