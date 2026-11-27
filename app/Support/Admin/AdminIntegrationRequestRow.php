<?php

namespace App\Support\Admin;

use App\Models\IntegrationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * An integration request as the admin pages list them.
 */
class AdminIntegrationRequestRow
{
    /**
     * Requests with their vote and unique-voter counts, most voted first.
     *
     * One user may vote a request more than once, so `voters_count` (distinct
     * voters) can be lower than `votes_count`.
     *
     * @return Builder<IntegrationRequest>
     */
    public static function query(): Builder
    {
        return IntegrationRequest::query()
            ->withCount([
                'votes',
                'votes as voters_count' => fn (Builder $votes) => $votes->select(DB::raw('count(distinct user_id)')),
            ])
            // Authors who deleted their account are soft-deleted, but their
            // requests linger; load them trashed so each row still says who.
            ->with(['user' => fn ($user) => $user->withTrashed()->select('id', 'email')])
            ->orderByDesc('votes_count')
            ->orderByDesc('created_at');
    }

    /**
     * @return array{id: string, name: string, url: string, status: string, comment: ?string, votes_count: int, voters_count: int, submitted_by: ?string, created_at: ?string}
     */
    public static function from(IntegrationRequest $request): array
    {
        return [
            'id' => $request->id,
            'name' => $request->name,
            'url' => $request->url,
            'status' => $request->status->value,
            'comment' => $request->comment,
            'votes_count' => (int) $request->votes_count,
            'voters_count' => (int) $request->voters_count,
            'submitted_by' => $request->user?->email,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}
