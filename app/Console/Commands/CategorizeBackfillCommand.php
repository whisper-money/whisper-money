<?php

namespace App\Console\Commands;

use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class CategorizeBackfillCommand extends Command
{
    protected $signature = 'ai:categorize-backfill {user : User id or email}';

    protected $description = 'Categorize a user\'s existing uncategorized transactions with AI (explicit, opt-in backfill)';

    public function handle(AiCategorizationGate $gate, AiCategorizer $categorizer): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        if (! $gate->allowsBackfill($user)) {
            $this->warn('User is not eligible (needs the feature enabled, a pro plan and active AI consent).');

            return self::FAILURE;
        }

        $pendingIds = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereNull('description_iv')
            ->pluck('id');

        if ($pendingIds->isEmpty()) {
            $this->info('No uncategorized transactions to backfill.');

            return self::SUCCESS;
        }

        $rulesBefore = $this->aiRuleCount($user);
        $applied = 0;
        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));

        // Chunk a fixed snapshot of ids so transactions left blank (below the
        // confidence bar) are never re-processed on a later iteration.
        $this->withProgressBar(
            $pendingIds->chunk($batchSize),
            function ($chunkIds) use ($user, $categorizer, &$applied): void {
                $chunk = Transaction::query()->whereIn('id', $chunkIds->all())->get();

                $outcomes = $categorizer->run($user, new Collection($chunk->all()));
                $applied += count(array_filter($outcomes, fn ($outcome): bool => $outcome->applied));
            },
        );

        $this->newLine(2);
        $this->components->info(sprintf(
            'Backfill complete: %d transaction(s) categorized, %d AI rule(s) learned.',
            $applied,
            $this->aiRuleCount($user) - $rulesBefore,
        ));

        return self::SUCCESS;
    }

    private function aiRuleCount(User $user): int
    {
        return AutomationRule::query()
            ->where('user_id', $user->id)
            ->origin(RuleOrigin::Ai)
            ->count();
    }

    private function resolveUser(string $identifier): ?User
    {
        return User::query()->where('email', $identifier)->first()
            ?? User::query()->find($identifier);
    }
}
