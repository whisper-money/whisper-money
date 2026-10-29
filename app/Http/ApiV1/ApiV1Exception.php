<?php

namespace App\Http\ApiV1;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An expected `/api/v1` failure with a machine-readable `code` (and optional
 * extra fields), rendered by ErrorRenderer like every other v1 error.
 */
class ApiV1Exception extends HttpException
{
    /**
     * @param  array<string, mixed>  $extra  fields merged into the error body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message, null, $headers);
    }

    public static function unauthenticated(string $message = 'Unauthenticated.'): self
    {
        return new self(401, 'unauthenticated', $message, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function insufficientScope(string $message): self
    {
        return new self(403, 'insufficient_scope', $message);
    }

    public static function badRequest(string $errorCode, string $message): self
    {
        return new self(400, $errorCode, $message);
    }
}
