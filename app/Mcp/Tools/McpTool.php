<?php

namespace App\Mcp\Tools;

use App\Enums\PlanFeature;
use App\Models\Label;
use App\Models\McpToolCall;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every Whisper Money read tool. Enforces the Pro-plan gate on each
 * call (a lapsed subscription stops working without revoking the token) and
 * provides the shared space-resolution and JSON-encoding helpers.
 */
abstract class McpTool extends Tool
{
    /**
     * Expose snake_case tool names (search_transactions, list_spaces, …) instead
     * of the framework default kebab-case, matching the documented tool catalog.
     */
    public function name(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * The ChatGPT app directory requires all three MCP hints to be declared
     * explicitly, with a justification per tool. Default them here — read tools
     * flip `readOnlyHint` with #[IsReadOnly] and the delete tools flip
     * `destructiveHint` with #[IsDestructive]. `openWorldHint` is always false:
     * every tool reads or writes the user's own account, never the open web.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return array_merge([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ], parent::annotations());
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Authentication required.');
        }

        // The demo account's credentials are public and its data is shared, so
        // it never drives the MCP — an OAuth connection would otherwise carry
        // write access to it (see WriteTool).
        if ($user->isRestrictedDemoAccount()) {
            return Response::error('The demo account cannot be connected to an AI assistant.');
        }

        if (! $user->canUseFeature(PlanFeature::McpAccess)) {
            return Response::error(
                'A paid (Pro) plan is required to use the Whisper Money MCP. Upgrade your account at '.route('subscribe')
            );
        }

        $response = $this->respond($request, $user);

        // Usage metric (see `stats:mcp-usage`), recorded only for calls that did
        // something: an error response (a read-only token, an id the user cannot
        // reach) or a thrown ValidationException is a rejected attempt, not
        // usage. Rescued so a failed insert can never break a working call.
        if (! $response->isError()) {
            rescue(fn (): McpToolCall => McpToolCall::create([
                'user_id' => $user->id,
                'tool' => $this->name(),
            ]));
        }

        return $response;
    }

    abstract protected function respond(Request $request, User $user): Response;

    /**
     * Encode structured data as a JSON text response the agent can parse.
     */
    protected function json(mixed $data): Response
    {
        return Response::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Reuse an existing analytics controller by invoking one of its actions with
     * a synthesized GET request bound to the MCP user, returning its JSON body.
     * Keeps the (user-scoped) dashboard maths in exactly one place.
     *
     * ponytail: couples to the controllers returning a JsonResponse; acceptable
     * while they're stable. Extract the orchestration into a shared service if a
     * controller stops returning JSON or a third tool needs the same maths.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    protected function callController(object $controller, string $method, User $user, array $query): array
    {
        $httpRequest = \Illuminate\Http\Request::create('/', 'GET', $query);
        $httpRequest->setUserResolver(fn (): User => $user);

        return $controller->{$method}($httpRequest)->getData(true);
    }

    /**
     * The space a tool operates on: the optional `space` argument (validated
     * against the spaces the user can access) or the user's personal space.
     *
     * Scoping is by `space_id` only, gated by membership (`accessibleSpaces`): a
     * space is a shared tenant, so a member is meant to see every row in it. The
     * security boundary is the membership check here, not a per-row `user_id`
     * filter.
     */
    protected function resolveSpace(Request $request, User $user): Space
    {
        $spaceId = $request->string('space')->toString();

        if ($spaceId === '') {
            return $user->personalSpace ?? $user->activeSpace();
        }

        $space = $user->accessibleSpaces()->firstWhere('id', $spaceId);

        if ($space === null) {
            throw ValidationException::withMessages([
                'space' => "You do not have access to a space with id {$spaceId}. Call list_spaces to see valid ids.",
            ]);
        }

        return $space;
    }

    /**
     * Resolve every label id passed under $key, asserting each belongs to the
     * space. Returns an empty collection when the argument is absent or empty.
     *
     * @return Collection<int, Label>
     */
    protected function labelsInSpace(Request $request, Space $space, string $key): Collection
    {
        $ids = $this->requestedIds($request, $key);

        if ($ids === []) {
            /** @var Collection<int, Label> */
            return new Collection;
        }

        $labels = Label::query()->forSpace($space)->whereIn('id', $ids)->get();

        if ($labels->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $key => "One or more label ids do not exist in space {$space->id}. Call list_labels to see valid ids.",
            ]);
        }

        return $labels;
    }

    /**
     * The ids passed under $key, cast to strings and de-duplicated. Empty when
     * the argument is absent.
     *
     * @return list<string>
     */
    protected function requestedIds(Request $request, string $key): array
    {
        return collect($request->get($key, []))
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
