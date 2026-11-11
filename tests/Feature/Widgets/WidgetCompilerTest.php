<?php

use App\Services\Widgets\WidgetCompiler;
use Illuminate\Support\Facades\Exceptions;

it('compiles a valid widget into an ES module that only imports the widget externals', function () {
    $source = (string) file_get_contents(base_path('tests/Fixtures/widgets/valid/01-groceries-by-month.tsx'));

    $compilation = app(WidgetCompiler::class)->compile($source);

    expect($compilation->ok)->toBeTrue()
        ->and($compilation->errors)->toBe([])
        ->and($compilation->js)->toContain('export{')
        ->and($compilation->timings)->toHaveKeys(['validateMs', 'buildMs']);

    preg_match_all('/from\s*"([^"]+)"/', (string) $compilation->js, $matches);

    expect(array_unique($matches[1]))->each->toBeIn([
        'react',
        'react/jsx-runtime',
        'recharts',
        'lucide-react',
        '@whisper-money/widget-sdk',
    ]);
});

it('returns the validation errors with their position and what to use instead', function () {
    $source = <<<'TSX'
        export default function Widget() {
            const leak = [].constructor;

            return <iframe src="https://evil.test" />;
        }
        TSX;

    $compilation = app(WidgetCompiler::class)->compile($source);

    expect($compilation->ok)->toBeFalse()
        ->and($compilation->js)->toBeNull()
        ->and($compilation->errors)->toHaveCount(2)
        ->and($compilation->errors[0])->toMatchArray(['line' => 2, 'code' => 'forbidden-property'])
        ->and($compilation->errors[1])->toMatchArray(['line' => 4, 'code' => 'jsx-tag'])
        ->and($compilation->errors[1]['message'])->toContain('Native tags: div, span');
});

it('gives up on a compile that outlives its timeout', function () {
    $startedAt = microtime(true);

    $compilation = (new WidgetCompiler(base_path('tests/Fixtures/widgets/slow-compiler.ts'), 1))
        ->compile('export default function W() { return <div />; }');

    expect(microtime(true) - $startedAt)->toBeLessThan(5)
        ->and($compilation->ok)->toBeFalse()
        ->and($compilation->errors[0]['code'])->toBe('timeout')
        ->and($compilation->errors[0]['message'])->toContain('longer than 1s');
});

it('reports a crashed compiler without leaking its output to the agent or the report', function () {
    Exceptions::fake();

    $compilation = (new WidgetCompiler(base_path('tests/Fixtures/widgets/crashing-compiler.ts')))
        ->compile('export default function W() { return <div />; }');

    expect($compilation->ok)->toBeFalse()
        ->and($compilation->errors[0]['code'])->toBe('compiler')
        ->and($compilation->errors[0]['message'])->not->toContain('boom');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Widget compiler crashed: exit code 3');
});

it('treats output that is not a compile result as a crash', function () {
    Exceptions::fake();

    $compilation = (new WidgetCompiler(base_path('tests/Fixtures/widgets/unexpected-output-compiler.ts')))
        ->compile('export default function W() { return <div />; }');

    expect($compilation->errors[0]['code'])->toBe('compiler');

    Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), 'unexpected output shape'));
});

it('rejects deeply nested widgets as too complex instead of crashing Bun', function (string $source) {
    $compilation = app(WidgetCompiler::class)->compile($source);

    expect($compilation->ok)->toBeFalse()
        ->and($compilation->errors[0]['code'])->toBe('too-complex');
})->with([
    'nested JSX' => 'export default function W() { return '.str_repeat('<div>', 1500).str_repeat('</div>', 1500).'; }',
    'nested parentheses' => 'export default function W() { return '.str_repeat('(', 9000).'1'.str_repeat(')', 9000).'; }',
    'long operator chain' => 'export default function W() { return '.str_repeat('1+', 25000).'1; }',
]);
