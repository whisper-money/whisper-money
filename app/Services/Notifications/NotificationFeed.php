<?php

namespace App\Services\Notifications;

use App\Models\MonthlySummary;
use App\Models\User;
use App\Notifications\MonthlySummaryReady;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * What the bell shows, and what reading a row does.
 *
 * Every notification type lands in the same `notifications` table, so this is
 * the one place that knows how to turn a stored row into a line the reader can
 * act on: a kind for the glyph, a title, a body, and where it leads. The stored
 * data is kept to identifiers and frozen sentences; anything that depends on the
 * reader's language today is built here, at read time.
 */
class NotificationFeed
{
    /**
     * Rows the bell shows before pointing at the full page.
     */
    private const RECENT = 6;

    /**
     * ponytail: the page shows the latest 50 rows in one go; paginate the day
     * someone actually scrolls past them.
     */
    private const PAGE = 50;

    /**
     * The badge and the latest rows, shared with every page.
     *
     * @return array{unread: int, recent: list<array<string, mixed>>}
     */
    public function forBell(User $user): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'recent' => $this->present($user->notifications()->latest()->limit(self::RECENT)->get()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function page(User $user): array
    {
        return $this->present($user->notifications()->latest()->limit(self::PAGE)->get());
    }

    /**
     * Mark one row read and hand back where it points. Reading the row that
     * announced a summary also puts the dashboard notice for it away, so the
     * two never nag about the same month.
     */
    public function open(DatabaseNotification $notification): ?string
    {
        $notification->markAsRead();

        $this->dismissNoticesFor(collect([$notification]));

        return $this->presentRow($notification)['url'];
    }

    public function markAllRead(User $user): void
    {
        $unread = $user->unreadNotifications()->get();

        $user->unreadNotifications()->update(['read_at' => now()]);

        $this->dismissNoticesFor($unread);
    }

    /**
     * The row for a report is read the moment the report is opened or its
     * notice dismissed, wherever that happened.
     */
    public function markReadForSummary(MonthlySummary $summary): void
    {
        // Addressed by the ids on the summary rather than through `$summary->user`,
        // which would load a reader nothing here reads.
        DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('notifiable_id', $summary->user_id)
            ->whereNull('read_at')
            ->where('type', MonthlySummaryReady::class)
            ->where('data->summary_id', $summary->id)
            ->update(['read_at' => now()]);
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     */
    private function dismissNoticesFor(Collection $notifications): void
    {
        $summaryIds = $notifications
            ->where('type', MonthlySummaryReady::class)
            ->map(fn (DatabaseNotification $notification): ?string => $notification->data['summary_id'] ?? null)
            ->filter()
            ->values();

        if ($summaryIds->isEmpty()) {
            return;
        }

        // Through the model, so dismissing here and dismissing from the notice
        // stay one and the same rule.
        MonthlySummary::query()
            ->whereIn('id', $summaryIds)
            ->whereNull('dismissed_at')
            ->get()
            ->each->dismiss();
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        return $rows
            ->map(fn (DatabaseNotification $notification): array => $this->presentRow($notification))
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, kind: string, title: string, body: ?string, url: ?string, read_at: ?string, created_at: ?string}
     */
    private function presentRow(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            ...match ($notification->type) {
                MonthlySummaryReady::class => $this->monthlySummaryRow($notification->data),
                default => [
                    'kind' => 'other',
                    'title' => class_basename($notification->type),
                    'body' => null,
                    'url' => null,
                ],
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: string, title: string, body: ?string, url: string}
     */
    private function monthlySummaryRow(array $data): array
    {
        // The title follows the reader's current language; the headline was
        // frozen in the language they read the email in. They can differ after a
        // language switch, and that is the lesser evil: a frozen title would be
        // wrong for everyone who switched, a re-derived headline costs a report
        // build per row.
        $month = Carbon::createFromFormat('Y-m-d', $data['period'].'-01')
            ->locale(app()->getLocale())
            ->isoFormat('MMMM');

        return [
            'kind' => 'monthly_summary',
            'title' => __('Your :month summary is ready', ['month' => $month]),
            'body' => $data['headline'] ?? null,
            'url' => route('monthly-summaries.show', $data['summary_id']),
        ];
    }
}
