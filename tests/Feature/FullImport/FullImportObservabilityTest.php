<?php

use App\Enums\AccountType;
use App\Enums\ImportStatus;
use App\Jobs\ProcessFullImportJob;
use App\Jobs\UndoImportJob;
use App\Models\Account;
use App\Models\Import;
use App\Services\Imports\FullImporter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FullImportFixtures as Fixtures;

/**
 * Every log line written while the callback runs, as [level, message, context].
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function fullImportLogsDuring(Closure $callback): array
{
    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = [$event->level, $event->message, $event->context];
    });

    $callback();

    return $logged;
}

/**
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $logs
 * @return array{0: string, 1: string, 2: array<string, mixed>}|null
 */
function fullImportLog(array $logs, string $message): ?array
{
    return collect($logs)->first(fn (array $line): bool => $line[1] === $message);
}

it('logs the start and the end of an import, with ids and counts only', function () {
    $user = Fixtures::user();

    $logs = fullImportLogsDuring(fn () => Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise Personal', ['new_bank_name' => 'Private Bank Name']),
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee at Secret Place', ['notes' => 'private note']),
    ]));

    $import = Import::query()->where('user_id', $user->id)->sole();

    [$level, , $started] = fullImportLog($logs, 'Full import started');

    expect($level)->toBe('info')
        ->and($started)->toMatchArray([
            'import_id' => $import->id,
            'user_id' => $user->id,
            'space_id' => $user->current_space_id,
            'source' => 'banktrack',
            'mode' => 'add',
            'attempt' => 1,
            'expected_transactions' => 1,
            'expected_balances' => 0,
            'accounts_new' => 1,
            'accounts_mapped' => 0,
            'categories_new' => 0,
        ]);

    [$level, , $finished] = fullImportLog($logs, 'Full import finished');

    expect($level)->toBe('info')
        ->and($finished)->toMatchArray([
            'import_id' => $import->id,
            'status' => 'completed',
            'imported' => 1,
            'skipped_duplicates' => 0,
            'banks_created' => 1,
            'balances_imported' => 0,
            'ai_status' => 'unavailable',
        ])
        ->and($finished)->toHaveKey('duration_seconds');

    // Nothing from the file reaches the logs: names, descriptions, notes, the file name.
    $everything = json_encode(array_map(fn (array $line): array => $line[2], $logs));

    expect($everything)->not->toContain('Wise Personal')
        ->not->toContain('Private Bank Name')
        ->not->toContain('Secret Place')
        ->not->toContain('private note')
        ->not->toContain('banktrack-export.csv');
});

it('logs the failure, keeps the real reason and shows the user a friendly one', function () {
    $user = Fixtures::user();
    $import = Import::factory()->for($user)->create(['status' => ImportStatus::Processing, 'stats' => ['stage' => 'transactions']]);

    $logs = fullImportLogsDuring(fn () => (new ProcessFullImportJob($import))->failed(new RuntimeException('Deadlock found when trying to get lock')));

    [$level, , $context] = fullImportLog($logs, 'Full import failed');

    expect($level)->toBe('error')
        ->and($context)->toMatchArray([
            'import_id' => $import->id,
            'stage' => 'transactions',
            'exception' => RuntimeException::class,
            'message' => 'Deadlock found when trying to get lock',
        ])
        ->and($import->fresh()->error)->toBe('RuntimeException: Deadlock found when trying to get lock')
        ->and($import->fresh()->status)->toBe(ImportStatus::Failed);

    $this->actingAs($user)->getJson(route('api.full-imports.show', $import))
        ->assertOk()
        ->assertJsonPath('error', 'The import stopped before it finished.')
        ->assertDontSee('Deadlock');
});

it('reports a failure it raises by hand, and leaves the worker to report the ones handle() throws', function () {
    Exceptions::fake();
    $halfDone = Import::factory()->create(['status' => ImportStatus::Processing, 'plan' => ['accounts' => []]]);

    $job = new ProcessFullImportJob($halfDone);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'preparing its accounts'));

    Exceptions::fake();
    $thrown = Import::factory()->create(['status' => ImportStatus::Processing]);

    (new ProcessFullImportJob($thrown))->failed(new RuntimeException('Thrown out of handle'));

    Exceptions::assertNothingReported();
});

it('logs a run that hands over to the next one', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Processing, 'plan' => ['resolved' => ['accounts' => [], 'categories' => []]]]);

    $this->mock(FullImporter::class)->shouldReceive('run')->once()->andReturnFalse();
    Queue::fake();

    $logs = fullImportLogsDuring(fn () => app()->call([new ProcessFullImportJob($import), 'handle']));

    [$level, , $context] = fullImportLog($logs, 'Full import resumed');

    expect($level)->toBe('warning')
        ->and($context)->toMatchArray(['import_id' => $import->id, 'attempt' => 1]);
});

it('logs what a wipe deleted', function () {
    $user = Fixtures::user();
    Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking, 'name' => 'Very Private Account']);

    $logs = fullImportLogsDuring(fn () => Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], mode: 'wipe'), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]));

    [$level, , $context] = fullImportLog($logs, 'Full import wiped the manual accounts');

    expect($level)->toBe('info')
        ->and($context)->toMatchArray(['mode' => 'wipe', 'accounts' => 1, 'transactions' => 0])
        ->and(json_encode($context))->not->toContain('Very Private Account');
});

it('logs an undo when it is requested and when it is done', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    $logs = fullImportLogsDuring(fn () => $this->delete(route('full-import.destroy', $import)));

    expect(fullImportLog($logs, 'Full import undo requested')[2])->toMatchArray(['import_id' => $import->id, 'previous_status' => 'completed'])
        ->and(fullImportLog($logs, 'Full import undone')[2])->toMatchArray([
            'import_id' => $import->id,
            'transactions' => 1,
            'accounts_deleted' => 1,
        ])
        ->and(fullImportLog($logs, 'Full import undone')[2])->toHaveKey('duration_seconds');
});

it('logs a failed undo and keeps its reason away from the client', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    Queue::fake([UndoImportJob::class]);
    $this->delete(route('full-import.destroy', $import));

    $logs = fullImportLogsDuring(fn () => (new UndoImportJob($import->fresh()))->failed(new LogicException('Lock wait timeout exceeded')));

    expect(fullImportLog($logs, 'Full import undo failed'))->toMatchArray([
        1 => 'Full import undo failed',
        2 => [...$import->logContext(), 'attempt' => 1, 'exception' => LogicException::class, 'message' => 'Lock wait timeout exceeded'],
    ])
        ->and($import->fresh()->stats['undo']['error'])->toBe('LogicException: Lock wait timeout exceeded');

    $this->getJson(route('api.full-imports.show', $import))
        ->assertOk()
        ->assertJsonPath('stats.undo.failed', true)
        ->assertJsonMissingPath('stats.undo.error')
        ->assertDontSee('Lock wait timeout');
});
