<?php

namespace App\Console\Commands;

use App\Support\MethodComplexityVisitor;
use Illuminate\Console\Command;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class CrapCommand extends Command
{
    protected $signature = 'crap
        {--path=app : Directory to analyze}
        {--base= : Only report methods whose lines this branch changed since the given git ref}
        {--report=build/crap4j.xml : crap4j XML produced by `pest --coverage-crap4j`}
        {--no-coverage : Report complexity only, without requiring the coverage report}
        {--ignore=.crap-ignore.json : JSON map of exempted methods to the reason they are exempt}
        {--threshold=10 : Cyclomatic complexity above which a method fails the check}
        {--limit=20 : Methods to list in table output}
        {--json : Machine-readable output}';

    protected $description = 'Report cyclomatic complexity per method, with CRAP and coverage as context';

    public function handle(): int
    {
        $path = base_path($this->option('path'));

        if (! is_dir($path)) {
            $this->components->error("Not a directory: {$path}");

            return static::INVALID;
        }

        $threshold = (int) $this->option('threshold');
        $touched = $this->option('base') ? $this->touchedLines((string) $this->option('base')) : null;

        if ($touched === []) {
            return $this->render([], [], [], $threshold, null, 0);
        }

        $methods = $this->analyze($path, $touched);

        $coverage = $this->option('no-coverage') ? null : $this->coverage($methods);

        if ($coverage === false) {
            return static::INVALID;
        }

        $ignored = $this->ignored();

        $over = array_values(array_filter(
            $methods,
            fn (array $m) => $m['complexity'] > $threshold && ! isset($ignored[$m['method']]),
        ));

        $exempt = array_values(array_filter(
            array_map(
                fn (array $m) => $m + ['reason' => $ignored[$m['method']] ?? null],
                $methods,
            ),
            fn (array $m) => $m['reason'] !== null,
        ));

        return $this->render($over, $exempt, $methods, $threshold, $coverage, count($methods));
    }

    /**
     * Cyclomatic complexity and line range for every method under $path, keyed by
     * method. When $touched is given, only methods overlapping a changed line survive.
     *
     * @param  array<string, list<int>|true>|null  $touched
     * @return list<array{method: string, file: string, line: int, complexity: int}>
     */
    private function analyze(string $path, ?array $touched): array
    {
        $parser = (new ParserFactory)->createForHostVersion();
        $methods = [];

        foreach ($this->files($path, $touched) as $file) {
            $relative = $this->relative($file->getRealPath());
            $ast = $parser->parse((string) file_get_contents($file->getRealPath()));

            if ($ast === null) {
                continue;
            }

            foreach ($this->measure($ast) as $name => $measured) {
                if ($touched !== null && ! $this->overlaps($touched[$relative] ?? [], $measured['range'])) {
                    continue;
                }

                $methods[] = [
                    'method' => $name,
                    'file' => $relative,
                    'line' => $measured['range'][0],
                    'complexity' => $measured['complexity'],
                ];
            }
        }

        usort($methods, fn (array $a, array $b) => $b['complexity'] <=> $a['complexity'] ?: strcmp($a['method'], $b['method']));

        return $methods;
    }

    /**
     * @param  array<string, list<int>|true>|null  $touched
     * @return iterable<SplFileInfo>
     */
    private function files(string $path, ?array $touched): iterable
    {
        $finder = Finder::create()->files()->in($path)->name('*.php');

        if ($touched !== null) {
            // ponytail: filter the full scan rather than stat each changed path;
            // app/ is ~400 files, so the walk is cheaper than the bookkeeping.
            $finder->filter(fn (SplFileInfo $file) => isset($touched[$this->relative($file->getRealPath())]));
        }

        return $finder;
    }

    /**
     * @param  list<Node>  $ast
     * @return array<string, array{range: array{int, int}, complexity: int}>
     */
    private function measure(array $ast): array
    {
        $visitor = new MethodComplexityVisitor;

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->measured;
    }

    /**
     * Lines each file gained or changed since $base, including uncommitted work.
     * A value of true means the whole file is new, so every method in it counts.
     *
     * @return array<string, list<int>|true>
     */
    private function touchedLines(string $base): array
    {
        $touched = [];

        // Untracked files never show up in `git diff`, and a new feature is mostly
        // new files, so they would otherwise sail through the check untouched.
        foreach (preg_split('/\R/', $this->git(['ls-files', '--others', '--exclude-standard', '--', $this->option('path')])) ?: [] as $untracked) {
            if (str_ends_with($untracked, '.php')) {
                $touched[$untracked] = true;
            }
        }

        $mergeBase = trim($this->git(['merge-base', $base, 'HEAD']));

        return $touched + $this->diffLines($mergeBase !== '' ? $mergeBase : $base);
    }

    /**
     * Added and modified line numbers per file, parsed out of a zero-context diff.
     *
     * @return array<string, list<int>>
     */
    private function diffLines(string $ref): array
    {
        $diff = $this->git(['diff', '--unified=0', '--no-color', $ref, '--', $this->option('path')]);

        $lines = [];
        $file = null;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $target = substr($line, 4);
                $file = $target === '/dev/null' ? null : preg_replace('#^b/#', '', $target);

                continue;
            }

            if ($file === null || ! str_starts_with($line, '@@') || preg_match('/\+(\d+)(?:,(\d+))?/', $line, $m) !== 1) {
                continue;
            }

            $start = (int) $m[1];

            for ($i = 0; $i < (isset($m[2]) ? (int) $m[2] : 1); $i++) {
                $lines[$file][] = $start + $i;
            }
        }

        return $lines;
    }

    /**
     * @param  list<int>|true  $lines
     * @param  array{int, int}  $range
     */
    private function overlaps(array|bool $lines, array $range): bool
    {
        if ($lines === true) {
            return true;
        }

        foreach ($lines as $line) {
            if ($line >= $range[0] && $line <= $range[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decorate $methods in place with crap and coverage from the crap4j report.
     * Returns report metadata, or false when the report is unusable.
     *
     * @param  list<array{method: string, file: string, line: int, complexity: int}>  $methods
     * @return array{source: string, generated_at: string, stale: bool}|false
     */
    private function coverage(array &$methods): array|false
    {
        $report = base_path($this->option('report'));

        if (! is_file($report)) {
            $this->components->error(sprintf(
                "No coverage report at %s.\n  Generate it with:  php -d pcov.directory=%s ./vendor/bin/pest --exclude-testsuite=Browser,Performance --coverage-crap4j=%s\n  Or skip coverage with --no-coverage to report complexity only.",
                $this->option('report'),
                $this->option('path'),
                $this->option('report'),
            ));

            return false;
        }

        $xml = simplexml_load_file($report);

        if ($xml === false) {
            $this->components->error("Could not parse {$report}");

            return false;
        }

        $byMethod = [];

        foreach ($xml->methods->method as $entry) {
            $byMethod[(string) $entry->className.'::'.(string) $entry->methodName] = [
                'crap' => round((float) $entry->crap, 1),
                'coverage' => round((float) $entry->coverage, 1),
            ];
        }

        $newest = 0;

        foreach ($methods as $i => $method) {
            $methods[$i] += $byMethod[$method['method']] ?? ['crap' => null, 'coverage' => null];
            $newest = max($newest, (int) @filemtime(base_path($method['file'])));
        }

        return [
            'source' => $this->option('report'),
            'generated_at' => date('c', (int) filemtime($report)),
            'stale' => $newest > filemtime($report),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ignored(): array
    {
        $file = base_path($this->option('ignore'));

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? array_filter($decoded, 'is_string') : [];
    }

    /**
     * @param  list<array<string, mixed>>  $over
     * @param  list<array<string, mixed>>  $exempt
     * @param  list<array<string, mixed>>  $methods
     * @param  array{source: string, generated_at: string, stale: bool}|null  $coverage
     */
    private function render(array $over, array $exempt, array $methods, int $threshold, ?array $coverage, int $analyzed): int
    {
        $stale = array_values(array_filter($exempt, fn (array $m) => $m['complexity'] <= $threshold));

        $payload = [
            'scope' => 'php',
            'included' => [$this->option('path').'/'],
            'excluded' => ['resources/js/ (no complexity pipeline)'],
            'mode' => $this->option('base') ? 'changed' : 'project',
            'base' => $this->option('base') ?: null,
            'threshold' => $threshold,
            'coverage' => $coverage,
            'methods_analyzed' => $analyzed,
            'over_threshold' => $over,
            'exempt' => $exempt,
            'stale_exemptions' => $stale,
            'verdict' => $over === [] ? 'pass' : 'fail',
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($payload);
        }

        return $over === [] ? static::SUCCESS : static::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderText(array $payload): void
    {
        if ($payload['coverage'] !== null && $payload['coverage']['stale']) {
            $this->components->warn(sprintf(
                'Coverage report is older than the code it describes; crap and coverage columns may be wrong (%s).',
                $payload['coverage']['source'],
            ));
        }

        if ($payload['over_threshold'] === []) {
            $this->components->info(sprintf(
                '%d method(s) analyzed, none above complexity %d.',
                $payload['methods_analyzed'],
                $payload['threshold'],
            ));
        } else {
            $this->renderViolations($payload['over_threshold'], $payload['threshold']);
        }

        if ($payload['stale_exemptions'] !== []) {
            $this->components->warn(sprintf(
                'These %s entries are no longer needed: %s',
                $this->option('ignore'),
                implode(', ', array_column($payload['stale_exemptions'], 'method')),
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $over
     */
    private function renderViolations(array $over, int $threshold): void
    {
        $limit = (int) $this->option('limit');

        $this->components->error(sprintf('%d method(s) above complexity %d:', count($over), $threshold));

        $this->table(
            ['Cplx', 'CRAP', 'Cov%', 'Method', 'Location'],
            array_map(fn (array $m) => [
                $m['complexity'],
                $m['crap'] ?? '-',
                $m['coverage'] ?? '-',
                $m['method'],
                $m['file'].':'.$m['line'],
            ], array_slice($over, 0, $limit)),
        );

        if (count($over) > $limit) {
            $this->line(sprintf('  ... and %d more.', count($over) - $limit));
        }
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): string
    {
        $process = new Process(['git', ...$args], base_path());
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    private function relative(string $absolute): string
    {
        return ltrim(str_replace(base_path(), '', $absolute), DIRECTORY_SEPARATOR);
    }
}
