<?php

namespace App\Console\Commands;

use App\Services\Transactions\LegacySplits\LegacySplitConversion;
use App\Services\Transactions\LegacySplits\LegacySplitConverter;
use App\Services\Transactions\LegacySplits\LegacySplitReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run once after the deploy that moves splits to upstream's model. Prints
 * counts, ids and the verdict only: amounts and the differences' values need
 * -v, since its output gets pasted where personal finances should not go.
 *
 * @phpstan-import-type Difference from \App\Services\Transactions\LegacySplits\LegacySplitVerifier
 */
class ConvertLegacySplitsCommand extends Command
{
    protected $signature = 'transactions:convert-legacy-splits
        {--execute : Write the changes; without it nothing is saved}
        {--undo : Put converted splits back on their lines instead, but for those whose parts changed since}
        {--user= : Only the user with this id or email address}';

    protected $description = 'Convert transactions still split into lines (transaction_splits) into split parts, as a split is made today: one part per line, the original soft-deleted, budget rows moved so every period keeps its total. Splits transactions:audit-splits flags with an anomaly their parts would carry over (lines that do not add up, a zero line or one moving money the other way, fewer than two lines, a category missing or of another user or space, lines of mixed category types, a category left on the transaction itself) are skipped. Previews by default. The run is verified before and after (category, label, budget period, monthly cashflow and per-day account totals, and each split against its lines) and rolled back when anything differs beyond FX rounding. One database transaction: run it once after deploy, while nobody is writing.';

    public function handle(LegacySplitConversion $conversion): int
    {
        $userId = null;
        $userOption = $this->option('user');

        if (is_string($userOption) && $userOption !== '') {
            $userId = LegacySplitConverter::resolveUserId($userOption);

            if ($userId === null) {
                $this->error('No user has that id or email address.');

                return self::FAILURE;
            }
        }

        try {
            $report = $conversion->run($userId, (bool) $this->option('undo'), (bool) $this->option('execute'));
        } catch (Throwable $exception) {
            $this->error('Stopped by '.$exception::class.'; nothing was written.'.($this->output->isVerbose() ? ' '.$exception->getMessage() : ''));

            return self::FAILURE;
        }

        $this->printPlan($report);
        $this->printVerification($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function printPlan(LegacySplitReport $report): void
    {
        $this->line(match (true) {
            $report->written => 'Executed: the changes are saved.',
            (bool) $this->option('execute') => 'Nothing was written.',
            default => 'Preview: nothing was written. Run with --execute to write.',
        });

        if ($report->undo) {
            $this->printUndoPlan($report);
        } else {
            $this->printConversionPlan($report);
        }

        $this->line($report->undo
            ? "Budget rows moved back from the parts: {$report->budgetRowsMoved}"
            : "Budget rows moved to the parts: {$report->budgetRowsMoved} (shared by part amount: ".count($report->fallback).')');

        if ($this->output->isVerbose()) {
            $this->printIds($report);
        }
    }

    private function printConversionPlan(LegacySplitReport $report): void
    {
        $plan = $report->plan;

        $this->line('Legacy splits to convert: '.$plan->originals->count());
        $this->line('Already converted: '.$plan->alreadyConverted);
        $this->line('Skipped: '.collect($plan->skipped)->flatten()->count());

        $reasons = array_keys($plan->skipped);
        sort($reasons);
        $reasons = array_unique([...LegacySplitConverter::FIXED_SKIP_REASONS, ...$reasons]);

        foreach ($reasons as $reason) {
            $this->line("  {$reason}: ".count($plan->skipped[$reason] ?? []));
        }

        $this->line('Parts created: '.$report->parts);
    }

    /**
     * The ids of the splits left on their parts are printed without -v: the
     * owner has to look at each one.
     */
    private function printUndoPlan(LegacySplitReport $report): void
    {
        $changed = $report->plan->skipped[LegacySplitConverter::SKIP_CHANGED] ?? [];

        $this->line('Converted splits to put back on their lines: '.$report->plan->originals->count());
        $this->line('Parts removed: '.$report->parts);
        $this->line('Skipped, '.LegacySplitConverter::SKIP_CHANGED.': '.count($changed));

        foreach ($changed as $id) {
            $this->line("  skipped: {$id}");
        }
    }

    private function printIds(LegacySplitReport $report): void
    {
        $verb = $report->undo ? 'put back' : 'converted';

        $report->plan->originals->each(fn ($original) => $this->line("  {$verb}: {$original->id}"));
        collect($report->plan->skipped)
            ->except($report->undo ? [LegacySplitConverter::SKIP_CHANGED] : [])
            ->each(fn (array $ids, string $reason) => collect($ids)->each(fn (string $id) => $this->line("  skipped ({$reason}): {$id}")));
        collect($report->fallback)->each(fn (array $row) => $this->line("  shared by part amount: transaction {$row['transaction_id']}, budget period {$row['budget_period_id']}"));
    }

    private function printVerification(LegacySplitReport $report): void
    {
        if ($report->plan->originals->isEmpty()) {
            $this->info('Nothing to do.');

            return;
        }

        foreach ($report->fxRounding as $difference) {
            $this->line('FX rounding (accepted): '.$this->describe($difference));
        }

        if ($report->passed()) {
            $this->info('Verification passed: every figure is unchanged'.($report->fxRounding === [] ? '.' : ', but for the FX rounding above.'));

            return;
        }

        $this->error('Verification failed: '.count($report->differences).' figure(s) changed, '.count($report->originalFailures).' split(s) do not match. Everything was rolled back.');

        foreach ($report->differences as $difference) {
            $this->line('  changed: '.$this->describe($difference));
        }

        foreach ($report->originalFailures as $id => $failure) {
            $this->line("  {$id}: {$failure}");
        }
    }

    /**
     * @param  Difference  $difference
     */
    private function describe(array $difference): string
    {
        $values = $this->output->isVerbose()
            ? ' (before '.($difference['before'] ?? 'none').', after '.($difference['after'] ?? 'none').')'
            : '';

        return "{$difference['family']} {$difference['bucket']}{$values}";
    }
}
