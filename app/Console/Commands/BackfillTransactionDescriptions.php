<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\Formatters\RemittanceTagFormatter;
use App\Services\Banking\TransactionDescriptionFormatter;
use Illuminate\Console\Command;

class BackfillTransactionDescriptions extends Command
{
    protected $signature = 'banking:backfill-descriptions
        {--user= : Filter by user email address}
        {--dry-run : Preview what would be updated without making changes}';

    protected $description = 'Re-apply the bank description formatters to already-imported transactions that still carry a raw remittance tag, and rewrite the automation rules matching on it';

    public function handle(TransactionDescriptionFormatter $formatter): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $userEmail = $this->option('user');
        $userId = null;

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        if ($userEmail) {
            $user = User::query()->where('email', $userEmail)->first();

            if (! $user) {
                $this->error("User with email '{$userEmail}' not found.");

                return self::FAILURE;
            }

            $userId = $user->id;
        }

        $transactions = $this->reformatTransactions($formatter, $userId, $isDryRun);

        // User rules were written against the raw text ("description contains
        // /TXT/D|MERCADONA"), so rewriting descriptions without rewriting the
        // rules would silently stop them from ever matching again.
        $rules = $this->reformatRules($formatter, $userId, $isDryRun);

        $verb = $isDryRun ? 'would be reformatted' : 'reformatted';
        $this->info("{$transactions} transaction(s) and {$rules} automation rule(s) {$verb}.");

        return self::SUCCESS;
    }

    private function reformatTransactions(TransactionDescriptionFormatter $formatter, ?string $userId, bool $isDryRun): int
    {
        $query = Transaction::query()
            ->with('account.bank')
            ->whereNull('description_iv')
            ->where('description', 'like', RemittanceTagFormatter::TAG.'%')
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $reformatted = 0;

        // chunkById: the update takes rows out of the result set, so a
        // page-offset chunk() would skip half of them.
        $query->chunkById(500, function ($transactions) use ($formatter, $isDryRun, &$reformatted): void {
            foreach ($transactions as $transaction) {
                $formatted = $formatter->format($transaction->description, $transaction->account?->bank?->name);

                if ($formatted['description'] === $transaction->description) {
                    continue;
                }

                if ($this->output->isVerbose()) {
                    $this->line("  {$transaction->description}  →  {$formatted['description']}");
                }

                if (! $isDryRun) {
                    // Quietly: the only listener on TransactionUpdated assigns
                    // budgets from the category, labels, amount and date, none
                    // of which this touches.
                    $transaction->updateQuietly([
                        'description' => $formatted['description'],
                        'original_description' => $transaction->original_description ?? $formatted['original_description'],
                    ]);
                }

                $reformatted++;
            }
        });

        return $reformatted;
    }

    private function reformatRules(TransactionDescriptionFormatter $formatter, ?string $userId, bool $isDryRun): int
    {
        $reformatted = 0;

        // No `like '%/TXT/%'` prefilter: whether the tag survives in the stored
        // JSON with its slashes escaped depends on how the rule was written, so
        // the formatter — not a fragile SQL pattern — decides what is tagged.
        AutomationRule::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->chunkById(500, function ($rules) use ($formatter, $isDryRun, &$reformatted): void {
                foreach ($rules as $rule) {
                    // The column holds the rule either as an array or as a JSON
                    // string; whichever it was has to survive the rewrite.
                    $stored = $rule->rules_json;
                    $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $rewritten = $this->rewriteTaggedValues($decoded, $formatter);

                    if ($rewritten === $decoded) {
                        continue;
                    }

                    if ($this->output->isVerbose()) {
                        $this->line("  rule {$rule->id}: ".json_encode($rewritten));
                    }

                    if (! $isDryRun) {
                        $rule->rules_json = is_string($stored) ? json_encode($rewritten) : $rewritten;
                        $rule->save();
                    }

                    $reformatted++;
                }
            });

        return $reformatted;
    }

    /**
     * Run every string literal in a rule tree through the formatter, so the
     * text a rule matches on is normalized the same way descriptions now are.
     * Untagged literals — operators, variable names, plain merchant text —
     * come back unchanged.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function rewriteTaggedValues(array $node, TransactionDescriptionFormatter $formatter): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->rewriteTaggedValues($value, $formatter);

                continue;
            }

            if (is_string($value)) {
                $node[$key] = $formatter->format($value, null)['description'];
            }
        }

        return $node;
    }
}
