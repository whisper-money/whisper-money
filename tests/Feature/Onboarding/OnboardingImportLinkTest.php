<?php

use App\Http\Controllers\OnboardingImportLinkController;
use App\Mail\OnboardingImportLinkEmail;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

function importLinkUser(array $attributes = []): User
{
    return User::factory()->create(['onboarded_at' => null, ...$attributes]);
}

function importLinkAccount(User $user, array $attributes = []): Account
{
    return Account::factory()->for($user)->create([
        'type' => 'checking',
        'banking_connection_id' => null,
        ...$attributes,
    ]);
}

function importLinkUrl(Account $account): string
{
    return URL::temporarySignedRoute(
        'onboarding.import-link.show',
        now()->addDays(OnboardingImportLinkEmail::LINK_LIFETIME_DAYS),
        ['account' => $account->id],
    );
}

beforeEach(function () {
    RateLimiter::clear('onboarding-import-link');
});

it('queues the import link to the user’s own email, on the emails queue', function () {
    Mail::fake();
    $user = importLinkUser();
    $account = importLinkAccount($user);

    $this->actingAs($user)
        ->postJson('/onboarding/import-link', ['account_id' => $account->id])
        ->assertAccepted();

    Mail::assertQueued(OnboardingImportLinkEmail::class, function (OnboardingImportLinkEmail $mail) use ($user, $account): bool {
        return $mail->hasTo($user->email)
            && $mail->account->is($account)
            && $mail->queue === 'emails';
    });
});

it('puts a signed link to that account in the email, and nothing about the user in it', function () {
    $user = importLinkUser(['name' => 'Ana']);
    $account = importLinkAccount($user, ['name' => 'Joint account']);

    $mail = new OnboardingImportLinkEmail($user, $account);
    $html = $mail->render();

    $mail->assertSeeInHtml('Import your history from your computer');
    $mail->assertDontSeeInHtml('Joint account');

    preg_match('/href="([^"]*onboarding\/import-link[^"]*)"/', $html, $matches);
    $url = html_entity_decode($matches[1]);

    expect($url)->toContain($account->id)
        ->toContain('signature=')
        ->not->toContain(urlencode($user->email))
        ->not->toContain($user->email);
});

it('refuses an account that belongs to someone else', function () {
    Mail::fake();
    $user = importLinkUser();
    $other = importLinkAccount(User::factory()->create());

    $this->actingAs($user)
        ->postJson('/onboarding/import-link', ['account_id' => $other->id])
        ->assertNotFound();

    Mail::assertNothingQueued();
});

it('refuses an account a file cannot be imported into', function () {
    Mail::fake();
    $user = importLinkUser();
    $connected = importLinkAccount($user, [
        'banking_connection_id' => BankingConnection::factory()->create(['user_id' => $user->id])->id,
    ]);

    $this->actingAs($user)
        ->postJson('/onboarding/import-link', ['account_id' => $connected->id])
        ->assertUnprocessable();

    Mail::assertNothingQueued();
});

it('sends at most three links an hour', function () {
    Mail::fake();
    $user = importLinkUser();
    $account = importLinkAccount($user);

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)
            ->postJson('/onboarding/import-link', ['account_id' => $account->id])
            ->assertAccepted();
    }

    $this->actingAs($user)
        ->postJson('/onboarding/import-link', ['account_id' => $account->id])
        ->assertTooManyRequests();

    Mail::assertQueuedCount(3);
});

it('lands someone still onboarding on the import step, with the account in the session', function () {
    $user = importLinkUser();
    $account = importLinkAccount($user);

    $this->actingAs($user)
        ->get(importLinkUrl($account))
        ->assertRedirect(route('onboarding', ['step' => 'import-transactions']))
        ->assertSessionHas(OnboardingImportLinkController::FLASH_KEY, $account->id);
});

it('lands someone already onboarded on the app’s importer', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $account = importLinkAccount($user);

    $this->actingAs($user)
        ->get(importLinkUrl($account))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHas(OnboardingImportLinkController::FLASH_KEY, $account->id);
});

it('shares the landing account with the page it redirects to, once', function () {
    $user = importLinkUser();
    $account = importLinkAccount($user);

    $this->actingAs($user)->get(importLinkUrl($account));

    $this->get(route('onboarding', ['step' => 'import-transactions']))
        ->assertInertia(fn ($page) => $page
            ->where('flash.import_link_account_id', $account->id)
            ->where('initialStep', 'import-transactions'));

    $this->get(route('onboarding'))
        ->assertInertia(fn ($page) => $page->where('flash.import_link_account_id', null));
});

it('sends a logged-out visitor to log in first', function () {
    $account = importLinkAccount(importLinkUser());

    $url = importLinkUrl($account);

    // The login form, not the sign-up one, and back to the link afterwards.
    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', $url);
});

it('rejects a link without a valid signature', function () {
    $user = importLinkUser();
    $account = importLinkAccount($user);

    $this->actingAs($user)
        ->get(route('onboarding.import-link.show', ['account' => $account->id]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(importLinkUrl($account).'tampered')
        ->assertForbidden();
});

it('rejects an expired link', function () {
    $user = importLinkUser();
    $account = importLinkAccount($user);
    $url = importLinkUrl($account);

    $this->travel(OnboardingImportLinkEmail::LINK_LIFETIME_DAYS + 1)->days();

    $this->actingAs($user)->get($url)->assertForbidden();
});

it('does not open another user’s account from a forwarded link', function () {
    $account = importLinkAccount(importLinkUser());

    $this->actingAs(importLinkUser())
        ->get(importLinkUrl($account))
        ->assertNotFound()
        ->assertSessionMissing(OnboardingImportLinkController::FLASH_KEY);
});

it('still lands, without naming the account, once it can no longer take a file', function () {
    $user = importLinkUser();
    $account = importLinkAccount($user, ['archived_at' => now()]);

    $this->actingAs($user)
        ->get(importLinkUrl($account))
        ->assertRedirect(route('onboarding', ['step' => 'import-transactions']))
        ->assertSessionMissing(OnboardingImportLinkController::FLASH_KEY);
});
