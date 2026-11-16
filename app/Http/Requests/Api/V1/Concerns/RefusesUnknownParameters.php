<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\Validator;

/**
 * For the analytics requests: a parameter the endpoint does not take is
 * refused, as the lists refuse one, so a misspelt filter is never read as no
 * filter and a `space` sent to a figure that adds up every space is never
 * read as that space's. A request with more to check declares its own
 * after() and calls refuseUnknownParameters() from it.
 */
trait RefusesUnknownParameters
{
    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseUnknownParameters($validator, array_keys($this->rules()))];
    }

    /**
     * Adds an error under every parameter sent that is not among $known.
     *
     * @param  list<string>  $known
     */
    protected function refuseUnknownParameters(Validator $validator, array $known): void
    {
        foreach (array_diff(array_map(strval(...), array_keys($this->all())), $known) as $key) {
            $validator->errors()->add($key, "This endpoint does not take a {$key} parameter.");
        }
    }
}
