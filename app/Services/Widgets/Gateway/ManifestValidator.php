<?php

namespace App\Services\Widgets\Gateway;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Checks a widget manifest before it is saved: its name, its size among the
 * fixed ones, and that every data call reads a registered endpoint with the
 * parameters it takes and only the dashboard variables that exist. The
 * messages are written for the agent that sent it, to fix and retry. The
 * parameter values themselves are validated when the gateway resolves them,
 * with the endpoint's own rules, since they may reference variables.
 *
 * @api called when a widget is saved, which arrives with the saved widgets
 */
class ManifestValidator
{
    private const array Widths = ['1/3', '1/2', '1/1'];

    private const array Heights = ['m', 'l'];

    /**
     * The most data calls one widget may declare.
     */
    private const int MaxCalls = 20;

    public function __construct(private EndpointRegistry $endpoints) {}

    /**
     * The manifest, once valid.
     *
     * @param  array<mixed>  $manifest
     * @return array{name: string, size: array{width: string, height: string, allowed_widths?: list<string>, allowed_heights?: list<string>}, data: array<string, array{endpoint: string, params?: array<string, mixed>, paginate?: 'all'}>}
     *
     * @throws ValidationException
     */
    public function validate(array $manifest): array
    {
        $validator = Validator::make($manifest, [
            'name' => ['required', 'string', 'max:80'],
            'size' => ['required', 'array:width,height,allowed_widths,allowed_heights'],
            'size.width' => ['required', Rule::in(self::Widths)],
            'size.height' => ['required', Rule::in(self::Heights)],
            'size.allowed_widths' => ['sometimes', 'list', 'min:1'],
            'size.allowed_widths.*' => ['distinct', Rule::in(self::Widths)],
            'size.allowed_heights' => ['sometimes', 'list', 'min:1'],
            'size.allowed_heights.*' => ['distinct', Rule::in(self::Heights)],
            'data' => ['required', 'array', 'min:1', 'max:'.self::MaxCalls],
            'data.*' => ['array:endpoint,params,paginate'],
            'data.*.endpoint' => ['required', 'string'],
            'data.*.params' => ['sometimes', 'array'],
            'data.*.paginate' => ['sometimes', Rule::in(['all'])],
        ], [
            'size.array' => 'The size takes only width, height, allowed_widths and allowed_heights.',
            'size.width.in' => 'The size.width must be one of: '.implode(', ', self::Widths).'.',
            'size.height.in' => 'The size.height must be one of: '.implode(', ', self::Heights).'.',
            'size.allowed_widths.*.in' => 'Every allowed width must be one of: '.implode(', ', self::Widths).'.',
            'size.allowed_heights.*.in' => 'Every allowed height must be one of: '.implode(', ', self::Heights).'.',
            'data.max' => 'A widget can declare at most :max data calls.',
            'data.*.array' => 'Each data call takes only endpoint, params and paginate.',
            'data.*.paginate.in' => 'The only paginate value is "all", which reads the whole list.',
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($manifest): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkSize($validator, $manifest['size']);

            foreach ($manifest['data'] as $key => $call) {
                $this->checkCall($validator, (string) $key, $call);
            }
        });

        /** @var array{name: string, size: array{width: string, height: string, allowed_widths?: list<string>, allowed_heights?: list<string>}, data: array<string, array{endpoint: string, params?: array<string, mixed>, paginate?: 'all'}>} */
        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $size
     */
    private function checkSize(ValidatorInstance $validator, array $size): void
    {
        foreach (['width' => 'allowed_widths', 'height' => 'allowed_heights'] as $dimension => $allowed) {
            if (isset($size[$allowed]) && ! in_array($size[$dimension], $size[$allowed], true)) {
                $validator->errors()->add("size.{$allowed}", "The size.{$allowed} must include the widget's own {$dimension}, {$size[$dimension]}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function checkCall(ValidatorInstance $validator, string $key, array $call): void
    {
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) !== 1) {
            $validator->errors()->add("data.{$key}", "The data key \"{$key}\" must be a JavaScript identifier, such as \"spending\": the widget reads it from useWidgetData().");
        }

        $endpoint = $this->endpoint($validator, $key, $call['endpoint']);

        if ($endpoint === null) {
            return;
        }

        $params = $call['params'] ?? [];

        if (($call['paginate'] ?? null) === 'all') {
            $this->checkReadAll($validator, $key, $endpoint, $params);
        }

        foreach ($endpoint->pathParameters() as $name) {
            if (! array_key_exists($name, $params)) {
                $validator->errors()->add("data.{$key}.params", "{$endpoint->name()} needs params.{$name}, the id in its path.");
            }
        }

        $this->checkParams($validator, $key, $endpoint, $params);
    }

    private function endpoint(ValidatorInstance $validator, string $key, string $name): ?Endpoint
    {
        $field = "data.{$key}.endpoint";

        if (preg_match('#^([A-Za-z]+) (/\S*)$#', $name, $match) !== 1) {
            $validator->errors()->add($field, "The endpoint \"{$name}\" must be a method and a path, such as \"GET /transactions\".");

            return null;
        }

        if (strtoupper($match[1]) !== 'GET') {
            $validator->errors()->add($field, "Widgets only read data: {$match[1]} is not allowed, only GET endpoints are.");

            return null;
        }

        $endpoint = $this->endpoints->find('GET '.$match[2]);

        if ($endpoint === null) {
            $validator->errors()->add($field, "GET {$match[2]} is not available to widgets. Use one of: ".implode(', ', array_keys($this->endpoints->all())).'.');
        }

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function checkReadAll(ValidatorInstance $validator, string $key, Endpoint $endpoint, array $params): void
    {
        if (! $endpoint->listable) {
            $validator->errors()->add("data.{$key}.paginate", "{$endpoint->name()} is not a paginated list, so it takes no paginate.");

            return;
        }

        foreach (array_intersect(Endpoint::CursorParameters, array_keys($params)) as $name) {
            $validator->errors()->add("data.{$key}.params.{$name}", "paginate \"all\" reads the whole list, so it takes no {$name}.");
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function checkParams(ValidatorInstance $validator, string $key, Endpoint $endpoint, array $params): void
    {
        $accepted = $endpoint->parameters();

        foreach ($params as $name => $value) {
            $field = "data.{$key}.params.{$name}";
            $kind = $accepted[$name] ?? null;

            if ($kind === null) {
                $takes = $accepted === [] ? 'It takes none.' : 'It takes: '.implode(', ', array_keys($accepted)).'.';
                $validator->errors()->add($field, "{$endpoint->name()} has no parameter \"{$name}\". {$takes}");

                continue;
            }

            if (! $this->hasKind($value, $kind)) {
                $validator->errors()->add($field, $kind === 'list'
                    ? "The {$name} parameter must be a list of strings or numbers, or a single string."
                    : "The {$name} parameter must be a string, a number or a boolean.");

                continue;
            }

            $this->checkVariables($validator, $field, $value);
        }
    }

    private function hasKind(mixed $value, string $kind): bool
    {
        if (is_scalar($value)) {
            return $kind === 'value' || is_string($value);
        }

        return $kind === 'list' && is_array($value) && array_is_list($value)
            && collect($value)->every(fn (mixed $item): bool => is_string($item) || is_int($item) || is_float($item));
    }

    private function checkVariables(ValidatorInstance $validator, string $field, mixed $value): void
    {
        foreach ((array) $value as $item) {
            foreach (DashboardVariables::referencedIn((string) $item) as $variable) {
                if (! in_array($variable, DashboardVariables::Names, true)) {
                    $known = implode(', ', array_map(fn (string $name): string => '{{'.$name.'}}', DashboardVariables::Names));
                    $validator->errors()->add($field, "{{{$variable}}} is not a dashboard variable. The variables are: {$known}.");
                }
            }
        }
    }
}
