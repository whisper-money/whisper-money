<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Public roadmap, mirrored from the UserJot board. UserJot has no public API,
 * so we read the same tRPC endpoints its own frontend calls and cache the
 * result for a day.
 */
class RoadmapController extends Controller
{
    private const CACHE_KEY = 'roadmap-items';

    private const HOST = 'whispermoney.userjot.com';

    private const ROADMAP_ENDPOINT = 'https://api.userjot.com/trpc/x1.roadmap.getPage';

    private const SUBMISSION_ENDPOINT = 'https://api.userjot.com/trpc/x1.submission.getPage';

    /**
     * UserJot statuses, in the order they are rendered.
     */
    private const STATUSES = [
        'PLANNED' => 'planned',
        'PROGRESS' => 'in_progress',
        'COMPLETED' => 'shipped',
    ];

    public function index(): Response
    {
        return Inertia::render('roadmap', [
            'canRegister' => config('auth.registration_enabled'),
            'items' => $this->items(),
        ]);
    }

    /**
     * @return array<int, array{title: string, body: string, status: string, date: string, url: string}>
     */
    private function items(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addDay(), fn (): array => $this->fetch());
        } catch (Throwable) {
            // ponytail: an unreachable UserJot renders the empty state, not a 500
            return [];
        }
    }

    /**
     * @return array<int, array{title: string, body: string, status: string, date: string, url: string}>
     */
    private function fetch(): array
    {
        $items = [];

        foreach (self::STATUSES as $userJotStatus => $status) {
            $submissions = Http::withHeader('x-host', self::HOST)
                ->get(self::ROADMAP_ENDPOINT, [
                    'input' => $this->input(['limit' => 50, 'status' => [$userJotStatus]]),
                ])
                ->throw()
                ->json('result.data.json.submissions', []);

            foreach ($submissions as $submission) {
                $items[] = [
                    'title' => (string) $submission['title'],
                    'body' => $this->toPlainText((string) ($submission['body'] ?? '')),
                    'status' => $status,
                    'date' => (string) $submission['createdAt'],
                    'url' => 'https://'.self::HOST.'/board/p/'.$submission['slug'],
                    'slug' => (string) $submission['slug'],
                ];
            }
        }

        return $this->sortByStatusThenDate($this->withStatusDates($items));
    }

    /**
     * Statuses keep the order they are declared in; within a status the most
     * recently moved submission comes first.
     *
     * @param  array<int, array<string, string>>  $items
     * @return array<int, array<string, string>>
     */
    private function sortByStatusThenDate(array $items): array
    {
        $order = array_values(self::STATUSES);

        usort($items, fn (array $first, array $second): int => [
            array_search($first['status'], $order, strict: true), $second['date'],
        ] <=> [
            array_search($second['status'], $order, strict: true), $first['date'],
        ]);

        return $items;
    }

    /**
     * The roadmap listing only knows when a submission was opened, which for
     * shipped work is months before it moved. The date each submission last
     * changed status lives on its detail endpoint, so those are fetched
     * concurrently and fall back to the creation date.
     *
     * @param  array<int, array<string, string>>  $items
     * @return array<int, array<string, string>>
     */
    private function withStatusDates(array $items): array
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (array $item) => $pool->withHeader('x-host', self::HOST)
                ->get(self::SUBMISSION_ENDPOINT, ['input' => $this->input(['slug' => $item['slug']])]),
            $items,
        ));

        foreach ($items as $index => $item) {
            $items[$index]['date'] = $this->lastStatusChange($responses[$index] ?? null) ?? $item['date'];
            unset($items[$index]['slug']);
        }

        return $items;
    }

    /**
     * ISO-8601 timestamps sort lexicographically, so the newest status change
     * is simply the largest one.
     */
    private function lastStatusChange(mixed $response): ?string
    {
        if (! $response instanceof ClientResponse || $response->failed()) {
            return null;
        }

        $dates = collect($response->json('result.data.json.events', []))
            ->where('type', 'STATUS_CHANGED')
            ->pluck('createdAt')
            ->filter();

        return $dates->max();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function input(array $payload): string
    {
        return (string) json_encode(['json' => $payload]);
    }

    /**
     * Submissions are written in Markdown by whoever opened them, and the
     * roadmap shows them as a single paragraph of running text.
     *
     * ponytail: drops the emphasis markers instead of rendering Markdown; a
     * renderer is only worth it if we ever show the full submission.
     */
    private function toPlainText(string $body): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['**', '__'], '', $body)));
    }
}
