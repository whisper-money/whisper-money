<?php

namespace App\Services\Widgets\Gateway;

use App\Enums\ApiCredential;
use App\Http\ApiV1\ApiV1Exception;
use App\Http\ApiV1\ErrorRenderer;
use App\Http\ApiV1\Listing;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as ApiRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Resolves the data a widget's manifest declares, as the user: each call is
 * validated with its endpoint's form request, read with the same services and
 * shown with the same resources as `/api/v1`, so the widget gets what the API
 * would answer, status and body alike, errors included. Calls never go
 * through HTTP: a sub-request would replace the real request, run the API's
 * middleware (and its 60-a-minute limit) again and could leave a different
 * user signed in.
 *
 * Nothing is cached across resolutions: the same call twice within one is
 * read once, and only that one counts towards the gateway's own limit. Each
 * call made is recorded in `api_requests` as a `dashboard` one, under the
 * name of the API route it stands for.
 *
 * @api resolved by the widget data routes, which arrive with the saved widgets
 */
class WidgetDataGateway
{
    /**
     * The gateway's own rate limiter, apart from the public API's.
     */
    public const string Limiter = 'widget-gateway';

    public function __construct(
        private EndpointRegistry $endpoints,
        private ErrorRenderer $errors,
    ) {}

    /**
     * Every call of $data answered as the API would answer it.
     *
     * @param  array<string, array{endpoint: string, params?: array<string, mixed>, paginate?: 'all'}>  $data  a validated manifest's `data`
     * @return array<string, array{status: int, body: array<mixed>}>
     */
    public function resolve(User $user, array $data, DashboardVariables $variables): array
    {
        $answered = [];
        $made = [];

        $result = array_map(function (array $call) use ($user, $variables, &$answered, &$made): array {
            $params = $variables->fill($call['params'] ?? []);
            $paginate = $call['paginate'] ?? null;
            $key = md5((string) json_encode([$call['endpoint'], $this->sorted($params), $paginate]));

            if (! isset($answered[$key])) {
                $answered[$key] = $this->answer($user, $call['endpoint'], $params, $paginate);
                $made[] = [$call['endpoint'], $answered[$key]['status']];
            }

            return $answered[$key];
        }, $data);

        $this->record($user, $made);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{status: int, body: array<mixed>}
     */
    private function answer(User $user, string $name, array $params, ?string $paginate): array
    {
        try {
            $endpoint = $this->endpoints->find($name)
                ?? throw new ApiV1Exception(404, 'unknown_endpoint', "{$name} is not available to widgets.");

            $this->throttle($user);

            return $this->call($endpoint, $user, $params, $paginate === 'all');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{status: int, body: array<mixed>}
     */
    private function call(Endpoint $endpoint, User $user, array $params, bool $readAll): array
    {
        $path = [];

        foreach ($endpoint->pathParameters() as $name) {
            $path[$name] = is_string($params[$name] ?? null) && Str::isUuid($params[$name])
                ? $params[$name]
                : throw new NotFoundHttpException;

            unset($params[$name]);
        }

        $request = $endpoint->validate($this->request($endpoint, $user, $path, $params));
        $result = $endpoint->handle($request, $user, $path);

        if ($result instanceof Listing) {
            if ($readAll) {
                return ['status' => 200, 'body' => $result->all($request, (int) config('widgets.gateway.max_rows'))];
            }

            $result = $result->paginate($request);
        }

        $response = $result->response($request);

        return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    }

    /**
     * The request the endpoint's controller would get, signed in as $user,
     * without ever being dispatched: the parameters go through a query string
     * and the global middleware that trims them, as they would over HTTP.
     * Keep the list in step with the global input middleware of
     * bootstrap/app.php (Laravel's defaults today); the parity test sends
     * padded and blank strings through both.
     *
     * @param  array<string, string>  $path
     * @param  array<string, mixed>  $params
     */
    private function request(Endpoint $endpoint, User $user, array $path, array $params): Request
    {
        parse_str(http_build_query($params), $query);

        $request = Request::create('/api/v1'.$endpoint->concretePath($path), 'GET', $query);
        $request->setUserResolver(fn (): User => $user);

        foreach ([TrimStrings::class, ConvertEmptyStringsToNull::class] as $middleware) {
            app($middleware)->handle($request, fn (Request $request): Request => $request);
        }

        return $request;
    }

    /**
     * One `api_requests` row per call made, in one insert. Rescued, like
     * RecordApiRequest: usage stats must never fail a dashboard.
     *
     * @param  list<array{0: string, 1: int}>  $calls  endpoint name and status
     */
    private function record(User $user, array $calls): void
    {
        if ($calls === []) {
            return;
        }

        $now = now();

        rescue(fn (): bool => ApiRequest::query()->insert(array_map(fn (array $call): array => [
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'method' => 'GET',
            'endpoint' => $this->routeName($call[0]),
            'credential' => ApiCredential::Dashboard->value,
            'status' => $call[1],
            'created_at' => $now,
        ], $calls)));
    }

    /**
     * The name of the `/api/v1` route a manifest's endpoint stands for, as
     * RecordApiRequest stores it, so the gateway's calls group with the API's.
     */
    private function routeName(string $endpoint): string
    {
        $uri = 'api/v1'.Str::after($endpoint, ' ');

        return once(fn (): array => collect(Route::getRoutes()->get('GET'))
            ->filter(fn (ApiRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->mapWithKeys(fn (ApiRoute $route): array => [$route->uri() => $route->getName() ?? $route->uri()])
            ->all())[$uri] ?? $uri;
    }

    /**
     * Count one call against the user's gateway limit, refusing it once spent.
     */
    private function throttle(User $user): void
    {
        $limit = RateLimiter::limiter(self::Limiter)($user);
        $key = self::Limiter.':'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ApiV1Exception(429, 'too_many_requests', "Too many widget data requests. Try again in {$seconds} seconds.", ['retry_after' => $seconds]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);
    }

    /**
     * The error the API would answer: Laravel's validation body, or the
     * `/api/v1` error shape. An unexpected error is reported and answered as
     * the API's generic 500, for this call only: the dashboard's other
     * widgets still get their data.
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function failure(Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return ['status' => $exception->status, 'body' => ['message' => $exception->getMessage(), 'errors' => $exception->errors()]];
        }

        if ($exception instanceof ModelNotFoundException) {
            $exception = new NotFoundHttpException($exception->getMessage(), $exception);
        }

        if (! $exception instanceof HttpExceptionInterface) {
            report($exception);
        }

        $response = $this->errors->render($exception) ?? throw $exception;

        return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    }

    /**
     * $params with every key sorted, so the same call is recognised however its parameters are ordered.
     *
     * @param  array<mixed>  $params
     * @return array<mixed>
     */
    private function sorted(array $params): array
    {
        ksort($params);

        return array_map(fn (mixed $value): mixed => is_array($value) ? $this->sorted($value) : $value, $params);
    }
}
