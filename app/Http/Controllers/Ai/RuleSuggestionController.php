<?php

namespace App\Http\Controllers\Ai;

use App\Enums\RuleSuggestionStatus;
use App\Enums\SuggestionRunStatus;
use App\Features\AiRuleSuggestions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AcceptRuleSuggestionsRequest;
use App\Http\Requests\Ai\PreviewRuleSuggestionRequest;
use App\Jobs\GenerateRuleSuggestionsJob;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\ApplyRuleSuggestions;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\RuleSuggestionAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class RuleSuggestionController extends Controller
{
    public function __construct(private readonly RuleSuggestionAvailability $availability) {}

    /**
     * Return the current suggestion state (used for polling + review).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureFeature($user);

        return response()->json($this->state($user));
    }

    /**
     * Kick off a generation run, reusing the latest run while throttled.
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureFeature($user);
        abort_unless($user->hasActiveAiConsent(), 403);

        if (! $this->availability->isEligible($user)) {
            return response()->json($this->state($user), 422);
        }

        if (! $this->availability->isThrottled($user)) {
            $run = $user->suggestionRuns()->create(['status' => SuggestionRunStatus::Pending]);
            GenerateRuleSuggestionsJob::dispatch($run);
        }

        return response()->json($this->state($user->refresh()));
    }

    /**
     * Live preview of the transactions a candidate token would match.
     */
    public function preview(PreviewRuleSuggestionRequest $request, TransactionMatcher $matcher): JsonResponse
    {
        $user = $request->user();
        $this->ensureFeature($user);

        $field = (string) $request->validated('match_field');
        $operator = (string) $request->validated('match_operator');
        $token = (string) $request->validated('match_token');

        $matches = $matcher->matching($user, $field, $operator, $token, 100);

        return response()->json([
            'match_count' => $matcher->countMatching($user, $field, $operator, $token),
            'total_uncategorized' => $matcher->total($user),
            'transactions' => $matches->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'description' => $transaction->description,
                'creditor_name' => $transaction->creditor_name,
                'debtor_name' => $transaction->debtor_name,
                'amount' => (int) $transaction->amount,
                'currency_code' => $transaction->currency_code,
                'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
            ])->all(),
        ]);
    }

    /**
     * Accept (and optionally tweak) suggestions: create the rules and, during
     * onboarding, apply them to existing uncategorized transactions right away.
     */
    public function accept(AcceptRuleSuggestionsRequest $request, ApplyRuleSuggestions $applier): JsonResponse
    {
        $user = $request->user();
        $this->ensureFeature($user);
        abort_unless($user->hasActiveAiConsent(), 403);

        $run = $this->availability->latestSuccessfulRun($user);
        abort_if($run === null, 404);

        $payload = collect($request->validated('suggestions'));

        $pending = $run->suggestions()
            ->whereIn('id', $payload->pluck('id'))
            ->where('status', RuleSuggestionStatus::Pending)
            ->get()
            ->keyBy('id');

        $accepted = $payload
            ->filter(fn (array $data): bool => $pending->has($data['id']))
            ->map(function (array $data) use ($pending): RuleSuggestion {
                $suggestion = $pending->get($data['id']);
                $existingCategory = $data['proposed_category_id'] ?? null;

                $suggestion->forceFill([
                    'match_field' => $data['match_field'],
                    'match_operator' => $data['match_operator'],
                    'match_token' => mb_strtolower(trim((string) $data['match_token'])),
                    'proposed_category_id' => $existingCategory,
                    'new_category_name' => $existingCategory ? null : ($data['new_category_name'] ?? null),
                    'new_category_direction' => $existingCategory ? null : ($data['new_category_direction'] ?? null),
                ])->save();

                return $suggestion;
            })
            ->values();

        $applyToExisting = ! $user->isOnboarded();

        $summary = $applier->apply($user, $accepted, $applyToExisting);

        $run->suggestions()
            ->where('status', RuleSuggestionStatus::Pending)
            ->update(['status' => RuleSuggestionStatus::Dismissed]);

        return response()->json([
            'summary' => $summary,
            'applied_to_existing' => $applyToExisting,
        ]);
    }

    private function ensureFeature(User $user): void
    {
        abort_unless(Feature::for($user)->active(AiRuleSuggestions::class), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(User $user): array
    {
        $run = $this->availability->latestRun($user);
        $throttledUntil = $this->availability->throttledUntil($user);

        return [
            'available' => true,
            'consented' => $user->hasActiveAiConsent(),
            'eligible' => $this->availability->isEligible($user),
            'transaction_count' => $this->availability->transactionCount($user),
            'min_transactions' => $this->availability->minTransactions(),
            'throttled' => $throttledUntil !== null,
            'throttled_until' => $throttledUntil?->toIso8601String(),
            'run' => $run === null ? null : [
                'id' => $run->id,
                'status' => $run->status->value,
                'suggestions_count' => $run->suggestions_count,
            ],
            'suggestions' => $run !== null && $run->status === SuggestionRunStatus::Completed
                ? $this->serializeSuggestions($run)
                : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeSuggestions(SuggestionRun $run): array
    {
        return $run->suggestions()
            ->with('proposedCategory')
            ->where('status', RuleSuggestionStatus::Pending)
            ->orderByDesc('confidence')
            ->orderByDesc('group_size')
            ->get()
            ->map(fn (RuleSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'match_field' => $suggestion->match_field,
                'match_operator' => $suggestion->match_operator,
                'match_token' => $suggestion->match_token,
                'confidence' => (float) $suggestion->confidence,
                'group_size' => $suggestion->group_size,
                'sample_descriptions' => $suggestion->sample_descriptions ?? [],
                'proposed_category' => $suggestion->proposedCategory === null ? null : [
                    'id' => $suggestion->proposedCategory->id,
                    'name' => $suggestion->proposedCategory->name,
                ],
                'new_category_name' => $suggestion->new_category_name,
                'new_category_direction' => $suggestion->new_category_direction,
            ])
            ->all();
    }
}
