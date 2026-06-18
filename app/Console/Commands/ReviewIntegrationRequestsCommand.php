<?php

namespace App\Console\Commands;

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ReviewIntegrationRequestsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'integration-requests:review {--all : Pick any request from the full list and change its status}';

    /**
     * @var string
     */
    protected $description = 'Review integration requests and change their status';

    public function handle(): int
    {
        return $this->option('all') ? $this->reviewAny() : $this->reviewPending();
    }

    private function reviewPending(): int
    {
        $pending = $this->load(IntegrationRequest::query()->where('status', IntegrationRequestStatus::Pending));

        if ($pending->isEmpty()) {
            $this->info('No pending integration requests.');

            return self::SUCCESS;
        }

        $this->printTable($pending);

        $changed = 0;

        foreach ($pending as $request) {
            $decision = $this->choice(
                "Review \"{$request->name}\" ({$request->url})",
                ['approve', 'in progress', 'reject', 'not doable', 'skip'],
                'skip',
            );

            if ($decision !== 'skip' && $this->apply($request, $decision)) {
                $changed++;
            }
        }

        $this->info("Done. Updated {$changed} of {$pending->count()} requests.");

        return self::SUCCESS;
    }

    private function reviewAny(): int
    {
        $requests = $this->load(IntegrationRequest::query());

        if ($requests->isEmpty()) {
            $this->info('No integration requests.');

            return self::SUCCESS;
        }

        $this->printTable($requests, withIndex: true);

        $request = $requests->get((int) $this->ask('Which request do you want to update? (number)') - 1);

        if ($request === null) {
            $this->error('That number is not on the list.');

            return self::FAILURE;
        }

        $this->apply($request, $this->choice(
            "New status for \"{$request->name}\"",
            ['approve', 'in progress', 'reject', 'not doable'],
        ));

        $this->info("\"{$request->name}\" is now {$request->status->label()}.");

        return self::SUCCESS;
    }

    private function apply(IntegrationRequest $request, string $decision): bool
    {
        $status = match ($decision) {
            'approve' => IntegrationRequestStatus::Approved,
            'in progress' => IntegrationRequestStatus::InProgress,
            'reject' => IntegrationRequestStatus::Rejected,
            'not doable' => IntegrationRequestStatus::NotDoable,
            default => null,
        };

        if ($status === null) {
            return false;
        }

        $request->update([
            'status' => $status,
            'comment' => $this->commentFor($status),
        ]);

        return true;
    }

    private function commentFor(IntegrationRequestStatus $status): ?string
    {
        if ($status === IntegrationRequestStatus::NotDoable) {
            $comment = $this->ask('Why is this integration not doable? (shown to users)');

            while (blank($comment)) {
                $comment = $this->ask('A comment is required to mark an integration as not doable');
            }

            return $comment;
        }

        if ($status === IntegrationRequestStatus::InProgress) {
            return $this->ask('Add a comment for this request (optional, shown to users)') ?: null;
        }

        // Approving, rejecting or re-queuing drops any stale public comment.
        return null;
    }

    /**
     * @param  Builder<IntegrationRequest>  $query
     * @return Collection<int, IntegrationRequest>
     */
    private function load(Builder $query): Collection
    {
        return $query
            ->withCount('votes')
            ->with('user:id,email')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, IntegrationRequest>  $requests
     */
    private function printTable(Collection $requests, bool $withIndex = false): void
    {
        $headers = ['Name', 'URL', 'Status', 'Submitted by', 'Votes', 'Created'];

        if ($withIndex) {
            array_unshift($headers, '#');
        }

        $rows = $requests->values()->map(function (IntegrationRequest $request, int $index) use ($withIndex): array {
            $row = [
                $request->name,
                $request->url,
                $request->status->label(),
                $request->user->email,
                $request->votes_count,
                $request->created_at?->format('Y-m-d') ?? '—',
            ];

            if ($withIndex) {
                array_unshift($row, $index + 1);
            }

            return $row;
        })->all();

        $this->table($headers, $rows);
    }
}
