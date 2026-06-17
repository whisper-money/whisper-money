<?php

namespace App\Console\Commands;

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use Illuminate\Console\Command;

class ReviewIntegrationRequestsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'integration-requests:review';

    /**
     * @var string
     */
    protected $description = 'Review pending integration requests and approve or reject them';

    public function handle(): int
    {
        $pending = IntegrationRequest::query()
            ->where('status', IntegrationRequestStatus::Pending)
            ->withCount('votes')
            ->with('user:id,email')
            ->orderBy('created_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending integration requests.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'URL', 'Submitted by', 'Votes', 'Created'],
            $pending->map(fn (IntegrationRequest $request): array => [
                $request->name,
                $request->url,
                $request->user->email,
                $request->votes_count,
                $request->created_at?->format('Y-m-d') ?? '—',
            ])->all(),
        );

        $approved = 0;
        $rejected = 0;
        $notDoable = 0;

        foreach ($pending as $request) {
            $decision = $this->choice(
                "Review \"{$request->name}\" ({$request->url})",
                ['approve', 'reject', 'not doable', 'skip'],
                'skip',
            );

            if ($decision === 'approve') {
                $request->update(['status' => IntegrationRequestStatus::Approved]);
                $approved++;
            } elseif ($decision === 'reject') {
                $request->update(['status' => IntegrationRequestStatus::Rejected]);
                $rejected++;
            } elseif ($decision === 'not doable') {
                $comment = $this->ask('Why is this integration not doable? (shown to users)');

                while (blank($comment)) {
                    $comment = $this->ask('A comment is required to mark an integration as not doable');
                }

                $request->update([
                    'status' => IntegrationRequestStatus::NotDoable,
                    'comment' => $comment,
                ]);
                $notDoable++;
            }
        }

        $skipped = $pending->count() - $approved - $rejected - $notDoable;
        $this->info("Done. Approved: {$approved}, rejected: {$rejected}, not doable: {$notDoable}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
