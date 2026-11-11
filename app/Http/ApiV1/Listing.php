<?php

namespace App\Http\ApiV1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a `/api/v1` list endpoint lists: the query already scoped to what the
 * caller may see, its order and the resource each row is shown with. The
 * controller pages through it with the cursor; the widget data gateway can
 * also read it whole, up to a cap.
 *
 * @template TModel of Model
 */
final readonly class Listing
{
    /**
     * The order of most lists: newest first.
     *
     * @var list<array{0: string, 1: 'asc'|'desc'}>
     */
    public const array NewestFirst = [['created_at', 'desc'], ['id', 'desc']];

    /**
     * How many rows all() reads per query.
     */
    private const int ChunkSize = 1000;

    /**
     * @param  Builder<TModel>  $query
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $order  ending on the primary key
     * @param  class-string<JsonResource>  $resource
     */
    public function __construct(
        private Builder $query,
        private array $order,
        private string $resource,
    ) {}

    /**
     * One page of the list, as the endpoint answers it.
     */
    public function paginate(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate($this->query, $request, $this->order, $this->resource);
    }

    /**
     * The first $max rows of the list in its order, each shown as the
     * endpoint shows it, read in chunks.
     *
     * @return array{data: list<mixed>, truncated: bool}
     */
    public function all(Request $request, int $max): array
    {
        $ordered = $this->query->clone()->reorder();

        foreach ($this->order as [$column, $direction]) {
            $ordered->orderBy($column, $direction);
        }

        $rows = [];

        for ($offset = 0; $offset <= $max; $offset += self::ChunkSize) {
            $chunk = $ordered->clone()->offset($offset)->limit(min(self::ChunkSize, $max + 1 - $offset))->get();

            array_push($rows, ...$this->resource::collection($chunk)->response($request)->getData(true)['data']);

            if ($chunk->count() < self::ChunkSize) {
                break;
            }
        }

        return [
            'data' => array_slice($rows, 0, $max),
            'truncated' => count($rows) > $max,
        ];
    }
}
