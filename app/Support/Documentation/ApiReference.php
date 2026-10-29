<?php

namespace App\Support\Documentation;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Str;

/**
 * The endpoint reference of the public API, as documentation pages generated
 * from its OpenAPI spec: an index, and one page per resource group (an
 * OpenAPI tag) with every endpoint in it. English only.
 *
 * `php artisan api:docs` writes them under resources/docs/documentation, and
 * a test fails when the committed pages no longer match the code. The output
 * depends on the spec alone, in the spec's order, so it can be diffed.
 *
 * @phpstan-import-type Field from OpenApiDocument
 *
 * @phpstan-type Endpoint array{method: string, path: string, operation: array<string, mixed>}
 */
final class ApiReference
{
    /**
     * Where the pages go, relative to the documentation root: the reference
     * index is the `api/reference` page and each group a page nested under it.
     */
    private const string Directory = '70-api';

    private const string IndexFile = self::Directory.'/80-reference-en.md';

    public const string GroupsDirectory = self::Directory.'/80-reference';

    /**
     * Fixed rather than read from the app URL, so the pages read the same
     * wherever they are generated.
     */
    private const string BaseUrl = 'https://whisper.money/api/v1';

    private const string Notice = '<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->';

    public function __construct(private readonly OpenApiDocument $document) {}

