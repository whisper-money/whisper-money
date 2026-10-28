<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Services\SpaceResolver;
use Closure;
use Illuminate\Support\Str;

/**
 * For the form requests whose fields point at other objects of the request's
 * space: an id from another space, or someone else's, is refused like one
 * that does not exist.
 */
trait ChecksIdsInSpace
{
    private ?Space $requestSpace = null;

    /**
     * A rule passing an id of a $model in the request's space.
     *
     * @param  class-string<Account|Category|Label>  $model
     * @param  string  $noun  how the object is named in the message
     */
    protected function inSpace(string $model, string $noun): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $noun): void {
            $this->requestSpace ??= app(SpaceResolver::class)->forApiRequest($this);

            if (! is_string($value) || ! Str::isUuid($value) || ! $model::query()->forSpace($this->requestSpace)->whereKey($value)->exists()) {
                $fail("The :attribute is not {$noun} in this space.");
            }
        };
    }
}
