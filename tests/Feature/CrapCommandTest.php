<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandAlias;

const CRAP_SCRATCH = 'storage/framework/testing/crap';

/**
 * Writes a PHP file into a scratch directory the command can analyze via --path,
 * so the assertions never depend on real application code.
 */
function crapFixture(string $name, string $body): string
{
    File::ensureDirectoryExists(base_path(CRAP_SCRATCH));
    File::put(base_path(CRAP_SCRATCH."/{$name}.php"), $body);

    return CRAP_SCRATCH;
}

/**
 * @return array<string, mixed>
 */
function crapJson(string $arguments): array
{
    Artisan::call("crap {$arguments} --json");

    return json_decode(Artisan::output(), true);
}

function crapIgnoreFile(array $entries): string
{
    File::ensureDirectoryExists(base_path(CRAP_SCRATCH));
    File::put(base_path(CRAP_SCRATCH.'/ignore.json'), json_encode($entries));

    return CRAP_SCRATCH.'/ignore.json';
}

afterEach(function () {
    File::deleteDirectory(base_path(CRAP_SCRATCH));
});

it('passes when every method is below the threshold', function () {
    $path = crapFixture('Simple', <<<'PHP'
        <?php
        namespace Fixture;
        class Simple {
            public function plain(): int { return 1; }
        }
        PHP);

    $this->artisan("crap --path={$path} --no-coverage")
        ->expectsOutputToContain('none above complexity 10')
        ->assertExitCode(CommandAlias::SUCCESS);
});

it('fails and names the method when it exceeds the threshold', function () {
    $path = crapFixture('Branchy', <<<'PHP'
        <?php
        namespace Fixture;
        class Branchy {
            public function tangle(int $n): int {
                if ($n === 1) { return 1; }
                if ($n === 2) { return 2; }
                if ($n === 3) { return 3; }
                return 0;
            }
        }
        PHP);

    $this->artisan("crap --path={$path} --no-coverage --threshold=2")
        ->expectsOutputToContain('Fixture\Branchy::tangle')
        ->assertExitCode(CommandAlias::FAILURE);
});

it('counts one for the method plus one per decision point', function () {
    $path = crapFixture('Counted', <<<'PHP'
        <?php
        namespace Fixture;
        class Counted {
            public function branches(int $n, bool $flag): string {
                if ($n > 0 && $flag) { return 'a'; }
                foreach ([1, 2] as $i) { $n += $i; }
                return match ($n) { 1 => 'x', 2 => 'y', default => 'z' };
            }
        }
        PHP);

    // 1 base + if + && + foreach + 3 match arms = 7
    $json = crapJson("--path={$path} --no-coverage --threshold=0");

    expect($json['over_threshold'])->toHaveCount(1)
        ->and($json['over_threshold'][0]['complexity'])->toBe(7)
        ->and($json['over_threshold'][0]['method'])->toBe('Fixture\Counted::branches');
});

it('counts closures nested in a method toward that method', function () {
    $path = crapFixture('Nested', <<<'PHP'
        <?php
        namespace Fixture;
        class Nested {
            public function outer(array $items): array {
                return array_map(function ($item) {
                    return $item > 0 ? 'pos' : 'neg';
                }, $items);
            }
        }
        PHP);

    $json = crapJson("--path={$path} --no-coverage --threshold=0");

    expect($json['over_threshold'])->toHaveCount(1)
        ->and($json['over_threshold'][0]['complexity'])->toBe(2);
});

it('measures methods on enums, which the upstream visitor cannot', function () {
    $path = crapFixture('Kind', <<<'PHP'
        <?php
        namespace Fixture;
        enum Kind: string {
            case A = 'a';
            case B = 'b';
            public function label(): string {
                return match ($this) {
                    self::A => 'first',
                    self::B => 'second',
                };
            }
        }
        PHP);

    $json = crapJson("--path={$path} --no-coverage --threshold=0");

    expect(array_column($json['over_threshold'], 'method'))->toContain('Fixture\Kind::label');
});

it('exempts methods listed in the ignore file and reports the reason', function () {
    $path = crapFixture('Ignored', <<<'PHP'
        <?php
        namespace Fixture;
        class Ignored {
            public function tangle(int $n): int {
                if ($n === 1) { return 1; }
                if ($n === 2) { return 2; }
                return 0;
            }
        }
        PHP);

    $ignore = crapIgnoreFile(['Fixture\Ignored::tangle' => 'deliberate']);

    $json = crapJson("--path={$path} --no-coverage --threshold=2 --ignore={$ignore}");

    expect($json['over_threshold'])->toBeEmpty()
        ->and($json['verdict'])->toBe('pass')
        ->and($json['exempt'][0]['reason'])->toBe('deliberate');
});

it('flags an exemption that is no longer needed', function () {
    $path = crapFixture('Plain', <<<'PHP'
        <?php
        namespace Fixture;
        class Plain {
            public function simple(): int { return 1; }
        }
        PHP);

    $ignore = crapIgnoreFile(['Fixture\Plain::simple' => 'stale entry']);

    $json = crapJson("--path={$path} --no-coverage --ignore={$ignore}");

    expect(array_column($json['stale_exemptions'], 'method'))->toBe(['Fixture\Plain::simple']);
});

it('refuses to guess when the coverage report is missing', function () {
    $path = crapFixture('Any', '<?php namespace Fixture; class Any { public function a(): int { return 1; } }');

    $this->artisan("crap --path={$path} --report=build/does-not-exist.xml")
        ->expectsOutputToContain('--no-coverage')
        ->assertExitCode(CommandAlias::INVALID);
});

it('joins crap and coverage from the report onto the matching method', function () {
    $path = crapFixture('Reported', <<<'PHP'
        <?php
        namespace Fixture;
        class Reported {
            public function tangle(int $n): int {
                if ($n === 1) { return 1; }
                return 0;
            }
        }
        PHP);

    $report = CRAP_SCRATCH.'/crap4j.xml';
    File::put(base_path($report), <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crap_result>
          <methods>
            <method>
              <className>Fixture\Reported</className>
              <methodName>tangle</methodName>
              <crap>12.5</crap>
              <complexity>2</complexity>
              <coverage>50</coverage>
            </method>
          </methods>
        </crap_result>
        XML);

    $json = crapJson("--path={$path} --threshold=1 --report={$report}");

    // Whole floats come back as ints: json_encode writes 50.0 as 50.
    expect($json['over_threshold'][0]['crap'])->toBe(12.5)
        ->and($json['over_threshold'][0]['coverage'])->toEqual(50)
        ->and($json['coverage']['source'])->toBe($report);
});

it('reports the scope it did and did not look at', function () {
    $path = crapFixture('Scoped', '<?php namespace Fixture; class Scoped { public function a(): int { return 1; } }');

    $json = crapJson("--path={$path} --no-coverage");

    expect($json['scope'])->toBe('php')
        ->and($json['excluded'])->toContain('resources/js/ (no complexity pipeline)')
        ->and($json['mode'])->toBe('project');
});