    /**
     * The reference of the API as the code describes it right now.
     */
    public static function current(): self
    {
        return new self(new OpenApiDocument(app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API))));
    }

    /**
     * Every page, keyed by its path relative to the documentation root.
     *
     * @return array<string, string>
     */
    public function pages(): array
    {
        $pages = [self::IndexFile => $this->indexPage()];

        foreach ($this->groups() as $position => $group) {
            $pages[self::GroupsDirectory.'/'.(($position + 1) * 10).'-'.$group['slug'].'-en.md'] = $this->groupPage($group);
        }

        return $pages;
    }

    private function indexPage(): string
    {
        $lines = [
            '# API reference',
            '',
            'Every endpoint of the Whisper Money API, grouped by resource. The base URL is `'.self::BaseUrl.'`, and the full OpenAPI spec is at ['.self::BaseUrl.'/openapi.json]('.self::BaseUrl.'/openapi.json).',
            '',
            self::Notice,
            '',
            'Read [Authentication](/documentation/api/authentication) first, and [Errors](/documentation/api/errors) for what any endpoint can answer besides its own response.',
        ];

        foreach ($this->groups() as $group) {
            $lines = [...$lines, '', '## '.$group['name'], '', $group['description'], '', '| Endpoint | What it does |', '| --- | --- |'];

            foreach ($group['endpoints'] as $endpoint) {
                $lines[] = '| [`'.$endpoint['method'].' '.$endpoint['path'].'`](/documentation/api/reference/'.$group['slug'].'#'.$this->anchor($endpoint).') | '.$this->cell($this->summary($endpoint)).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{name: string, slug: string, description: string, endpoints: list<Endpoint>}  $group
     */
    private function groupPage(array $group): string
    {
        $lines = ['# '.$group['name'], '', $group['description'], '', self::Notice, '', '{{TOC}}'];

        foreach ($group['endpoints'] as $endpoint) {
            $lines = [...$lines, '', ...$this->endpoint($endpoint)];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  Endpoint  $endpoint
     * @return list<string>
     */
    private function endpoint(array $endpoint): array
    {
        $operation = $endpoint['operation'];
        $description = $this->document->description($operation);

        return [
            '## '.$this->summary($endpoint),
            '',
            '`'.$endpoint['method'].' '.$endpoint['path'].'`',
            '',
            ...($description === '' ? [] : [$description, '']),
            $this->scope($operation),
            ...$this->parameters($operation),
            ...$this->requestBody($operation),
            ...$this->response($operation),
            ...$this->exampleRequest($endpoint),
            ...$this->exampleResponse($operation),
            ...$this->errors($operation),
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function scope(array $operation): string
    {
        return ($operation['x-token-ability'] ?? 'api:read') === 'api:write'
            ? '**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.'
            : '**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.';
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function parameters(array $operation): array
    {
        $parameters = array_map(fn (mixed $parameter): array => $this->document->resolve(is_array($parameter) ? $parameter : []), $operation['parameters'] ?? []);

        if ($parameters === []) {
            return [];
        }

        $lines = ['', '#### Parameters', '', '| Name | In | Type | Required | Description |', '| --- | --- | --- | --- | --- |'];

        foreach ($parameters as $parameter) {
            $lines[] = '| `'.$parameter['name'].'` | '.$parameter['in'].' | '.$this->cell($this->document->typeLabel(is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [])).' | '.(($parameter['required'] ?? false) ? 'Yes' : 'No').' | '.$this->cell($this->document->description($parameter)).' |';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function requestBody(array $operation): array
    {
        $schema = $this->jsonSchema($operation['requestBody'] ?? null);

        if ($schema === null) {
            return [];
        }

        return ['', '#### Request body', '', ...$this->fieldsTable($this->document->fields($schema), withRequired: true)];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function response(array $operation): array
    {
        [$status, $response] = $this->successResponse($operation);
        $schema = $this->jsonSchema($response);

        if ($schema === null) {
            return ['', '#### Response', '', '`'.$status.'` with an empty body.'];
        }

        return ['', '#### Response', '', '`'.$status.'`', '', ...$this->fieldsTable($this->document->fields($schema), withRequired: false)];
    }

    /**
     * @param  Endpoint  $endpoint
     * @return list<string>
     */
    private function exampleRequest(array $endpoint): array
    {
        $operation = $endpoint['operation'];
        $command = ['curl'.($endpoint['method'] === 'GET' ? '' : ' -X '.$endpoint['method']).' '.self::BaseUrl.$this->examplePath($endpoint), '-H "Authorization: Bearer $WHISPER_MONEY_TOKEN"'];
        $body = $this->jsonSchema($operation['requestBody'] ?? null);

        if ($body !== null) {
            $command[] = '-H "Content-Type: application/json"';
            $command[] = "-d '".$this->json($this->document->example($body))."'";
        }

        return ['', '#### Example request', '', '```bash', implode(" \\\n  ", $command), '```'];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function exampleResponse(array $operation): array
    {
        $schema = $this->jsonSchema($this->successResponse($operation)[1]);

        if ($schema === null) {
            return [];
        }

        return ['', '#### Example response', '', '```json', $this->json($this->document->example($schema)), '```'];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function errors(array $operation): array
    {
        $rows = [];

        foreach ($operation['responses'] ?? [] as $status => $response) {
            if ((int) $status >= 400) {
                $rows[] = '| `'.$status.'` | '.$this->cell($this->document->description($this->document->resolve(is_array($response) ? $response : []))).' |';
            }
        }

        return $rows === [] ? [] : ['', '#### Errors', '', '| Status | When |', '| --- | --- |', ...$rows];
    }

    /**
     * @param  list<Field>  $fields
     * @return list<string>
     */
    private function fieldsTable(array $fields, bool $withRequired): array
    {
        $lines = $withRequired
            ? ['| Field | Type | Required | Description |', '| --- | --- | --- | --- |']
            : ['| Field | Type | Description |', '| --- | --- | --- |'];

        foreach ($fields as $field) {
            $lines[] = '| `'.$field['name'].'` | '.$this->cell($field['type']).' | '.($withRequired ? ($field['required'] ? 'Yes' : 'No').' | ' : '').$this->cell($field['description']).' |';
        }

        return $lines;
    }

    /**
     * The resource groups in the spec's order, each with its endpoints.
     *
     * @return list<array{name: string, slug: string, description: string, endpoints: list<Endpoint>}>
     */
    private function groups(): array
    {
        $groups = [];

        foreach ($this->document->spec['tags'] ?? [] as $tag) {
            $groups[$tag['name']] = [
                'name' => $tag['name'],
                'slug' => Str::slug($tag['name']),
                'description' => $this->document->description($tag),
                'endpoints' => [],
            ];
        }

        foreach ($this->endpoints() as $endpoint) {
            $tag = $endpoint['operation']['tags'][0] ?? null;

            if (isset($groups[$tag])) {
                $groups[$tag]['endpoints'][] = $endpoint;
            }
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['endpoints'] !== []));
    }

    /**
     * @return list<Endpoint>
     */
    private function endpoints(): array
    {
        $endpoints = [];

        foreach ($this->document->spec['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $endpoints[] = ['method' => strtoupper($method), 'path' => '/api/v1'.$path, 'operation' => $operation];
            }
        }

        return $endpoints;
    }

    /**
     * The 2xx response an endpoint answers with, and its status.
     *
     * @param  array<string, mixed>  $operation
     * @return array{0: int|string, 1: array<string, mixed>}
     */
    private function successResponse(array $operation): array
    {
        foreach ($operation['responses'] ?? [] as $status => $response) {
            if ((int) $status >= 200 && (int) $status < 300) {
                return [$status, $this->document->resolve($response)];
            }
        }

        return [200, []];
    }

    /**
     * The JSON schema of a request body or response, when it has one.
     *
     * @return array<string, mixed>|null
     */
    private function jsonSchema(mixed $node): ?array
    {
        $schema = is_array($node) ? ($this->document->resolve($node)['content']['application/json']['schema'] ?? null) : null;

        return is_array($schema) ? $schema : null;
    }

    /**
     * The path with each `{parameter}` filled with its example value.
     *
     * @param  Endpoint  $endpoint
     */
    private function examplePath(array $endpoint): string
    {
        $path = Str::after($endpoint['path'], '/api/v1');

        foreach ($endpoint['operation']['parameters'] ?? [] as $parameter) {
            $parameter = $this->document->resolve($parameter);

            if (($parameter['in'] ?? null) === 'path') {
                $path = str_replace('{'.$parameter['name'].'}', (string) $this->document->example(is_array($parameter['schema'] ?? null) ? $parameter['schema'] : []), $path);
            }
        }

        return $path;
    }

    /**
     * @param  Endpoint  $endpoint
     */
    private function summary(array $endpoint): string
    {
        $summary = $endpoint['operation']['summary'] ?? '';

        return is_string($summary) && $summary !== '' ? $summary : $endpoint['method'].' '.$endpoint['path'];
    }

    /**
     * The id DocumentationMarkdown gives the endpoint's heading.
     *
     * @param  Endpoint  $endpoint
     */
    private function anchor(array $endpoint): string
    {
        return Str::slug($this->summary($endpoint)) ?: 'section';
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Table cells cannot carry the pipe that would end them early.
     */
    private function cell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
