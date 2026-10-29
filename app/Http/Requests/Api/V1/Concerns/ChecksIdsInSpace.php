<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Services\SpaceResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * For the form requests whose fields point at other objects of the request's
 * space: as in the app, each must be one of the caller's own there, so an id
 * from another space, or another member's, is refused like one that does not
 * exist.
 */
trait ChecksIdsInSpace
{
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
            if (! is_string($value) || ! Str::isUuid($value) || ! app(SpaceResolver::class)->ownedForApiRequest($this, $model::query())->whereKey($value)->exists()) {
                $fail("The :attribute is not {$noun} of yours in this space.");
            }
        };
    }
}
