<?php

use App\Http\Middleware\FailOpenThrottleRequests;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

function cacheDeadlock(): QueryException
{
    return new QueryException(
        'mysql',
        'insert ignore into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)',
        [],
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
    );
}

function failOpenThrottle(RateLimiter $limiter): FailOpenThrottleRequests
{
    return new FailOpenThrottleRequests($limiter);
}

function throttledApiRequest(): Request
{
    $request = Request::create('/api/cashflow/sankey', 'GET');

    // The framework's signature falls back to the route when there is no user, and
    // throws without either.
    $request->setRouteResolver(fn () => new Route('GET', 'api/cashflow/sankey', []));

    return $request;
}

it('serves the request when the limiter cannot record the hit', function () {
    Log::spy();

    $limiter = Mockery::mock(RateLimiter::class);
    $limiter->shouldReceive('tooManyAttempts')->andReturn(false);
    $limiter->shouldReceive('hit')->once()->andThrow(cacheDeadlock());

    $ran = 0;

    $response = failOpenThrottle($limiter)->handle(throttledApiRequest(), function () use (&$ran) {
        $ran++;

        return new Response('a chart', 200);
    }, 300, 1);

    // The user gets their chart, exactly once: the deadlock happened before the
    // request ran, so letting it through cannot replay anything.
    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('a chart');
    expect($ran)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->with('Rate limiter could not record a hit, letting the request through', Mockery::any())
        ->once();
});

it('serves the request when the limiter cannot even read the counter', function () {
    // The over-limit check is a read, not the two-statement write, and it is inside
    // the same guard on purpose: a limit we cannot look up is not a limit the user
    // should be refused by. Stated as a test because it is not what the deadlock in
    // production does, so nothing else pins it.
    $limiter = Mockery::mock(RateLimiter::class);
    $limiter->shouldReceive('tooManyAttempts')->once()->andThrow(cacheDeadlock());

    $response = failOpenThrottle($limiter)->handle(
        throttledApiRequest(),
        fn () => new Response('a chart', 200),
        300,
        1,
    );

    expect($response->getStatusCode())->toBe(200);
});

it('serves the fail-open response without the rate limit headers', function () {
    $limiter = Mockery::mock(RateLimiter::class);
    $limiter->shouldReceive('tooManyAttempts')->andReturn(false);
    $limiter->shouldReceive('hit')->once()->andThrow(cacheDeadlock());

    $response = failOpenThrottle($limiter)->handle(
        throttledApiRequest(),
        fn () => new Response('a chart', 200),
        300,
        1,
    );

    // The parent attaches them on the way back out, which this path never reaches.
    // Nothing in the app reads them; asserting it keeps that true.
    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
    expect($response->headers->has('X-RateLimit-Remaining'))->toBeFalse();
});

it('lets a deadlock raised by the request itself through untouched', function () {
    $limiter = Mockery::mock(RateLimiter::class);
    $limiter->shouldReceive('tooManyAttempts')->andReturn(false);
    $limiter->shouldReceive('hit')->once();

    $ran = 0;

    // A controller query that deadlocks is a different event: it must surface, and
    // it must not be retried behind the user's back.
    // A closure and not an arrow function: the latter would capture $ran by value,
    // and the assertion below would read its own copy.
    expect(function () use ($limiter, &$ran) {
        failOpenThrottle($limiter)->handle(throttledApiRequest(), function () use (&$ran) {
            $ran++;

            throw cacheDeadlock();
        }, 300, 1);
    })->toThrow(QueryException::class);

    expect($ran)->toBe(1);
});

it('still fails when the cache is broken rather than busy', function () {
    $broken = new QueryException(
        'mysql',
        'insert ignore into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)',
        [],
        new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'default.cache' doesn't exist"),
    );

    $limiter = Mockery::mock(RateLimiter::class);
    $limiter->shouldReceive('tooManyAttempts')->andReturn(false);
    $limiter->shouldReceive('hit')->once()->andThrow($broken);

    expect(fn () => failOpenThrottle($limiter)->handle(throttledApiRequest(), fn () => new Response('never', 200), 300, 1))
        ->toThrow(QueryException::class);
});

it('still refuses a request that is over the limit', function () {
    $limiter = Mockery::mock(RateLimiter::class);
    $limiter->shouldReceive('tooManyAttempts')->andReturn(true);
    $limiter->shouldReceive('availableIn')->andReturn(30);

    expect(fn () => failOpenThrottle($limiter)->handle(throttledApiRequest(), fn () => new Response('never', 200), 300, 1))
        ->toThrow(ThrottleRequestsException::class);
});
