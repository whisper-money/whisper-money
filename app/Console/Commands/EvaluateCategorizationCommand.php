<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\Evaluation\BackendRun;
use App\Services\Ai\Evaluation\CategorizationEvaluator;
use App\Services\Ai\Evaluation\EvaluationGroup;
use App\Services\Ai\GeminiCategorizationBackend;
use App\Services\Ai\JevCategorizationBackend;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Compares the categorization backends on the same hand-categorized sample:
 * accuracy, calibration, latency and cost. Meant for a local copy of prod;
 * nothing is written to the database.
 */
class EvaluateCategorizationCommand extends Command
{
    protected $signature = 'ai:categorization-eval
        {--backend=* : Backends to evaluate: gemini, jev (default: both)}
        {--sample=300 : Transactions to evaluate}
        {--per-user=20 : Most transactions taken from a single user}
        {--seed= : Seed for a reproducible sample}
        {--user= : Only sample this user (id or email)}
        {--gemini-model= : Gemini model to use instead of ai_categorization.model}';

    protected $description = 'Evaluate the AI categorization backends against hand-categorized transactions';

    private const array BACKENDS = [
        'gemini' => GeminiCategorizationBackend::class,
        'jev' => JevCategorizationBackend::class,
    ];

    /**
     * Confidence ranges for the calibration table, as [from, to).
     */
    private const array BUCKETS = [[0.0, 0.5], [0.5, 0.7], [0.7, 0.85], [0.85, 0.95], [0.95, 1.01]];

