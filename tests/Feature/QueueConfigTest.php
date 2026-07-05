<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The database queue driver releases a job back to the queue once its
 * reservation is older than retry_after. If a job runs longer than that, a
 * second worker picks it up: a tries=1 job then dies with
 * MaxAttemptsExceededException and re-runs its side effects. Guard the
 * invariant so a new long-running job can never silently reintroduce it.
 * Queued Mailables and Notifications honor $timeout too, so scan them as well.
 */
test('every queued job timeout stays below the database queue retry_after', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');

    $queueableDirs = ['Jobs', 'Mail', 'Notifications'];

    $offenders = collect($queueableDirs)
        ->flatMap(fn (string $dir): array => File::allFiles(app_path($dir)))
        ->map(function ($file): string {
            $relative = Str::of($file->getPathname())
                ->after(app_path().DIRECTORY_SEPARATOR)
                ->replace(DIRECTORY_SEPARATOR, '\\')
                ->replaceLast('.php', '');

            return 'App\\'.$relative;
        })
        ->filter(fn (string $class): bool => class_exists($class))
        ->mapWithKeys(function (string $class): array {
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

            return [$class => $timeout];
        })
        ->filter(fn ($timeout): bool => is_int($timeout) && $timeout >= $retryAfter);

    expect($offenders->all())->toBe(
        [],
        'These jobs have a $timeout >= retry_after ('.$retryAfter.'s); raise DB_QUEUE_RETRY_AFTER above the longest job timeout.'
    );
});
