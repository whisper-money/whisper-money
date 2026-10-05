<?php

use App\Features\FullImport;
use App\Models\Import;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Support\FullImportFixtures as Fixtures;

function fullImportUserOnboarded(?int $daysAgo): User
{
    return User::factory()->create([
        'onboarded_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
        'locale' => 'en',
    ]);
}

it('is open while onboarding and for the first 15 days after it', function (?int $daysAgo, bool $eligible) {
    expect(fullImportUserOnboarded($daysAgo)->canUseFullImport())->toBe($eligible);
})->with([
    'still onboarding' => [null, true],
    'onboarded today' => [0, true],
    'onboarded 14 days ago' => [14, true],
    'onboarded 15 days ago' => [15, false],
    'onboarded 40 days ago' => [40, false],
]);

it('opens past the window with the FullImport flag', function () {
    $user = fullImportUserOnboarded(40);

    Feature::for($user)->activate(FullImport::class);

    expect($user->canUseFullImport())->toBeTrue()
        ->and($user->fullImportWindowEndsAt())->toBeNull();
});

it('says when the window closes while it is open', function () {
    $user = fullImportUserOnboarded(5);

    expect($user->fullImportWindowEndsAt()?->toDateString())->toBe(now()->addDays(10)->toDateString());
});

it('shares both answers with the frontend, the Settings one only where the Settings menu reads it', function () {
    $this->actingAs(fullImportUserOnboarded(3))
        ->get(route('accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.fullImport', true)
            ->where('features.fullImportSettings', true));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.fullImport', true)
            ->where('features.fullImportSettings', false));

    $this->actingAs(fullImportUserOnboarded(40))
        ->get(route('accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.fullImport', false)
            ->where('features.fullImportSettings', false));
});

it('refuses the endpoints and hides the page outside the window', function () {
    $user = fullImportUserOnboarded(40);

    $this->actingAs($user)->getJson(route('api.full-imports.context'))->assertForbidden();
    $this->postJson(route('api.full-imports.store'), Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]))->assertForbidden();
    $this->get(route('full-import.index'))->assertNotFound();
    $this->get(route('full-import.create'))->assertRedirect(route('full-import.index'));
});

it('runs the whole import for a user still in onboarding', function () {
    $user = Fixtures::user(['onboarded_at' => null]);

    $this->actingAs($user)->getJson(route('api.full-imports.context'))
        ->assertOk()
        ->assertJsonPath('inOnboarding', true);

    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    expect($import->stats['transactions']['imported'])->toBe(1);
});

it('keeps the Settings page, and undo, past the window while an import can be undone', function () {
    $user = fullImportUserOnboarded(40);
    $import = Import::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('full-import.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/full-import')
            ->where('canStart', false)
            ->where('imports.0.id', $import->id)
            ->where('imports.0.undoable', true));

    expect($user->canSeeFullImportSettings())->toBeTrue();

    $this->delete(route('full-import.destroy', $import))->assertRedirect(route('full-import.index'));

    expect($import->fresh()->undone_at)->not->toBeNull()
        ->and($user->fresh()->canSeeFullImportSettings())->toBeFalse();
});

it('is blocked on the shared demo and press accounts', function (string $configKey) {
    config([$configKey => 'shared@whisper.money']);

    $user = User::factory()->create(['email' => 'shared@whisper.money', 'onboarded_at' => null]);

    expect($user->canUseFullImport())->toBeFalse()
        ->and($user->canSeeFullImportSettings())->toBeFalse();

    $this->actingAs($user)->getJson(route('api.full-imports.context'))->assertForbidden();
    $this->postJson(route('api.full-imports.store'), Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]))
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is not available on a shared account.');
    $this->get(route('full-import.index'))->assertNotFound();
})->with(['demo' => 'app.demo.email', 'press' => 'app.press.email']);

it('lets nobody else read or undo an import', function () {
    $import = Import::factory()->create();
    $intruder = fullImportUserOnboarded(1);

    $this->actingAs($intruder)->getJson(route('api.full-imports.show', $import))->assertForbidden();
    $this->delete(route('full-import.destroy', $import))->assertForbidden();

    expect($import->fresh()->undone_at)->toBeNull();
});
