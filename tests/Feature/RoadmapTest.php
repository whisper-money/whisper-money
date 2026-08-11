<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * Fakes both UserJot endpoints: the roadmap listing returns one submission per
 * status, and each submission detail reports two status changes.
 */
function fakeUserJot(): void
{
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, 'x1.submission.getPage')) {
            return Http::response(['result' => ['data' => ['json' => [
                'events' => [
                    ['type' => 'STATUS_CHANGED', 'createdAt' => '2026-04-02T10:00:00.000Z'],
                    ['type' => 'COMMENT', 'createdAt' => '2026-09-09T10:00:00.000Z'],
                    ['type' => 'STATUS_CHANGED', 'createdAt' => '2026-07-01T10:00:00.000Z'],
                ],
            ]]]]);
        }

        preg_match('/(PLANNED|PROGRESS|COMPLETED)/', $url, $matches);
        $status = $matches[1] ?? 'PLANNED';

        return Http::response(['result' => ['data' => ['json' => [
            'submissions' => [
                [
                    'title' => "{$status} item",
                    'body' => "**{$status}**\n\nbody",
                    'slug' => mb_strtolower($status).'-item',
                    'createdAt' => '2026-01-15T10:00:00.000Z',
                ],
            ],
            'nextPage' => null,
        ]]]]);
    });
}

/**
 * @return array<string, string>
 */
function expectedRoadmapItem(string $status, string $mappedStatus): array
{
    return [
        'title' => "{$status} item",
        'body' => "{$status} body",
        'status' => $mappedStatus,
        // The latest status change wins over the creation date.
        'date' => '2026-07-01T10:00:00.000Z',
        'url' => 'https://whispermoney.userjot.com/board/p/'.mb_strtolower($status).'-item',
    ];
}

beforeEach(function () {
    Cache::forget('roadmap-items');
});

test('guests see the roadmap ordered by status, as plain text, linked to its ticket', function () {
    fakeUserJot();

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('roadmap')
                ->where('items', [
                    expectedRoadmapItem('PLANNED', 'planned'),
                    expectedRoadmapItem('PROGRESS', 'in_progress'),
                    expectedRoadmapItem('COMPLETED', 'shipped'),
                ])
        );
});

test('a submission that never changed status keeps its creation date', function () {
    Http::fake(function (Request $request) {
        if (str_contains(urldecode($request->url()), 'x1.submission.getPage')) {
            return Http::response(['result' => ['data' => ['json' => ['events' => []]]]]);
        }

        return Http::response(['result' => ['data' => ['json' => [
            'submissions' => [[
                'title' => 'Fresh idea',
                'body' => 'Body',
                'slug' => 'fresh-idea',
                'createdAt' => '2026-01-15T10:00:00.000Z',
            ]],
            'nextPage' => null,
        ]]]]);
    });

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page->where('items.0.date', '2026-01-15T10:00:00.000Z')
        );
});

test('submissions sharing a status are ordered by their most recent move', function () {
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, 'x1.submission.getPage')) {
            return Http::response(['result' => ['data' => ['json' => ['events' => []]]]]);
        }

        return Http::response(['result' => ['data' => ['json' => [
            'submissions' => str_contains($url, 'COMPLETED') ? [
                ['title' => 'Older', 'body' => '', 'slug' => 'older', 'createdAt' => '2026-02-01T10:00:00.000Z'],
                ['title' => 'Newer', 'body' => '', 'slug' => 'newer', 'createdAt' => '2026-05-01T10:00:00.000Z'],
            ] : [],
            'nextPage' => null,
        ]]]]);
    });

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('items.0.title', 'Newer')
                ->where('items.1.title', 'Older')
        );
});

test('the roadmap is fetched once and served from cache afterwards', function () {
    fakeUserJot();

    $this->get(route('roadmap'))->assertOk();
    $this->get(route('roadmap'))->assertOk();

    // Three listing calls plus one detail call per submission, once in total.
    // Inertia's SSR request goes through the same fake, so it is filtered out.
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.userjot.com')))
        ->toHaveCount(6);
});

test('an unreachable UserJot renders an empty roadmap instead of failing', function () {
    Http::fake(fn () => Http::response(status: 500));

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('items', []));

    expect(Cache::has('roadmap-items'))->toBeFalse();
});