    public function handle(CategorizationEvaluator $evaluator): int
    {
        if ($this->option('gemini-model')) {
            config(['ai_categorization.model' => (string) $this->option('gemini-model')]);
        }

        $backends = $this->backends();

        if ($backends === null) {
            return self::FAILURE;
        }

        $groups = $this->sample($evaluator);

        if ($groups === null) {
            return self::FAILURE;
        }

        $runs = [];

        foreach ($backends as $name) {
            $this->components->info("Running {$name}…");
            $runs[$name] = $this->runBackend($evaluator, $name, $groups);
        }

        $expected = $groups->flatMap(fn (EvaluationGroup $group): Collection => $group->transactions)
            ->mapWithKeys(fn ($transaction): array => [$transaction->id => $transaction->category_id])
            ->all();

        $this->renderSummary($runs, $expected);
        $this->renderCalibration($runs, $expected);
        $this->renderHeadToHead($runs, $expected);
        $this->renderErrors($runs);
        $this->components->info('Per-transaction results: '.$this->writeCsv($runs, $groups));

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function backends(): ?array
    {
        $backends = $this->option('backend') ?: array_keys(self::BACKENDS);
        $unknown = array_diff($backends, array_keys(self::BACKENDS));

        if ($unknown !== []) {
            $this->error('Unknown backend: '.implode(', ', $unknown).'. Use gemini or jev.');

            return null;
        }

        if (in_array('jev', $backends, true) && ! config('services.typesafe.enabled')) {
            $this->error('Jev needs TYPESAFE_API_KEY.');

            return null;
        }

        return array_values($backends);
    }

    /**
     * @return Collection<int, EvaluationGroup>|null
     */
    private function sample(CategorizationEvaluator $evaluator): ?Collection
    {
        $userId = null;

        if ($this->option('user')) {
            $identifier = (string) $this->option('user');
            $userId = User::query()->where('email', $identifier)->value('id') ?? User::query()->whereKey($identifier)->value('id');

            if ($userId === null) {
                $this->error('User not found.');

                return null;
            }
        }

        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : random_int(1, PHP_INT_MAX);
        $groups = $evaluator->sample((int) $this->option('sample'), max(1, (int) $this->option('per-user')), $seed, $userId);
        $size = $groups->sum(fn (EvaluationGroup $group): int => $group->transactions->count());

        if ($size === 0) {
            $this->warn('No hand-categorized transactions from users with an active AI consent.');

            return null;
        }

        $this->components->twoColumnDetail('Sample', "{$size} transactions from {$groups->count()} users (seed {$seed})");

        return $this->confirm('Send them to the selected providers?', true) ? $groups : null;
    }

    /**
     * @param  Collection<int, EvaluationGroup>  $groups
     */
    private function runBackend(CategorizationEvaluator $evaluator, string $name, Collection $groups): BackendRun
    {
        $bar = $this->output->createProgressBar($groups->sum(fn (EvaluationGroup $group): int => $group->transactions->count()));
        $started = microtime(true);

        $run = $evaluator->run($name, app(self::BACKENDS[$name]), $groups, fn (int $count) => $bar->advance($count));

        $bar->finish();
        $this->newLine();
        $this->components->twoColumnDetail('Wall time', sprintf('%.1fs', microtime(true) - $started));

        return $run;
    }

    /**
     * @param  array<string, BackendRun>  $runs
     * @param  array<string, string>  $expected
     */
    private function renderSummary(array $runs, array $expected): void
    {
        $labelBar = (float) config('ai_categorization.label_confidence');
        $ruleBar = (float) config('ai_categorization.rule_confidence');
        $rows = [];

        foreach ($runs as $run) {
            $answered = $run->sent() - count($run->failed);
            $all = $run->tally($expected);
            $applied = $run->tally($expected, fn (array $prediction): bool => $prediction['confidence'] >= $labelBar);
            $rules = $run->tally($expected, fn (array $prediction): bool => $prediction['merchant_unambiguous'] && $prediction['confidence'] >= $ruleBar);
            $cost = $run->cost();

            $rows[$run->backend] = [
                'Model' => $run->model,
                'Sent / failed' => $run->sent().' / '.count($run->failed),
                'Accuracy (all answered calls)' => $this->rate($all['correct'], $answered),
                'Coverage (gave a category)' => $this->rate($all['count'], $answered),
                'Precision when it gives one' => $this->rate($all['correct'], $all['count']),
                "Auto-applied (≥ {$labelBar})" => $this->rate($applied['count'], $answered),
                'Precision of auto-applied' => $this->rate($applied['correct'], $applied['count']),
                "Rule candidates (≥ {$ruleBar}, unambiguous)" => $this->rate($rules['count'], $answered),
                'Precision of rule candidates' => $this->rate($rules['correct'], $rules['count']),
                'Latency per call p50 / p95' => sprintf('%.2fs / %.2fs', $run->latency(0.5), $run->latency(0.95)),
                'Retries' => (string) $run->retries,
                'Tokens in / out' => number_format($run->inputTokens).' / '.number_format($run->outputTokens),
                'Cost of this run' => $cost === null ? 'unknown (no price for model)' : sprintf('$%.4f', $cost),
                'Cost per 1,000 transactions' => $cost === null || $run->sent() === 0 ? '—' : sprintf('$%.4f', $cost / $run->sent() * 1000),
            ];
        }

        $this->table(
            ['', ...array_keys($rows)],
            collect(array_keys(reset($rows)))->map(fn (string $metric): array => [$metric, ...array_column($rows, $metric)])->all(),
        );
    }

    /**
     * Precision per confidence range: a well-calibrated model is right ~90% of
     * the time when it says 0.9, which is what makes the label bar meaningful.
     *
     * @param  array<string, BackendRun>  $runs
     * @param  array<string, string>  $expected
     */
    private function renderCalibration(array $runs, array $expected): void
    {
        $rows = [];

        foreach (self::BUCKETS as [$from, $to]) {
            $row = [sprintf('%.2f – %.2f', $from, min($to, 1.0))];

            foreach ($runs as $run) {
                $bucket = $run->tally($expected, fn (array $prediction): bool => $prediction['confidence'] >= $from && $prediction['confidence'] < $to);
                $row[] = $bucket['count'].' · '.$this->rate($bucket['correct'], $bucket['count']);
            }

            $rows[] = $row;
        }

        $this->table(['Confidence', ...array_map(fn (string $name): string => "{$name} (n · precision)", array_keys($runs))], $rows);
    }

    /**
     * @param  array<string, BackendRun>  $runs
     * @param  array<string, string>  $expected
     */
    private function renderHeadToHead(array $runs, array $expected): void
    {
        if (count($runs) !== 2) {
            return;
        }

        [$first, $second] = array_values($runs);
        $outcomes = ['Both right' => 0, "Only {$first->backend} right" => 0, "Only {$second->backend} right" => 0, 'Both wrong or empty' => 0];

        foreach ($expected as $ref => $categoryId) {
            if (! isset($first->predictions[$ref], $second->predictions[$ref])) {
                continue;
            }

            $firstRight = $first->predictions[$ref]['category_id'] === $categoryId;
            $secondRight = $second->predictions[$ref]['category_id'] === $categoryId;
            $key = match (true) {
                $firstRight && $secondRight => 'Both right',
                $firstRight => "Only {$first->backend} right",
                $secondRight => "Only {$second->backend} right",
                default => 'Both wrong or empty',
            };
            $outcomes[$key]++;
        }

        $this->table(['Head to head', 'Transactions'], collect($outcomes)->map(fn (int $count, string $outcome): array => [$outcome, $count])->values()->all());
    }

    /**
     * @param  array<string, BackendRun>  $runs
     */
    private function renderErrors(array $runs): void
    {
        foreach ($runs as $run) {
            foreach (array_count_values($run->errors) as $error => $times) {
                $this->warn("{$run->backend}: {$times}× {$error}");
            }
        }
    }

    /**
     * One row per transaction with every backend's answer side by side, for
     * reading the misses. It holds user data, so it stays in local storage.
     *
     * @param  array<string, BackendRun>  $runs
     * @param  Collection<int, EvaluationGroup>  $groups
     */
    private function writeCsv(array $runs, Collection $groups): string
    {
        $path = storage_path('app/private/ai-eval/'.now()->format('Ymd-His').'.csv');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'w');

        $header = ['user_id', 'transaction_id', 'description', 'amount', 'currency', 'expected'];

        foreach (array_keys($runs) as $name) {
            array_push($header, "{$name}_category", "{$name}_confidence", "{$name}_unambiguous", "{$name}_correct");
        }

        fputcsv($handle, $header);

        foreach ($groups as $group) {
            foreach ($group->transactions as $transaction) {
                $row = [$group->user->id, $transaction->id, $transaction->description, $transaction->amount, $transaction->currency_code, $group->catalog->pathForCategoryId($transaction->category_id)];

                foreach ($runs as $run) {
                    array_push($row, ...$this->csvPrediction($run, $group, $transaction->id, $transaction->category_id));
                }

                fputcsv($handle, $row);
            }
        }

        fclose($handle);

        return $path;
    }

    /**
     * @return list<string>
     */
    private function csvPrediction(BackendRun $run, EvaluationGroup $group, string $ref, string $expectedCategoryId): array
    {
        $prediction = $run->predictions[$ref] ?? null;

        if ($prediction === null) {
            return ['FAILED', '', '', ''];
        }

        return [
            $prediction['category_id'] === null ? '' : (string) $group->catalog->pathForCategoryId($prediction['category_id']),
            sprintf('%.3f', $prediction['confidence']),
            $prediction['merchant_unambiguous'] ? 'yes' : 'no',
            $prediction['category_id'] === $expectedCategoryId ? 'yes' : 'no',
        ];
    }

    private function rate(int $part, int $whole): string
    {
        return $whole === 0 ? '—' : sprintf('%.1f%% (%d/%d)', $part / $whole * 100, $part, $whole);
    }
}
