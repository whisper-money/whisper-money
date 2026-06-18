<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationRequestStatus;
use App\Http\Requests\StoreIntegrationRequestRequest;
use App\Models\IntegrationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;

class IntegrationRequestController extends Controller
{
    private const FREE_MONTHLY_ACTION_LIMIT = 3;

    private const PRO_MONTHLY_ACTION_LIMIT = 9;

    public function index(Request $request, DashboardController $dashboard): Response
    {
        // Render the dashboard with the integration-requests drawer opened on top of it.
        return $dashboard($request)->with('openIntegrationRequests', true);
    }

    public function data(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'requests' => $this->list($user),
            'actionsRemaining' => $this->actionsRemaining($user),
        ]);
    }

    public function store(StoreIntegrationRequestRequest $request): JsonResponse
    {
        $user = $request->user();

        // Creating a request also auto-votes it for the author, so it costs two actions.
        if ($this->actionsRemaining($user) < 2) {
            return $this->limitReachedResponse($user);
        }

        $integrationRequest = $user->integrationRequests()->create([
            ...$request->only(['name', 'url']),
            // The admin curates the board, so their proposals skip moderation.
            'status' => $user->isAdmin() ? IntegrationRequestStatus::Approved : IntegrationRequestStatus::Pending,
        ]);
        $integrationRequest->votes()->create(['user_id' => $user->id]);

        return $this->payload($user, 201);
    }

    public function vote(Request $request, IntegrationRequest $integrationRequest): JsonResponse
    {
        $user = $request->user();

        $canVote = in_array($integrationRequest->status, [IntegrationRequestStatus::Approved, IntegrationRequestStatus::InProgress], true)
            || ($integrationRequest->status === IntegrationRequestStatus::Pending
                && $integrationRequest->user_id === $user->id);

        if (! $canVote) {
            abort(404);
        }

        // Votes are not toggles: a user may back the same integration as many
        // times as they have actions left, each vote pushing it up the board.
        if ($this->actionsRemaining($user) <= 0) {
            return $this->limitReachedResponse($user);
        }

        $integrationRequest->votes()->create(['user_id' => $user->id]);

        return $this->payload($user);
    }

    public function removeVote(Request $request, IntegrationRequest $integrationRequest): JsonResponse
    {
        $user = $request->user();

        // Only votes cast this month can be undone, so the refund maps back to
        // the current quota while earlier months' tallies stay locked in.
        $vote = $integrationRequest->votes()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->latest()
            ->first();

        $vote?->delete();

        return $this->payload($user);
    }

    /**
     * The board state shared by the page, the drawer and every mutation.
     *
     * @return Collection<int, IntegrationRequest>
     */
    private function list(User $user): Collection
    {
        return IntegrationRequest::query()
            ->where(function ($query) use ($user) {
                $query->whereIn('status', [IntegrationRequestStatus::Approved, IntegrationRequestStatus::InProgress, IntegrationRequestStatus::NotDoable])
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('status', IntegrationRequestStatus::Pending)
                            ->where('user_id', $user->id);
                    });
            })
            ->withCount('votes')
            ->withExists([
                'votes as has_voted' => fn ($query) => $query->where('user_id', $user->id),
                'votes as can_unvote' => fn ($query) => $query->where('user_id', $user->id)
                    ->where('created_at', '>=', now()->startOfMonth()),
            ])
            // Not-doable requests sink to the bottom regardless of their votes.
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [IntegrationRequestStatus::NotDoable->value])
            ->orderByDesc('votes_count')
            ->orderByDesc('created_at')
            ->get();
    }

    private function monthlyActionLimit(User $user): int
    {
        return $user->hasProPlan()
            ? self::PRO_MONTHLY_ACTION_LIMIT
            : self::FREE_MONTHLY_ACTION_LIMIT;
    }

    private function actionsRemaining(User $user): int
    {
        $limit = $this->monthlyActionLimit($user);

        // The admin has no monthly cap; report a full quota so neither the
        // backend checks nor the frontend buttons ever gate them.
        if ($user->isAdmin()) {
            return $limit;
        }

        $start = now()->startOfMonth();

        $used = $user->integrationRequests()->where('created_at', '>=', $start)->count()
            + $user->integrationRequestVotes()->where('created_at', '>=', $start)->count();

        return max(0, $limit - $used);
    }

    private function payload(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'requests' => $this->list($user),
            'actionsRemaining' => $this->actionsRemaining($user),
        ], $status);
    }

    private function limitReachedResponse(User $user): JsonResponse
    {
        return response()->json([
            'message' => __('You have reached your monthly limit of :count integration actions. Try again next month.', ['count' => $this->monthlyActionLimit($user)]),
        ], 422);
    }
}
