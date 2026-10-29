<?php

use App\Http\ApiV1\ApiV1Exception;
use App\Http\ApiV1\CursorPaginator;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Labels created with a tie on `created_at` every two rows, so the second
 * sort key (the id) has to break it. Returns them in the paginated order:
 * created_at desc, id desc.
 *
 * @return list<string>
 */
function seedLabelsForPagination(User $user, int $count): array
{
    for ($i = 0; $i < $count; $i++) {
        Label::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-01-01')->addMinutes(intdiv($i, 2)),
        ]);
    }

    return Label::query()
        ->forSpace($user->personalSpace)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->pluck('id')
        ->all();
}

/**
 * @param  array<string, mixed>  $query
 * @return array{ids: list<string>, has_more: bool}
 */
function labelPage(Space $space, array $query): array
{
    $page = CursorPaginator::paginate(
        Label::query()->forSpace($space),
        Request::create('/', 'GET', $query),
        [['created_at', 'desc'], ['id', 'desc']],
        JsonResource::class,
    )->response()->getData(true);

    return ['ids' => array_column($page['data'], 'id'), 'has_more' => $page['has_more']];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->space = $this->user->personalSpace;
});

it('returns the first page in the fixed order, 25 by default', function () {
    $ids = seedLabelsForPagination($this->user, 30);

    expect(labelPage($this->space, []))->toBe(['ids' => array_slice($ids, 0, 25), 'has_more' => true]);
});

it('walks forward with starting_after through ties on the sort key', function () {
    $ids = seedLabelsForPagination($this->user, 7);

    $seen = [];
    $query = ['limit' => 2];

    do {
        $page = labelPage($this->space, $query);
        $seen = [...$seen, ...$page['ids']];
        $query = ['limit' => 2, 'starting_after' => end($page['ids'])];
    } while ($page['has_more']);

    expect($seen)->toBe($ids);
});

it('walks backward with ending_before through ties on the sort key', function () {
    $ids = seedLabelsForPagination($this->user, 7);

    $seen = [];
    $query = ['limit' => 2, 'ending_before' => end($ids)];

    do {
        $page = labelPage($this->space, $query);
        $seen = [...$page['ids'], ...$seen];
        $query = ['limit' => 2, 'ending_before' => $page['ids'][0]];
    } while ($page['has_more']);

    expect($seen)->toBe(array_slice($ids, 0, -1));
});

it('keeps each backward page in the list order', function () {
    $ids = seedLabelsForPagination($this->user, 6);

    expect(labelPage($this->space, ['limit' => 3, 'ending_before' => $ids[4]]))
        ->toBe(['ids' => [$ids[1], $ids[2], $ids[3]], 'has_more' => true]);
});

it('reports has_more only when another object exists past the page', function (int $count, int $limit, bool $hasMore) {
    seedLabelsForPagination($this->user, $count);

    expect(labelPage($this->space, ['limit' => $limit])['has_more'])->toBe($hasMore);
})->with([
    'page exactly fills the list' => [3, 3, false],
    'one object left over' => [4, 3, true],
    'page larger than the list' => [2, 3, false],
]);

it('returns an empty page after the last object', function () {
    $ids = seedLabelsForPagination($this->user, 3);

    expect(labelPage($this->space, ['starting_after' => end($ids)]))->toBe(['ids' => [], 'has_more' => false]);
    expect(labelPage($this->space, ['ending_before' => $ids[0]]))->toBe(['ids' => [], 'has_more' => false]);
});

it('returns an empty list when there is nothing to page', function () {
    expect(labelPage($this->space, []))->toBe(['ids' => [], 'has_more' => false]);
});

it('accepts the limit bounds and rejects anything outside them', function (mixed $limit, bool $valid) {
    seedLabelsForPagination($this->user, 2);

    $attempt = fn () => labelPage($this->space, ['limit' => $limit]);

    $valid ? expect($attempt())->toBeArray() : expect($attempt)->toThrow(ValidationException::class);
})->with([
    'minimum' => [1, true],
    'maximum' => [100, true],
    'zero' => [0, false],
    'over the maximum' => [101, false],
    'not a number' => ['many', false],
]);

it('caps a page at the limit', function () {
    $ids = seedLabelsForPagination($this->user, 5);

    expect(labelPage($this->space, ['limit' => 1]))->toBe(['ids' => [$ids[0]], 'has_more' => true]);
});

it('refuses both cursors at once', function () {
    $ids = seedLabelsForPagination($this->user, 3);

    labelPage($this->space, ['starting_after' => $ids[0], 'ending_before' => $ids[2]]);
})->throws(ValidationException::class);

it('answers 400 for a cursor that does not exist or lies outside the scoped query', function (Closure $cursor) {
    seedLabelsForPagination($this->user, 2);

    try {
        labelPage($this->space, ['starting_after' => $cursor()]);
        $this->fail('Expected an invalid cursor to be rejected.');
    } catch (ApiV1Exception $e) {
        expect($e->getStatusCode())->toBe(400)
            ->and($e->errorCode)->toBe('invalid_cursor');
    }
})->with([
    'unknown uuid' => [fn () => (string) Str::uuid()],
    'not a uuid' => [fn () => 'abc'],
    'another user\'s label' => [fn () => Label::factory()->create()->id],
]);

it('requires the order to end on the primary key', function () {
    CursorPaginator::paginate(
        Label::query()->forSpace($this->space),
        Request::create('/'),
        [['created_at', 'desc']],
        JsonResource::class,
    );
})->throws(InvalidArgumentException::class);
