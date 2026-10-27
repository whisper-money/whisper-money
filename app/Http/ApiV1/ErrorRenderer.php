<?php

namespace App\Http\ApiV1;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single exit point for every `/api/v1` error, so all of them share one
 * shape: `{"message": "...", "code": "not_found"}`, plus any extra fields an
 * ApiV1Exception carries. Validation errors keep Laravel's standard 422 body.
 * Future date-based versioning hooks its error transformers in here.
 *
 * @api registered in bootstrap/app.php `withExceptions`
 */
class ErrorRenderer
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public function render(Throwable $exception): ?JsonResponse
    {
        if ($exception instanceof ValidationException || $exception instanceof HttpResponseException) {
            return null;
        }

        if ($exception instanceof AuthenticationException) {
            $exception = ApiV1Exception::unauthenticated();
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $body = [
            'message' => $this->message($exception, $status),
            'code' => $exception instanceof ApiV1Exception
                ? $exception->errorCode
                : Str::snake(Response::$statusTexts[$status] ?? 'Error'),
            ...($exception instanceof ApiV1Exception ? $exception->extra : []),
        ];

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * Server errors never leak their message, and a missing model (which
     * Laravel words with the model's class name) gets the generic text.
     */
    private function message(Throwable $exception, int $status): string
    {
        $generic = Response::$statusTexts[$status] ?? 'Error';

        if ($status >= 500 || $exception->getPrevious() instanceof ModelNotFoundException) {
            return $generic;
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : $generic;
    }
}
