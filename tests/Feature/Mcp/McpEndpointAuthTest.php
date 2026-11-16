<?php

use App\Features\CustomDashboards;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListSpaces;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

$rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

it('rejects the MCP endpoint without a token', function () use ($rpc) {
    postJson('/mcp', $rpc)->assertUnauthorized();
});

it('rejects a token without the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('not-mcp', ['other'])->plainTextToken;

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertForbidden();
});

it('rejects an API token, whatever its abilities', function (array $abilities) use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('api', $abilities)->plainTextToken;

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertForbidden();
})->with([
    'read' => [['api:read']],
    'read & write' => [['api:read', 'api:write']],
]);

it('keeps accepting a legacy id|secret MCP token', function () use ($rpc) {
    $secret = Str::random(40);
    $legacy = User::factory()->create()->tokens()->create([
        'name' => 'Legacy',
        'token' => hash('sha256', $secret),
        'abilities' => ['mcp:read'],
    ]);

    withHeaders(['Authorization' => "Bearer {$legacy->id}|{$secret}"])
        ->postJson('/mcp', $rpc)
        ->assertOk();
});

it('accepts a token carrying the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    // Auth + ability middleware pass, so the request reaches the MCP transport
    // (which answers the JSON-RPC envelope) rather than being rejected.
    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertOk();
});

it('serves the whole tool catalogue on one page', function () use ($rpc) {
    // A client that does not follow nextCursor must still see every tool, so
    // the page size has to stay ahead of the catalogue as it grows. The flag
    // adds the custom dashboard tools, so with it on every tool is served.
    $user = User::factory()->create();
    Feature::for($user)->activate(CustomDashboards::class);
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    $result = withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertOk()
        ->json('result');

    $registered = (new ReflectionClass(WhisperMoneyServer::class))
        ->getDefaultProperties()['tools'];

    expect($result['nextCursor'] ?? null)->toBeNull()
        ->and($result['tools'])->toHaveCount(count($registered));
});

/**
 * Every tool the server publishes over the wire, as the client sees it.
 *
 * tools/list is paginated, so walk the cursor rather than assuming the tool
 * under test happens to land on the first page — adding any tool ahead of it
 * used to break this.
 *
 * @return array<int, array<string, mixed>>
 */
function publishedMcpToolCatalogue(User $user): array
{
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;
    $tools = [];
    $cursor = null;

    do {
        $result = withHeaders(['Authorization' => "Bearer {$plain}"])
            ->postJson('/mcp', array_filter([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => $cursor === null ? null : ['cursor' => $cursor],
            ]))
            ->assertOk()
            ->json('result');

        $tools = array_merge($tools, $result['tools'] ?? []);
        $cursor = $result['nextCursor'] ?? null;
    } while ($cursor !== null);

    return $tools;
}

it('lists the transaction split write tool with its required schema', function () {
    $tools = publishedMcpToolCatalogue(User::factory()->create());

    $splitTool = collect($tools)->firstWhere('name', 'split_transaction');

    expect($splitTool)->not->toBeNull()
        ->and($splitTool['inputSchema']['required'])->toContain('transaction_id', 'splits')
        ->and($splitTool['inputSchema']['properties']['splits']['type'])->toBe('array')
        ->and($splitTool['annotations']['destructiveHint'] ?? null)->toBeTrue();
});

it('publishes the clearable arguments as nullable in the schema', function (string $tool, string $argument) {
    // Saying "or null to clear" in a description is not enough: a client that
    // validates against the published schema refuses to send the documented
    // call unless the type itself admits null.
    $tools = publishedMcpToolCatalogue(User::factory()->create());

    $property = collect($tools)->firstWhere('name', $tool)['inputSchema']['properties'][$argument] ?? null;

    expect($property)->not->toBeNull()
        ->and($property['type'])->toBe(['string', 'null']);
})->with([
    ['categorize_transaction', 'category_id'],
    ['update_transaction', 'category_id'],
    ['update_transaction', 'notes'],
    ['update_transaction', 'creditor_name'],
    ['update_transaction', 'debtor_name'],
    ['update_category', 'parent_id'],
    ['update_automation_rule', 'action_category_id'],
    ['update_automation_rule', 'action_note'],
    ['update_savings_goal', 'target_date'],
]);

it('keeps a nullable argument required when the call must carry it', function () {
    // category_id is both: the key has to be present (absent means "no change
    // requested"), and null is the documented way to remove the category.
    $tools = publishedMcpToolCatalogue(User::factory()->create());

    $schema = collect($tools)->firstWhere('name', 'categorize_transaction')['inputSchema'];

    expect($schema['required'])->toContain('transaction_id', 'category_id');
});

it('restricts the recurring series status filter to the statuses that exist', function () {
    $tools = publishedMcpToolCatalogue(User::factory()->create());

    $status = collect($tools)->firstWhere('name', 'list_recurring_series')['inputSchema']['properties']['status'];

    expect($status['enum'])->toBe(['active', 'lapsed']);
});

it('rejects the demo account, whose credentials are public and data is shared', function () {
    config(['app.demo.email' => 'demo@example.test']);
    $user = User::factory()->create(['email' => 'demo@example.test']);
    $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertHasErrors();
});
