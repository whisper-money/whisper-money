<?php

namespace App\Http\Controllers\Ai;

use App\Enums\PlanFeature;
use App\Enums\RuleSuggestionStatus;
use App\Enums\SuggestionRunStatus;
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
use Illuminate\Support\Collection;

class RuleSuggestionController extends Controller
{
    public function __construct(
        private readonly RuleSuggestionAvailability $availability,
        private readonly TransactionMatcher $matcher,
    ) {}

    /**
     * Return the current suggestion state (used for polling + review).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->state($user));
    }

    /**
     * Kick off a generation run, reusing the latest run while throttled.
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
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
     * Live preview of the transactions a group of candidate conditions would
     * match (OR'd together), recomputed whenever the user edits a value.
     */
    public function preview(PreviewRuleSuggestionRequest $request): JsonResponse
    {
        $user = $request->user();

        $conditions = $this->conditions($request->validated('conditions'));

        $matches = $this->matcher->matchingAny($user, $conditions, 100);

        return response()->json([
            'match_count' => $this->matcher->countMatchingAny($user, $conditions),
            'total_uncategorized' => $this->matcher->total($user),
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
     * Accept (and optionally tweak) suggestion groups: create one OR rule per
     * group and, during onboarding, apply them to existing uncategorized
     * transactions right away.
     */
    public function accept(AcceptRuleSuggestionsRequest $request, ApplyRuleSuggestions $applier): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasActiveAiConsent(), 403);

        $run = $this->availability->latestSuccessfulRun($user);
        abort_if($run === null, 404);

        $groups = collect($request->validated('suggestions'));

        $referencedIds = $groups->flatMap(fn (array $group): array => $group['ids'])->unique()->all();

        /** @var Collection<string, RuleSuggestion> $pending */
        $pending = $run->suggestions()
            ->whereIn('id', $referencedIds)
            ->where('status', RuleSuggestionStatus::Pending)
            ->get()
            ->keyBy('id');

        $descriptors = $groups
            ->map(function (array $group) use ($pending): ?array {
                $rows = collect($group['ids'])
                    ->map(fn (string $id): ?RuleSuggestion => $pending->get($id))
                    ->filter();

                if ($rows->isEmpty()) {
                    return null;
                }

                $existingCategory = $group['proposed_category_id'] ?? null;

                return [
                    'conditions' => collect($group['values'])->map(fn (array $value): array => [
                        'field' => (string) $value['match_field'],
                        'operator' => (string) $value['match_operator'],
                        'token' => mb_strtolower(trim((string) $value['match_token'])),
                    ])->all(),
                    'proposed_category_id' => $existingCategory,
                    'new_category_name' => $existingCategory ? null : ($group['new_category_name'] ?? null),
                    'new_category_direction' => $existingCategory ? null : ($group['new_category_direction'] ?? null),
                    'confidence' => (float) $rows->max('confidence'),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $applyToExisting = ! $user->isOnboarded();

        $summary = $applier->apply($user, $descriptors, $applyToExisting);

        $run->suggestions()
            ->whereIn('id', $pending->keys()->all())
            ->update(['status' => RuleSuggestionStatus::Accepted]);

        $run->suggestions()
            ->where('status', RuleSuggestionStatus::Pending)
            ->update(['status' => RuleSuggestionStatus::Dismissed]);

        return response()->json([
            'summary' => $summary,
            'applied_to_existing' => $applyToExisting,
        ]);
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
            'requires_upgrade' => $this->requiresUpgrade($user),
            'eligible' => $this->availability->isEligible($user),
            'transaction_count' => $this->availability->transactionCount($user),
            'min_transactions' => $this->availability->minTransactions(),
            'auto_select_confidence' => (float) config('ai_suggestions.auto_select_confidence'),
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
     * Whether enabling AI suggestions would force the user onto a paid plan.
     *
     * Connected accounts already require a paid plan, so a user who has linked
     * a bank gains AI suggestions at no extra cost — we only warn the rest.
     */
    private function requiresUpgrade(User $user): bool
    {
        if ($user->canUseFeature(PlanFeature::AiSuggestions)) {
            return false;
        }

        return ! $user->bankingConnections()->exists();
    }

    /**
     * Pending suggestions, grouped by their target category into one card each:
     * the matched values are OR'd, so merchants heading to the same category are
     * reviewed (and later created) as a single rule.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeSuggestions(SuggestionRun $run): array
    {
        $user = $run->user;
        $minMatchCount = (int) config('ai_suggestions.min_match_count');

        return $run->suggestions()
            ->with('proposedCategory')
            ->where('status', RuleSuggestionStatus::Pending)
            ->orderByDesc('confidence')
            ->orderByDesc('group_size')
            ->get()
            ->groupBy(fn (RuleSuggestion $suggestion): string => $suggestion->categoryGroupKey())
            ->map(function ($items, string $key) use ($user): array {
                /** @var Collection<int, RuleSuggestion> $items */
                $first = $items->first();

                $values = $items->map(fn (RuleSuggestion $suggestion): array => [
                    'id' => $suggestion->id,
                    'match_field' => $suggestion->match_field,
                    'match_operator' => $suggestion->match_operator,
                    'match_token' => $suggestion->match_token,
                    'group_size' => $suggestion->group_size,
                ])->values()->all();

                return [
                    'id' => $key,
                    'confidence' => (float) $items->max('confidence'),
                    'group_size' => $this->matcher->countMatchingAny($user, $this->conditions($values)),
                    'sample_descriptions' => $items->flatMap(fn (RuleSuggestion $suggestion): array => $suggestion->sample_descriptions ?? [])->unique()->take(3)->values()->all(),
                    'proposed_category' => $first->proposedCategory === null ? null : [
                        'id' => $first->proposedCategory->id,
                        'name' => $first->proposedCategory->name,
                    ],
                    'new_category_name' => $first->new_category_name,
                    'new_category_direction' => $first->new_category_direction,
                    'values' => $values,
                ];
            })
            ->filter(fn (array $suggestion): bool => $suggestion['group_size'] >= $minMatchCount)
            ->sortByDesc('group_size')
            ->values()
            ->all();
    }

    /**
     * Normalize match-value arrays (as sent by the client or stored on a row)
     * into the matcher's condition shape.
     *
     * @param  list<array<string, mixed>>  $values
     * @return list<array{field: string, operator: string, token: string}>
     */
    private function conditions(array $values): array
    {
        return array_map(fn (array $value): array => [
            'field' => (string) $value['match_field'],
            'operator' => (string) $value['match_operator'],
            'token' => (string) $value['match_token'],
        ], $values);
    }
}
