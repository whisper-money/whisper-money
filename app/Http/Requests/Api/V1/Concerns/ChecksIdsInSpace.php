<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Services\SpaceResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * For the form requests whose fields point at other objects of the request's
 * space: as in the app, each must be one of the caller's own there, so an id
 * from another space, or another member's, is refused like one that does not
 * exist.
 */
trait ChecksIdsInSpace
{
    /** @var array<string, list<string>> */
    private array $idsFoundInSpace = [];

    /**
     * A rule passing an id of one of the caller's own $model in the request's
     * space.
     *
     * @param  class-string<Model>  $model  an Account, Category or Label
     * @param  string  $noun  how the object is named in the message
     */
    protected function inSpace(string $model, string $noun): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $noun): void {
            if (! is_string($value) || ! in_array(mb_strtolower($value), $this->idsFoundInSpace($model, $attribute), true)) {
                $fail("The :attribute is not {$noun} of yours in this space.");
            }
        };
    }

    /**
     * The ids of the caller's own $model in the request's space among every
     * value sent for the field $attribute belongs to (`splits.*.label_ids.*`
     * for `splits.1.label_ids.0`), lowercased. Looked up in one query per
     * field, so a list of ids costs the same as a single one.
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private function idsFoundInSpace(string $model, string $attribute): array
    {
        $field = (string) preg_replace('/(?<=^|\.)\d+(?=\.|$)/', '*', $attribute);

        return $this->idsFoundInSpace[$model.'|'.$field] ??= $this->lookUpIdsInSpace($model, $field);
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private function lookUpIdsInSpace(string $model, string $field): array
    {
        $sent = array_values(array_filter(
            Arr::flatten(Arr::wrap(data_get($this->all(), $field))),
            fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
        ));

        if ($sent === []) {
            return [];
        }

        return app(SpaceResolver::class)->ownedForApiRequest($this, $model::query())->whereKey($sent)->pluck('id')
            ->map(fn (string $id): string => mb_strtolower($id))
            ->values()
            ->all();
    }
}
