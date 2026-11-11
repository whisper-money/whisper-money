<?php

namespace App\Services\Widgets\Gateway;

use App\Http\ApiV1\Listing;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * One `GET /api/v1` endpoint a widget may read, resolved without HTTP: the
 * form request whose rules validate its parameters, and a handler that reads
 * it with the same services and resources as its controller.
 */
final readonly class Endpoint
{
    /**
     * The cursor parameters every list endpoint takes.
     */
    public const array CursorParameters = ['limit', 'starting_after', 'ending_before'];

    /**
     * @param  string  $path  as in the API reference, path parameters in braces: `/accounts/{account}/balances`
     * @param  class-string<FormRequest>|null  $formRequest  validates the parameters, as on the controller; null when the controller takes none
     * @param  bool  $scopedToSpace  takes the optional `space` parameter
     * @param  bool  $listable  a cursor-paginated list, which `paginate: "all"` can read whole
     * @param  Closure  $handler  takes the request (the form request when there is one), the user and the path parameters, and answers a resource or a Listing
     */
    public function __construct(
        private string $path,
        private ?string $formRequest,
        private bool $scopedToSpace,
        public bool $listable,
        private Closure $handler,
    ) {}

    /**
     * $request as the endpoint's controller would get it: validated by its
     * form request, which throws the API's ValidationException otherwise.
     */
    public function validate(Request $request): Request
    {
        if ($this->formRequest === null) {
            return $request;
        }

        $formRequest = $this->formRequest::createFrom($request);
        $formRequest->setContainer(app())->setRedirector(app('redirect'));
        $formRequest->validateResolved();

        return $formRequest;
    }

    /**
     * Read the endpoint for $user.
     *
     * @param  array<string, string>  $path
     * @return JsonResource|Listing<covariant Model>
     */
    public function handle(Request $request, User $user, array $path): JsonResource|Listing
    {
        $result = ($this->handler)($request, $user, $path);

        if (! $result instanceof JsonResource && ! $result instanceof Listing) {
            throw new LogicException("The handler of {$this->name()} must answer a resource or a Listing.");
        }

        return $result;
    }

    /**
     * How a manifest names it.
     */
    public function name(): string
    {
        return 'GET '.$this->path;
    }

    /**
     * @return list<string>
     */
    public function pathParameters(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->path, $matches);

        return $matches[1];
    }

    /**
     * Every parameter it takes, with whether it is a list or a single value.
     *
     * @return array<string, 'list'|'value'>
     */
    public function parameters(): array
    {
        $parameters = array_fill_keys($this->pathParameters(), 'value');

        if ($this->scopedToSpace) {
            $parameters['space'] = 'value';
        }

        if ($this->listable) {
            $parameters += array_fill_keys(self::CursorParameters, 'value');
        }

        $formRequest = $this->formRequest === null ? null : new $this->formRequest;
        $rules = $formRequest !== null && method_exists($formRequest, 'rules') ? $formRequest->rules() : [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            if (! str_contains($field, '.')) {
                $parameters[$field] = in_array('array', $fieldRules, true) ? 'list' : 'value';
            }
        }

        return $parameters;
    }

    /**
     * The API path with the path parameters filled in.
     *
     * @param  array<string, string>  $values
     */
    public function concretePath(array $values): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $match): string => rawurlencode($values[$match[1]] ?? ''), $this->path);
    }
}
