<?php

namespace App\Http\ApiV1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stripe-style cursor pagination shared by every `/api/v1` list endpoint.
 *
 * Query parameters: `limit` (1–100, default 25) and either `starting_after` or
 * `ending_before`, the id of an object from a previous page. The response is
 * `{"data": [...], "has_more": bool}`; `has_more` says whether more objects
 * exist past the page in the direction being walked.
 *
 * The caller fixes the ordering, which must end on a unique column (the
 * primary key) so ties on the leading sort keys still have a stable order.
 * The cursor is resolved within the caller's scoped query, so an id that does
 * not exist or lies outside the caller's space is a 400. Sort columns are
 * assumed non-null.
 *
 * @api shared by the list endpoints, the first of which ships with the accounts resource
 */
class CursorPaginator
{
    private const int DefaultLimit = 25;

    private const int MaxLimit = 100;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already scoped to what the caller may see
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $order  e.g. [['transaction_date', 'desc'], ['id', 'desc']]
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginate(Builder $query, Request $request, array $order, string $resource): AnonymousResourceCollection
    {
        if ($order === [] || self::column(end($order)[0]) !== $query->getModel()->getKeyName()) {
            throw new InvalidArgumentException('Cursor pagination must be ordered by the primary key last.');
        }

        $params = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MaxLimit],
            'starting_after' => ['sometimes', 'string', 'prohibits:ending_before'],
            'ending_before' => ['sometimes', 'string'],
        ])->validate();

        $limit = (int) ($params['limit'] ?? self::DefaultLimit);
        $backwards = isset($params['ending_before']);
        $cursorId = $params['starting_after'] ?? $params['ending_before'] ?? null;

        // Walking back from `ending_before` reads the order in reverse and
        // flips the page back afterwards.
        $direction = $backwards ? self::reversed($order) : $order;

        $page = $query->clone()->reorder();

        if ($cursorId !== null) {
            self::after($page, $direction, self::cursor($query, $cursorId));
        }

        foreach ($direction as [$column, $dir]) {
            $page->orderBy($column, $dir);
        }

        $items = $page->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        return $resource::collection($backwards ? $items->reverse()->values() : $items)
            ->additional(['has_more' => $hasMore]);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private static function cursor(Builder $query, string $id): Model
    {
        $cursor = Str::isUuid($id) ? $query->clone()->reorder()->whereKey($id)->first() : null;

        if ($cursor === null) {
            throw ApiV1Exception::badRequest('invalid_cursor', "No object with id {$id} exists in this list.");
        }

        return $cursor;
    }

    /**
     * Keep the rows that sort strictly after the cursor:
     * (a > x) or (a = x and (b > y or (b = y and ...))).
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $order
     */
    private static function after(Builder $query, array $order, Model $cursor): void
    {
        [[$column, $dir], $rest] = [$order[0], array_slice($order, 1)];
        $value = $cursor->getRawOriginal(self::column($column));
        $operator = $dir === 'asc' ? '>' : '<';

        if ($rest === []) {
            $query->where($column, $operator, $value);

            return;
        }

        $query->where(fn (Builder $outer) => $outer
            ->where($column, $operator, $value)
            ->orWhere(fn (Builder $tie) => $tie
                ->where($column, '=', $value)
                ->where(fn (Builder $next) => self::after($next, $rest, $cursor))));
    }

    /**
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $order
     * @return list<array{0: string, 1: 'asc'|'desc'}>
     */
    private static function reversed(array $order): array
    {
        return array_map(fn (array $sort): array => [$sort[0], $sort[1] === 'asc' ? 'desc' : 'asc'], $order);
    }

    /**
     * The attribute name of a possibly table-qualified column.
     */
    private static function column(string $column): string
    {
        return Str::afterLast($column, '.');
    }
}
