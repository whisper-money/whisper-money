<?php

namespace App\Mcp\Tools;

use App\Models\Space;
use App\Models\User;
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

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if ($user === null) {
            return Response::error('Authentication required.');
        }

        if (! $user->hasProPlan()) {
            return Response::error(
                'A paid (Pro) plan is required to use the Whisper Money MCP. Upgrade your account at '.route('subscribe')
            );
        }

        return $this->respond($request, $user);
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
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
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
}
