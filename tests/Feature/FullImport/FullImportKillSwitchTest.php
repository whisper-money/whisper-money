<?php

use App\Enums\ImportStatus;
use App\Features\FullImport;
use App\Jobs\ProcessFullImportJob;
use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Support\FullImportFixtures as Fixtures;

it('closes the import to an in-window user when the switch is off, with the same answers as for anyone not eligible', function () {
    $user = Fixtures::user(['onboarded_at' => now()->subDays(2)]);

    config(['full_import.enabled' => false]);

    expect($user->canUseFullImport())->toBeFalse()
        ->and($user->canSeeFullImportSettings())->toBeFalse()
        ->and($user->fullImportWindowEndsAt())->toBeNull();

    $this->actingAs($user)->get(route('accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.fullImport', false)
            ->where('features.fullImportSettings', false));

    $this->getJson(route('api.full-imports.context'))->assertForbidden();
    $this->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertForbidden();
    $this->get(route('full-import.index'))->assertNotFound();
    $this->get(route('full-import.create'))->assertRedirect(route('full-import.index'));
});

it('closes it during onboarding too', function () {
    $user = Fixtures::user(['onboarded_at' => null]);

    config(['full_import.enabled' => false]);

    expect($user->canUseFullImport())->toBeFalse();
});

it('keeps it open to a user with the FullImport flag, so it can still be tried in production', function () {
    $user = Fixtures::user(['onboarded_at' => now()->subDays(2)]);
    Feature::for($user)->activate(FullImport::class);

    config(['full_import.enabled' => false]);

    expect($user->canUseFullImport())->toBeTrue();

    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    expect($import->status)->toBe(ImportStatus::Completed);
});

it('lets an import already running finish', function () {
    $user = Fixtures::user();

    Queue::fake([ProcessFullImportJob::class]);
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ])->refresh();

    config(['full_import.enabled' => false]);

    // Its history stays reachable while it runs.
    expect($user->canSeeFullImportSettings())->toBeTrue();

    app()->call([new ProcessFullImportJob($import), 'handle']);

    expect($import->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(Transaction::query()->where('import_id', $import->id)->count())->toBe(1);

    $this->actingAs($user)->getJson(route('api.full-imports.show', $import))->assertOk()->assertJsonPath('status', 'completed');
});

it('keeps the history and the undo of past imports', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    config(['full_import.enabled' => false]);

    $this->actingAs($user)->get(route('full-import.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canStart', false)
            ->where('imports.0.id', $import->id)
            ->where('imports.0.undoable', true));

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');

    expect($import->fresh()->undone_at)->not->toBeNull()
        ->and(Transaction::query()->where('import_id', $import->id)->exists())->toBeFalse();
});

it('refuses the rest of an upload started before the switch went off', function () {
    $user = Fixtures::user();

    $id = $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertCreated()->json('id');

    config(['full_import.enabled' => false]);

    $this->postJson(route('api.full-imports.chunks.store', $id), [
        'kind' => 'transactions', 'position' => 0, 'rows' => [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')],
    ])->assertForbidden();
    $this->postJson(route('api.full-imports.start', $id))->assertForbidden();

    expect(Import::query()->find($id)->status)->toBe(ImportStatus::Draft);
});

it('is on unless configured otherwise', function () {
    expect(config('full_import.enabled'))->toBeTrue();
});
