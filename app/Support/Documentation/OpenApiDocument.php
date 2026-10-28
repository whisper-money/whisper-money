<?php

namespace App\Support\Documentation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Read access to a serialized OpenAPI document for the API reference pages:
 * `$ref`s followed, schemas described as a type label, flattened into a field
 * list, or turned into an example value. Every made-up example is a fixed
 * value, so the pages generated from the same spec are always identical.
 *
 * @phpstan-type Schema array<string, mixed>
 * @phpstan-type Field array{name: string, type: string, required: bool, description: string}
 */
final class OpenApiDocument
{
    /**
     * Values for a string schema without an example of its own, by format.
     *
     * @var array<string, string>
     */
    private const array StringExamples = [
        'uuid' => '0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60',
        'date-time' => '2026-01-15T09:30:00+00:00',
        'date' => '2026-01-15',
        'uri' => 'https://whisper.money',
        'email' => 'jane@example.com',
    ];

    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(public readonly array $spec) {}

    /**
     * The object a `$ref` points at, or the node itself when it is not one.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function resolve(array $node): array
    {
        $reference = $node['$ref'] ?? null;

        if (! is_string($reference) || ! str_starts_with($reference, '#/')) {
            return $node;
        }

        $target = Arr::get($this->spec, str_replace('/', '.', substr($reference, 2)));

        return is_array($target) ? $this->resolve($target) : [];
    }

    /**
     * `string`, `integer`, `string (uuid)`, `array of Space`, `"a" | "b"`.
     *
     * @param  Schema  $schema
     */
    public function typeLabel(array $schema): string
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return Str::afterLast($schema['$ref'], '/');
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return implode(' | ', array_map(fn (mixed $value): string => (string) json_encode($value), $schema['enum']));
        }

        $type = $this->type($schema);

        if ($type === 'array') {
            return 'array of '.$this->typeLabel(is_array($schema['items'] ?? null) ? $schema['items'] : []);
        }

        $label = isset($schema['format']) && is_string($schema['format']) ? "{$type} ({$schema['format']})" : $type;

        return $this->isNullable($schema) ? "{$label} or null" : $label;
    }

    /**
     * Every property of an object schema, nested objects and the objects in
     * arrays spelled out as `parent.child` and `items[].child`.
     *
     * @param  Schema  $schema
     * @return list<Field>
     */
    public function fields(array $schema, string $prefix = ''): array
    {
        $schema = $this->resolve($schema);
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $fields = [];

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $property) {
            $property = is_array($property) ? $property : [];
            $path = $prefix.$name;

            $fields[] = [
                'name' => $path,
                'type' => $this->typeLabel($property),
                'required' => in_array($name, $required, true),
                'description' => $this->description($property),
            ];

            $fields = [...$fields, ...$this->nestedFields($property, $path)];
        }

        return $fields;
    }

    /**
     * An example value for the schema: its own example when it has one, a
     * value built from its properties or items when it is an object or an
     * array, and a fixed placeholder for its type otherwise.
     *
     * @param  Schema  $schema
     */
    public function example(array $schema): mixed
    {
        $resolved = $this->resolve($schema);

        if (array_key_exists('example', $resolved)) {
            return $resolved['example'];
        }

        if (isset($resolved['examples']) && is_array($resolved['examples']) && $resolved['examples'] !== []) {
            return $resolved['examples'][0];
        }

        return match ($this->type($resolved)) {
            'object' => $this->objectExample($resolved),
            'array' => [$this->example(is_array($resolved['items'] ?? null) ? $resolved['items'] : [])],
            default => $this->scalarExample($resolved),
        };
    }

    /**
     * An example request body: its required fields and the optional ones that
     * carry an example of their own. A body with fields for several cases (an
     * account of any type) would otherwise show them all at once.
     *
     * @param  Schema  $schema
     */
    public function requestExample(array $schema): mixed
    {
        $resolved = $this->resolve($schema);

        if (array_key_exists('example', $resolved) || $this->type($resolved) !== 'object') {
            return $this->example($schema);
        }

        $required = is_array($resolved['required'] ?? null) ? $resolved['required'] : [];

        return array_filter(
            $this->objectExample($resolved),
            fn (string $name): bool => in_array($name, $required, true)
                || $this->hasOwnExample(is_array($resolved['properties'][$name] ?? null) ? $resolved['properties'][$name] : []),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  Schema  $schema
     */
    private function hasOwnExample(array $schema): bool
    {
        $resolved = $this->resolve($schema);

        return array_key_exists('example', $resolved) || ! empty($resolved['examples']);
    }

    /**
     * @param  Schema  $schema
     */
    public function description(array $schema): string
    {
        $description = $schema['description'] ?? $this->resolve($schema)['description'] ?? '';

        return is_string($description) ? trim((string) preg_replace('/\s+/', ' ', $description)) : '';
    }

    /**
     * @param  Schema  $schema
     * @return list<Field>
     */
    private function nestedFields(array $schema, string $path): array
    {
        $resolved = $this->resolve($schema);

        if ($this->type($resolved) === 'array' && is_array($resolved['items'] ?? null)) {
            return $this->fields($resolved['items'], $path.'[].');
        }

        return $this->type($resolved) === 'object' ? $this->fields($resolved, $path.'.') : [];
    }

    /**
     * @param  Schema  $schema
     * @return array<string, mixed>
     */
    private function objectExample(array $schema): array
    {
        $example = [];

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $property) {
            $example[$name] = $this->example(is_array($property) ? $property : []);
        }

        return $example;
    }

    /**
     * @param  Schema  $schema
     */
    private function scalarExample(array $schema): mixed
    {
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        return match ($this->type($schema)) {
            'integer', 'number' => 0,
            'boolean' => true,
            'null' => null,
            default => self::StringExamples[$schema['format'] ?? ''] ?? 'string',
        };
    }

    /**
     * The schema's type, the non-null one when it lists several.
     *
     * @param  Schema  $schema
     */
    private function type(array $schema): string
    {
        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string');

        if (is_array($type)) {
            $type = array_values(array_diff($type, ['null']))[0] ?? 'null';
        }

        return is_string($type) ? $type : 'string';
    }

    /**
     * @param  Schema  $schema
     */
    private function isNullable(array $schema): bool
    {
        return is_array($schema['type'] ?? null) && in_array('null', $schema['type'], true);
    }
}
