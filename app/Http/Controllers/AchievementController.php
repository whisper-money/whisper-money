<?php

namespace App\Http\Controllers;

use App\Features\Achievements;
use App\Models\MonthlySummary;
use App\Services\Achievements\Progress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * The progress screen: every medal, and the months we have closed.
 *
 * Reached from the account menu rather than the main navigation — it is
 * something to look back at, not somewhere to work — and it carries the last
 * few monthly summaries because they are the same thing: a record of what
 * already happened, not another number to act on.
 */
class AchievementController extends Controller
{
    public function __construct(private Progress $progress) {}

    public function index(Request $request): Response
    {
        abort_unless(Feature::active(Achievements::class), 404);

        $user = $request->user();

        return Inertia::render('achievements/index', [
            ...$this->progress->for($user),
            'summaries' => Inertia::defer(fn (): array => $this->summaries($request), 'progress'),
        ]);
    }

    /**
     * The last few months, in the shape the summaries screen uses. Deferred:
     * the medals are what the reader came for, and these ride the follow-up
     * request rather than holding up the first paint.
     *
     * @return list<array<string, mixed>>
     */
    private function summaries(Request $request): array
    {
        return $request->user()->monthlySummaries()
            ->whereNotNull('sent_at')
            ->orderByDesc('period')
            ->limit(3)
            ->get()
            ->map(fn (MonthlySummary $summary): array => [
                'id' => $summary->id,
                'period' => $summary->period,
                'savings_rate' => $summary->figure('cashflow.savings_rate'),
                'complete' => $summary->complete,
                'shared' => $summary->share_token !== null,
                'unread' => $summary->dismissed_at === null,
            ])
            ->all();
    }
}
