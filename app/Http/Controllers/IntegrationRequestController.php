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
    private const MONTHLY_ACTION_LIMIT = 3;

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
            return $this->limitReachedResponse();
        }

        $integrationRequest = $user->integrationRequests()->create($request->only(['name', 'url']));
        $integrationRequest->votes()->create(['user_id' => $user->id]);

        return $this->payload($user, 201);
    }

    public function vote(Request $request, IntegrationRequest $integrationRequest): JsonResponse
    {
        $user = $request->user();

        if ($integrationRequest->status !== IntegrationRequestStatus::Approved
            && $integrationRequest->user_id !== $user->id) {
            abort(404);
        }

        $vote = $integrationRequest->votes()->where('user_id', $user->id)->first();

        if ($vote !== null) {
            $vote->delete();

            return $this->payload($user);
        }

        if ($this->actionsRemaining($user) <= 0) {
            return $this->limitReachedResponse();
        }

        $integrationRequest->votes()->create(['user_id' => $user->id]);

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
                $query->where('status', IntegrationRequestStatus::Approved)
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('status', IntegrationRequestStatus::Pending)
                            ->where('user_id', $user->id);
                    });
            })
            ->withCount('votes')
            ->withExists(['votes as has_voted' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderByDesc('votes_count')
            ->orderByDesc('created_at')
            ->get();
    }

    private function actionsRemaining(User $user): int
    {
        $start = now()->startOfMonth();

        $used = $user->integrationRequests()->where('created_at', '>=', $start)->count()
            + $user->integrationRequestVotes()->where('created_at', '>=', $start)->count();

        return max(0, self::MONTHLY_ACTION_LIMIT - $used);
    }

    private function payload(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'requests' => $this->list($user),
            'actionsRemaining' => $this->actionsRemaining($user),
        ], $status);
    }

    private function limitReachedResponse(): JsonResponse
    {
        return response()->json([
            'message' => __('You have reached your monthly limit of :count integration actions. Try again next month.', ['count' => self::MONTHLY_ACTION_LIMIT]),
        ], 422);
    }
}
