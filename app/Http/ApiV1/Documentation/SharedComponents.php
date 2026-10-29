<?php

namespace App\Http\ApiV1\Documentation;

use Closure;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * The pieces every `/api/v1` operation shares in the spec: the error body
 * ErrorRenderer writes, the error responses built on it, and the rate-limit
 * headers. Each one is added to the document the first time an operation
 * references it, so the spec never lists a component nothing uses.
 */
final class SharedComponents
{
    /**
     * @var array<int, array{name: string, description: string, code: string, message: string}>
     */
    private const array Errors = [
        400 => [
            'name' => 'BadRequest',
            'description' => 'A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`).',
            'code' => 'invalid_cursor',
            'message' => 'No object with id 3f1e1a4c-1b2d-4c5e-9f00-1a2b3c4d5e6f exists in this list.',
        ],
        401 => [
            'name' => 'Unauthenticated',
            'description' => 'No bearer token, or one that is unknown, expired or revoked.',
            'code' => 'unauthenticated',
            'message' => 'Unauthenticated.',
        ],
        403 => [
            'name' => 'Forbidden',
            'description' => 'The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`).',
            'code' => 'insufficient_scope',
            'message' => 'This token is read-only. Create a read & write token to make changes.',
        ],
        404 => [
            'name' => 'NotFound',
            'description' => 'No object with that id exists, or it belongs to someone else.',
            'code' => 'not_found',
            'message' => 'Not Found',
        ],
        409 => [
            'name' => 'IdempotencyConflict',
            'description' => 'A request with the same `Idempotency-Key` is still being processed.',
            'code' => 'idempotency_conflict',
            'message' => 'A request with this Idempotency-Key is still being processed.',
        ],
        429 => [
            'name' => 'TooManyRequests',
            'description' => 'The rate limit is exhausted. Wait the seconds in `Retry-After`.',
            'code' => 'too_many_requests',
            'message' => 'Too Many Attempts.',
        ],
    ];

    /**
     * @var array<string, array{description: string, example: int}>
     */
    private const array Headers = [
        'X-RateLimit-Limit' => ['description' => 'Requests allowed per minute.', 'example' => 60],
        'X-RateLimit-Remaining' => ['description' => 'Requests left in the current minute.', 'example' => 59],
        'Retry-After' => ['description' => 'Seconds to wait before retrying.', 'example' => 42],
    ];

    public static function error(Components $components, int $status): Reference
    {
        $error = self::Errors[$status];

        return self::remember($components, 'responses', $error['name'], function () use ($components, $status, $error): Response {
            $response = Response::make($status)
                ->setDescription($error['description'])
                ->addContent('application/json', new MediaType(
                    schema: Schema::fromType(self::errorSchema($components)),
                    example: ['message' => $error['message'], 'code' => $error['code']],
                ));

            return $status === 429
                ? $response->addHeader('Retry-After', self::header($components, 'Retry-After'))
                : $response;
        });
    }

    /**
     * Laravel's standard validation body, which `/api/v1` keeps as it is.
     */
    public static function validationError(Components $components): Reference
    {
        return self::remember($components, 'responses', 'ValidationError', function (): Response {
            $body = (new ObjectType)
                ->addProperty('message', (new StringType)->setDescription('The first error, and how many more there are.'))
                ->addProperty('errors', (new ObjectType)
                    ->setDescription('Every invalid field, with its messages.')
                    ->additionalProperties((new ArrayType)->setItems(new StringType)))
                ->setRequired(['message', 'errors']);

            return Response::make(422)
                ->setDescription('The request failed validation.')
                ->addContent('application/json', new MediaType(
                    schema: Schema::fromType($body),
                    example: [
                        'message' => 'You do not have access to a space with id 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60. List the valid ids with GET /api/v1/spaces.',
                        'errors' => ['space' => ['You do not have access to a space with id 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60. List the valid ids with GET /api/v1/spaces.']],
                    ],
                ));
        });
    }

    /**
     * The rate-limit headers, sent on every authenticated response.
     *
     * @param  key-of<self::Headers>  $name
     */
    public static function header(Components $components, string $name): Reference
    {
        return self::remember($components, 'headers', $name, fn (): Header => new Header(
            description: self::Headers[$name]['description'],
            schema: Schema::fromType(new IntegerType),
            example: self::Headers[$name]['example'],
        ));
    }

    private static function errorSchema(Components $components): Reference
    {
        return self::remember($components, 'schemas', 'Error', fn (): Schema => Schema::fromType((new ObjectType)
            ->addProperty('message', (new StringType)->setDescription('What went wrong, in a sentence meant for a developer.'))
            ->addProperty('code', (new StringType)->setDescription('A stable, machine-readable error code, such as `not_found`.'))
            ->addProperty('subscribe_url', (new StringType)->format('uri')->setDescription('Only on `plan_required`: where to subscribe.'))
            ->setRequired(['message', 'code'])));
    }

    /**
     * The named component, added to the document on first use.
     *
     * @param  'responses'|'headers'|'schemas'  $type
     * @param  Closure(): (Response|Header|Schema)  $make
     */
    private static function remember(Components $components, string $type, string $name, Closure $make): Reference
    {
        $reference = new Reference($type, $name, $components);

        return $components->has($reference) ? $reference : $components->add($reference, $make());
    }
}
