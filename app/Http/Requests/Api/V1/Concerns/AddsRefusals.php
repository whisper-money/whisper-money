<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * For the form requests that refuse unknown body fields and run a shared
 * refusal (AccountWriteRules, AccountBalanceRecorder) after their rules: its
 * errors, keyed by field, are added to the validator like any other. A request
 * with more to refuse than unknown fields declares its own after().
 */
trait AddsRefusals
{
    /**
     * Refuse unknown fields.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }

    /**
     * An after() refusing unknown fields and what $refusals finds once the
     * rules have run, keyed by field: a null refusal is none.
     *
     * @param  Closure(): array<string, ?string>  $refusals
     * @return array<int, callable(Validator): void>
     */
    protected function refusingUnknownFieldsAnd(Closure $refusals): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...array_filter($refusals()),
        ])];
    }

    /**
     * @param  array<string, string>  $refusals
     */
    protected function addRefusals(Validator $validator, array $refusals): void
    {
        foreach ($refusals as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * A body field the endpoint does not take, so a misspelt one (`currency`
     * for `currency_code`) is reported instead of silently ignored.
     *
     * @return array<string, string>
     */
    protected function unknownFieldRefusals(): array
    {
        $known = [...array_keys($this->rules()), 'space'];

        return collect($this->sentFields())
            ->reject(fn (string $field): bool => in_array($field, $known, true))
            ->mapWithKeys(fn (string $field): array => [$field => "This endpoint does not take a {$field} field."])
            ->all();
    }

    /**
     * The fields the request carries: exactly the data it is validated with,
     * the query string included, so a field cannot skip a refusal by being
     * sent there instead of in the body.
     *
     * @return list<string>
     */
    protected function sentFields(): array
    {
        return array_map(strval(...), array_keys($this->validationData()));
    }
}
