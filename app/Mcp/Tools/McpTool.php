<?php

namespace App\Mcp\Tools;

use App\Enums\PlanFeature;
use App\Models\Label;
use App\Models\McpToolCall;
use App\Models\Space;
use App\Models\User;
use App\Services\SpaceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every Whisper Money read tool. Enforces the Standard-plan gate on each
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
     * flip `readOnlyHint` with #[IsReadOnly] and the deleting or overwriting
     * tools flip `destructiveHint` with #[IsDestructive] (see WriteTool).
     * `openWorldHint` is false here, for the read tools; WriteTool turns it on
     * for every write tool.
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

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Authentication required.');
        }

        $refusal = $this->refusal($user);

        if ($refusal instanceof Response) {
            return $refusal;
        }

        // The demo account's credentials are public and its data is shared, so
        // it never drives the MCP — an OAuth connection would otherwise carry
        // write access to it (see WriteTool).
        if ($user->isRestrictedDemoAccount()) {
            return Response::error('The demo account cannot be connected to an AI assistant.');
        }

        if (! $user->canUseFeature(PlanFeature::McpAccess)) {
            return Response::error(
                'A paid (Standard) plan is required to use the Whisper Money MCP. Upgrade your account at '.route('subscribe')
            );
        }

        $response = $this->respond($request, $user);

        // Usage metric (see `stats:mcp-usage`), recorded only for calls that did
        // something: an error response (a read-only token, an id the user cannot
        // reach) or a thrown ValidationException is a rejected attempt, not
        // usage. Rescued so a failed insert can never break a working call.
        if (! $this->isError($response)) {
            rescue(fn (): McpToolCall => McpToolCall::create([
                'user_id' => $user->id,
                'tool' => $this->name(),
            ]));
        }

        return $response;
    }

    /**
     * Why this tool refuses $user before anything else is checked, or null.
     * A tool family with its own gate overrides it (see
     * Concerns\GuardsCustomDashboards) so its message comes first.
     */
    protected function refusal(User $user): ?Response
    {
        return null;
    }

    /**
     * The answer, or a ResponseFactory when it carries `_meta` for an MCP App
     * (the widget preview) besides what the agent reads.
     */
    abstract protected function respond(Request $request, User $user): Response|ResponseFactory;

    private function isError(Response|ResponseFactory $response): bool
    {
        $responses = $response instanceof ResponseFactory ? $response->responses() : collect([$response]);

        return $responses->contains(fn (Response $item): bool => $item->isError());
    }

    /**
     * Encode structured data as a JSON text response the agent can parse.
     */
    /**
     * The currency every rolled-up figure is reported in. Analytics responses
     * carry it so the agent never has to guess: the amounts alone do not say
     * whether they are euros or dollars.
     */
    protected function reportingCurrency(User $user): string
    {
        return $user->currency_code ?? 'USD';
    }

    protected function json(mixed $data): Response
    {
        return Response::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The space a tool operates on: the optional `space` argument or the
     * user's personal space (see SpaceResolver).
     */
    protected function resolveSpace(Request $request, User $user): Space
    {
        return app(SpaceResolver::class)->resolve(
            $user,
            $request->string('space')->toString(),
            'Call list_spaces to see valid ids.',
        );
    }

    /**
     * The authenticated user. Only a User reaches respond(): handle() refuses
     * anything else first.
     */
    protected function caller(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * Resolve every label id passed under $key, asserting each is one of the
     * caller's own labels in the space, as the app does. Returns an empty collection when the argument is absent or empty.
     *
     * @return Collection<int, Label>
     */
    protected function labelsInSpace(Request $request, Space $space, string $key): Collection
    {
        return $this->ownRowsInSpace($request, $space, $key, Label::class, 'label', 'list_labels');
    }

    /**
     * Resolve every id of a $model passed under $key, asserting each is one of
     * the caller's own in the space, as the app does. Returns an empty
     * collection when the argument is absent or empty.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  string  $noun  how a row is named in the message: `label`, `account`, `category`
     * @param  string  $listTool  the tool that lists the valid ids
     * @return Collection<int, TModel>
     */
    protected function ownRowsInSpace(Request $request, Space $space, string $key, string $model, string $noun, string $listTool): Collection
    {
        $ids = $this->requestedIds($request, $key);

        if ($ids === []) {
            /** @var Collection<int, TModel> */
            return new Collection;
        }

        /** @var Collection<int, TModel> $rows */
        $rows = app(SpaceResolver::class)->ownedInSpace($model::query(), $this->caller($request), $space)->whereIn('id', $ids)->get();

        if ($rows->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $key => "One or more {$noun} ids are not ".Str::plural($noun)." of yours in space {$space->id}. Call {$listTool} to see valid ids.",
            ]);
        }

        return $rows;
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
