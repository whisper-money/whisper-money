<?php

namespace App\Http\ApiV1\Documentation;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType as InferredObjectType;
use ReflectionAttribute;

/**
 * Documents what the `/api/v1` middleware and helpers do for every endpoint,
 * so a controller only describes its own work:
 *
 * - every operation: the token ability it needs (`x-token-ability`), the 401,
 *   403 and 429 errors and the rate-limit headers on its success response;
 * - a path parameter: the 404;
 * - a POST: the optional `Idempotency-Key` header, its 409 and 422;
 * - #[ScopedToSpace]: the `space` parameter and its 422;
 * - #[Paginated]: the cursor parameters, the `has_more` envelope and the 400.
 *
 * Errors are rendered by ErrorRenderer, so their shared responses replace
 * whatever Scramble inferred for the same status.
 */
class OperationConventions extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $components = $this->openApiTransformer->getComponents();
        $isWrite = ! in_array(strtoupper($operation->method), ['GET', 'HEAD', 'OPTIONS'], true);

        $operation->setExtensionProperty('token-ability', $isWrite ? 'api:write' : 'api:read');

        $paginated = $this->attribute($routeInfo, Paginated::class);

        if ($paginated !== null) {
            $this->documentPagination($operation, $components, $paginated);
        }

        if ($this->attribute($routeInfo, ScopedToSpace::class) !== null) {
            $this->documentSpace($operation, $components);
        }

        if (strtoupper($operation->method) === 'POST') {
            $this->documentIdempotency($operation, $components);
        }

        $this->documentErrors($operation, $components);
        $this->documentRateLimitHeaders($operation, $components);

        usort($operation->responses, fn (Response|Reference $first, Response|Reference $second): int => (string) $this->status($first) <=> (string) $this->status($second));
    }

    private function documentPagination(Operation $operation, Components $components, Paginated $paginated): void
    {
        $operation->addParameters([
            Parameter::make('limit', 'query')
                ->description('How many objects to return, between 1 and 100.')
                ->setSchema(Schema::fromType((new IntegerType)->default(25)))
                ->example(25),
            Parameter::make('starting_after', 'query')
                ->description('An object id: return the page that follows it. Pass the last id of the previous page.')
                ->setSchema(Schema::fromType((new StringType)->format('uuid'))),
            Parameter::make('ending_before', 'query')
                ->description('An object id: return the page that precedes it. Pass the first id of the next page. Cannot be combined with `starting_after`.')
                ->setSchema(Schema::fromType((new StringType)->format('uuid'))),
        ]);

        $item = $this->openApiTransformer->transform(new InferredObjectType($paginated->resource));

        $envelope = (new ObjectType)
            ->addProperty('data', (new ArrayType)->setItems($item))
            ->addProperty('has_more', (new BooleanType)->setDescription('Whether more objects exist past this page, in the direction being walked.'))
            ->setRequired(['data', 'has_more']);

        $this->replaceResponse($operation, Response::make(200)
            ->setDescription('A page of objects.')
            ->addContent('application/json', new MediaType(schema: Schema::fromType($envelope))));

        $this->replaceResponse($operation, SharedComponents::error($components, 400));
        $this->replaceResponse($operation, SharedComponents::validationError($components));
    }

    private function documentSpace(Operation $operation, Components $components): void
    {
        $operation->addParameters([
            Parameter::make('space', 'query')
                ->description('The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body.')
                ->setSchema(Schema::fromType((new StringType)->format('uuid'))),
        ]);

        $this->replaceResponse($operation, SharedComponents::validationError($components));
    }

    private function documentIdempotency(Operation $operation, Components $components): void
    {
        $operation->addParameters([
            Parameter::make('Idempotency-Key', 'header')
                ->description('Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request.')
                ->setSchema(Schema::fromType(new StringType))
                ->example('9b2f4c1e-5d3a-4e7b-8c6d-0a1b2c3d4e5f'),
        ]);

        $this->replaceResponse($operation, SharedComponents::error($components, 400));
        $this->replaceResponse($operation, SharedComponents::error($components, 409));
        $this->replaceResponse($operation, SharedComponents::validationError($components));
    }

    private function documentErrors(Operation $operation, Components $components): void
    {
        $hasPathParameter = collect($operation->parameters)->contains(fn (Parameter|Reference $parameter): bool => $parameter instanceof Parameter && $parameter->in === 'path');

        foreach ([401, 403, ...($hasPathParameter ? [404] : []), 429] as $status) {
            $this->replaceResponse($operation, SharedComponents::error($components, $status));
        }
    }

    private function documentRateLimitHeaders(Operation $operation, Components $components): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && is_int($response->code) && $response->code < 300) {
                $response->addHeader('X-RateLimit-Limit', SharedComponents::header($components, 'X-RateLimit-Limit'));
                $response->addHeader('X-RateLimit-Remaining', SharedComponents::header($components, 'X-RateLimit-Remaining'));
            }
        }
    }

    /**
     * Put the response in place of any other documented for its status.
     */
    private function replaceResponse(Operation $operation, Response|Reference $response): void
    {
        $status = $this->status($response);

        $operation->responses = [
            ...array_values(array_filter($operation->responses ?? [], fn (Response|Reference $existing): bool => $this->status($existing) !== $status)),
            $response,
        ];
    }

    private function status(Response|Reference $response): int|string|null
    {
        $resolved = $response instanceof Reference ? rescue(fn (): mixed => $response->resolve(), report: false) : $response;

        return $resolved instanceof Response ? $resolved->code : null;
    }

    /**
     * @template TAttribute of object
     *
     * @param  class-string<TAttribute>  $class
     * @return TAttribute|null
     */
    private function attribute(RouteInfo $routeInfo, string $class): ?object
    {
        $attribute = $routeInfo->reflectionMethod()?->getAttributes($class)[0] ?? null;

        return $attribute instanceof ReflectionAttribute ? $attribute->newInstance() : null;
    }
}
