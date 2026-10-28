<?php

namespace App\Http\ApiV1\Documentation;

use Attribute;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Marks a `/api/v1` list endpoint that answers through CursorPaginator, so
 * the spec documents the `limit` / `starting_after` / `ending_before` query
 * parameters, the `{"data": [...], "has_more": bool}` envelope and the 400
 * for an unknown cursor (see OperationConventions).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Paginated
{
    /**
     * @param  class-string<JsonResource>  $resource  the resource each item in `data` is
     */
    public function __construct(public string $resource) {}
}
