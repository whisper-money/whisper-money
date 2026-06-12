<?php

namespace App\Console\Commands;

use App\Enums\SuggestionRunStatus;
use App\Models\RuleSuggestion;
use App\Models\User;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\GenerateRuleSuggestions;
use App\Services\Ai\RuleSuggestionAggregator;
use App\Services\Ai\RuleSuggestionAvailability;
use App\Services\Ai\RuleSuggestionGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SuggestRulesCommand extends Command
{
    protected $signature = 'ai:suggest-rules
        {user : User id or email}
        {--persist : Run through the real pipeline and store a SuggestionRun instead of a dry run}';

    protected $description = 'Generate AI rule suggestions for a user and print what each stage produces';

    public function handle(
        RuleSuggestionAggregator $aggregator,
        RuleSuggestionGenerator $generator,
        RuleSuggestionGuard $guard,
        RuleSuggestionAvailability $availability,
    ): int {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $this->line("User: <info>{$user->email}</info> ({$user->id})");
        $this->line(sprintf(
            'Transactions: %d  ·  eligible: %s  ·  throttled: %s',
            $availability->transactionCount($user),
            $availability->isEligible($user) ? 'yes' : 'no',
            $availability->isThrottled($user) ? 'yes' : 'no',
        ));
        $this->newLine();

        if ($this->option('persist')) {
            return $this->runPersisted($user);
        }

        return $this->runDryRun($user, $aggregator, $generator, $guard);
    }

    private function runDryRun(
        User $user,
        RuleSuggestionAggregator $aggregator,
        RuleSuggestionGenerator $generator,
        RuleSuggestionGuard $guard,
    ): int {
        $groups = $aggregator->groupsFor($user);

        if ($groups === []) {
            $this->warn('No transaction groups to suggest from (need uncategorized, server-readable transactions).');

            return self::SUCCESS;
        }

        $this->components->info(count($groups).' transaction group(s) sent to the model');
        $this->table(
            ['Key', 'Field', 'Count', 'Direction', 'Avg', 'Samples'],
            array_map(fn (array $group): array => [
                $group['key'],
                $group['field'],
                $group['count'],
                $group['direction'],
                $group['avg_amount'],
                Str::limit(implode(' | ', $group['samples']), 50),
            ], $groups),
        );

        $categories = $aggregator->categoryOptions($user);

        try {
            $raw = $generator->generate($groups, $categories);
        } catch (Throwable $exception) {
            $this->error('Model call failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(count($raw).' raw suggestion(s) returned by the model');
        $this->table(
            ['Group', 'Field', 'Op', 'Token', 'Category', 'Confidence'],
            array_map(fn (array $suggestion): array => [
                $suggestion['group_key'] ?? '',
                $suggestion['match_field'] ?? '',
                $suggestion['match_operator'] ?? '',
                $suggestion['match_token'] ?? '',
                $this->describeRawCategory($suggestion, $categories),
                $suggestion['confidence'] ?? '',
            ], $raw),
        );

        $validated = $guard->validate($user, $raw, $categories);

        $this->components->info(count($validated).' suggestion(s) survived the guards');
        $this->table(
            ['Field', 'Op', 'Token', 'Category', 'Confidence', 'Matches'],
            array_map(fn (array $suggestion): array => [
                $suggestion['match_field'],
                $suggestion['match_operator'],
                $suggestion['match_token'],
                $this->describeValidatedCategory($suggestion, $categories),
                $suggestion['confidence'],
                $suggestion['group_size'],
            ], $validated),
        );

        $this->newLine();
        $this->line('<comment>Dry run — nothing was saved. Use --persist to store a SuggestionRun.</comment>');

        return self::SUCCESS;
    }

    private function runPersisted(User $user): int
    {
        $run = $user->suggestionRuns()->create(['status' => SuggestionRunStatus::Pending]);

        app(GenerateRuleSuggestions::class)->run($run);

        $run->refresh()->load('suggestions.proposedCategory');

        $this->components->info("Run {$run->id} finished with status: {$run->status->value}");

        if ($run->error) {
            $this->error($run->error);

            return self::FAILURE;
        }

        if ($run->suggestions->isEmpty()) {
            $this->warn('No suggestions were produced.');

            return self::SUCCESS;
        }

        $this->table(
            ['Field', 'Op', 'Token', 'Category', 'Confidence', 'Matches'],
            $run->suggestions->map(fn (RuleSuggestion $suggestion): array => [
                $suggestion->match_field,
                $suggestion->match_operator,
                $suggestion->match_token,
                $suggestion->proposed_category_id !== null
                    ? $suggestion->proposedCategory->name
                    : ($suggestion->new_category_name !== null
                        ? "NEW: {$suggestion->new_category_name}"
                        : '—'),
                $suggestion->confidence,
                $suggestion->group_size,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function resolveUser(string $identifier): ?User
    {
        return User::query()->where('email', $identifier)->first()
            ?? User::query()->find($identifier);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @param  list<array<string, mixed>>  $categories
     */
    private function describeRawCategory(array $suggestion, array $categories): string
    {
        $id = $suggestion['category_id'] ?? null;

        if (filled($id)) {
            foreach ($categories as $category) {
                if ($category['id'] === $id) {
                    return (string) $category['path'];
                }
            }

            return (string) $id;
        }

        $name = $suggestion['new_category_name'] ?? null;

        return filled($name) ? "NEW: {$name}" : '—';
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @param  list<array<string, mixed>>  $categories
     */
    private function describeValidatedCategory(array $suggestion, array $categories): string
    {
        if (filled($suggestion['proposed_category_id'])) {
            foreach ($categories as $category) {
                if ($category['id'] === $suggestion['proposed_category_id']) {
                    return (string) $category['path'];
                }
            }

            return (string) $suggestion['proposed_category_id'];
        }

        return filled($suggestion['new_category_name'])
            ? "NEW: {$suggestion['new_category_name']} ({$suggestion['new_category_direction']})"
            : '—';
    }
}
