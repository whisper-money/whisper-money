<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Models\User;
use App\Services\Widgets\WidgetSdkReference;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The reference for writing a dashboard widget: manifest, sizes, data endpoints and params, SDK, allowed imports, globals, tags, attributes and CSS classes. Read it before create_widget.')]
class GetWidgetSdk extends McpTool
{
    use GuardsCustomDashboards;

    /**
     * How a widget must be written, beyond what the lists say. The server
     * instructions point here; the lists come from the validator itself.
     */
    private const array Rules = [
        'Write every visible text in the language the user is talking to you in. There is no i18n.',
        'Amounts arrive as integers in the minor units of their currency (100 per EUR or USD, 1 per JPY or CLP, 1000 per KWD); read each row\'s currency, never assume cents. Render every amount with <Amount amount currency /> from the SDK, which formats it and blurs it in privacy mode; use formatMoney only where a component cannot go (chart ticks, tooltips) and hide or blur those figures when useWidgetEnv().privacyMode is true.',
        'Look right in light and dark mode: use theme tokens (text-muted-foreground, bg-card, border) or pair every color with its dark: variant. useWidgetEnv().theme says which one is on.',
        'Be responsive with container variants (@sm:, @md:, @xl:…), not viewport ones: the same widget is drawn at 1/3, 1/2 and 1/1 and on a phone. useWidgetEnv().isCompact is true on a phone and at 1/3 width. Fill the cell (h-full) and let ResponsiveContainer size charts.',
        'No network and no browser APIs: the data comes only from the manifest through useWidgetData(), and the environment from useWidgetEnv(). To hand data to the user (a CSV), call wm.download(filename, content) from a button.',
        'Read a key computed at run time with get(obj, key) from the SDK, and a field named target with get(obj, \'target\'): obj.target and obj[\'target\'] are rejected, as are the other forbidden property names.',
        'Event handlers on native tags take no parameters; Recharts handlers and render props (content, shape, label, a custom dot) are not allowed. For a styled chart tooltip, put <ChartTooltip valueFormatter labelFormatter /> from the SDK inside the chart in place of <Tooltip>.',
        'Draw bank logos with <BankLogo accountId /> from the SDK; there is no <img>.',
        'Handle empty data (a new user, a month without transactions) with a short message or <EmptyState>, never a blank chart.',
        'After saving, call get_widget: when the dashboard caught a runtime error it is in last_error, with the revision it came from. Fix it with update_widget.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        return $this->json([
            'rules' => self::Rules,
            ...app(WidgetSdkReference::class)->toArray(),
        ]);
    }
}
