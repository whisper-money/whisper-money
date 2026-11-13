<?php

namespace App\Services\Widgets;

use App\Enums\WidgetFrame;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Models\Dashboard;
use App\Models\WidgetDefinition;
use App\Models\WidgetRevision;
use App\Services\Widgets\Gateway\DashboardVariables;
use App\Services\Widgets\Gateway\Endpoint;
use App\Services\Widgets\Gateway\EndpointRegistry;

/**
 * Everything an agent needs to write a widget that passes on the first try,
 * read from the same sources the save checks against: the validator's closed
 * lists (widget-sdk-reference.json, kept in step by a test), the precompiled widget
 * classes, the gateway's endpoint registry and the manifest's sizes and
 * variables. get_widget_sdk hands it over as is.
 */
class WidgetSdkReference
{
    private const string LISTS = 'resources/js/widgets/widget-sdk-reference.json';

    private const string CLASSES = 'resources/js/widgets/widget-classes.json';

    /**
     * How a widget must be written, beyond what the lists say. The server
     * instructions point here.
     */
    private const array Rules = [
        'Write every visible text in the language the user is talking to you in. There is no i18n.',
        'Amounts arrive as integers in the minor units of their currency (100 per EUR or USD, 1 per JPY or CLP, 1000 per KWD); read each row\'s currency, never assume cents. Render every amount with <Amount amount currency /> from the SDK, which formats it and blurs it in privacy mode; use formatMoney only where a component cannot go (chart ticks, tooltips) and hide those figures when useWidgetEnv().privacyMode is true.',
        'Look right in light and dark mode: use theme tokens (text-muted-foreground, bg-card, border) or pair every color with its dark: variant. useWidgetEnv().theme says which one is on.',
        'Be responsive with container variants (@sm:, @md:, @xl:…), not viewport ones: the same widget is drawn at 1/3, 1/2 and 1/1 and on a phone. useWidgetEnv().isCompact is true on a phone and at 1/3 width.',
        'Fill the cell (h-full) and let ResponsiveContainer size charts. At m or l the cell has a fixed height on a wider dashboard and clips what does not fit, so fit the content to it (cap a list to its top items); on a phone it grows with the widget, never below that height. At a the cell takes the height of the content, everywhere. Do not pin the widget to a fixed height or wrap it all in an inner scroll to fit.',
        'Pick the height by what the widget draws. a (auto) is for content with a height of its own: a row of KPI tiles, a few lines of text, a short list; it leaves no empty space under them. A chart has no height of its own (ResponsiveContainer takes the cell\'s), so a chart, or a widget whose main part is one, needs m or l, never a. On a wider dashboard every widget in a row is as tall as the tallest one, so an a widget beside an m one is at least 16rem tall.',
        'No network and no browser APIs: the data comes only from the manifest through useWidgetData(), and the environment from useWidgetEnv(). To hand data to the user (a CSV), call wm.download(filename, content) from a button.',
        'Read a key computed at run time with get(obj, key) from the SDK, and a field named target with get(obj, \'target\'): obj.target and obj[\'target\'] are rejected, as are the other forbidden property names.',
        'Event handlers on native tags take no parameters; Recharts handlers and render props (content, shape, label, a custom dot) are not allowed. For a styled chart tooltip, put <ChartTooltip valueFormatter labelFormatter /> from the SDK inside the chart in place of <Tooltip>.',
        'The dashboard already draws a card around every widget: never draw a card, border or bg-card wrapper around the whole widget, and never nest cards inside it. When the widget is a row of KPI tiles that each draw their own card, set frame: "none" in the manifest and render a heading (title and subtitle) followed by the tiles directly, leaving the top right corner of the heading free for the dashboard\'s menu button. Every other widget (charts, tables, lists) keeps the default frame.',
        'Draw bank logos with <BankLogo accountId /> from the SDK; there is no <img>.',
        'Handle empty data (a new user, a month without transactions) with a short message or <EmptyState>, never a blank chart.',
        'A saved widget can still fail on the dashboard. create_widget, update_widget and get_widget list in data_failures every data call the API refuses: fix those right away. A runtime error only shows up once the widget is placed and the user opens the dashboard; get_widget then returns it as last_error with the revision it came from. last_error: null means no error was caught yet, not that the widget works.',
    ];

    /**
     * Container variants the widget stylesheet defines, smallest first.
     */
    private const array CONTAINER_VARIANTS = ['@sm:', '@md:', '@lg:', '@xl:', '@3xl:'];

    public function __construct(private EndpointRegistry $endpoints) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rules' => self::Rules,
            'manifest' => $this->manifest(),
            'source' => [
                'max_bytes' => WidgetDefinitionService::MaxSourceBytes,
                'shape' => 'One TSX module whose default export is a function component taking no props. Import only named exports of the modules below; no `import * as`, no default imports, no dynamic import().',
            ],
            ...$this->validatorLists(),
            'classes' => $this->classes(),
            'endpoints' => $this->endpointList(),
            'limits' => [
                'widgets_per_user' => WidgetDefinition::MAX_PER_USER,
                'revisions_kept' => WidgetRevision::MAX_KEPT,
                'dashboards_per_user' => Dashboard::MAX_PER_USER,
                'widgets_per_dashboard' => Dashboard::MAX_WIDGETS,
                'rows_per_paginated_call' => config()->integer('widgets.gateway.max_rows'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'shape' => '{ name, size: { width, height, allowed_widths?, allowed_heights? }, frame?, data: { <key>: { endpoint, params?, paginate? } } }',
            'name' => 'Up to 80 characters, in the user\'s language.',
            'widths' => array_column(WidgetWidth::cases(), 'value'),
            'heights' => WidgetHeight::values(),
            'sizes' => 'Widths are fractions of a dashboard row: 1/3, 1/2 or 1/1. Heights are m (16rem), l (33.5rem, two m rows and the gap) or a (auto: the height of the content, see rules). size.width and size.height are the default; allowed_widths and allowed_heights list every size the widget can be resized to and must include the default. Without them, only the default size is allowed.',
            'frames' => array_column(WidgetFrame::cases(), 'value'),
            'frame' => 'What the dashboard draws around the widget. "card" (the default, when frame is left out) is a rounded, bordered bg-card box with no padding of its own, which the widget fills. "none" draws nothing, so the widget sits on the dashboard background: only for a row of KPI tiles that each draw their own card (see rules). Loading and error states keep the card either way.',
            'data' => 'Each key is a JavaScript identifier the widget reads from useWidgetData(). endpoint is "GET <path>" from the endpoints below, with path ids in params. paginate: "all" reads a whole list (only on listable endpoints, without limit/starting_after/ending_before), up to rows_per_paginated_call rows; past it the answer carries truncated: true. At most 20 calls.',
            'answer' => 'useWidgetData().<key> is the JSON body the API answers for that call, as documented for /api/v1 (a list read with paginate "all" is { data: [every row], truncated }). When a call fails (a param the endpoint refuses, an id that is not the user\'s), the dashboard shows that error in the widget\'s place instead of drawing it.',
            'variables' => array_map(fn (string $name): string => '{{'.$name.'}}', DashboardVariables::Names),
            'variables_note' => 'A param value may reference the range the dashboard shows, as "{{range.from}}" and "{{range.to}}" (Y-m-d). Without a range picked, it is the user\'s current month.',
            'example' => [
                'name' => 'Groceries by month',
                'size' => ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1']],
                'data' => [
                    'months' => [
                        'endpoint' => 'GET /analytics/aggregate',
                        'params' => ['group_by' => ['month'], 'types' => ['expense'], 'category_ids' => ['<category id>'], 'from' => '{{range.from}}', 'to' => '{{range.to}}'],
                    ],
                ],
            ],
            'example_frameless' => [
                'name' => 'This month at a glance',
                'size' => ['width' => '1/1', 'height' => 'a'],
                'frame' => 'none',
                'data' => [
                    'cashflow' => [
                        'endpoint' => 'GET /analytics/cashflow',
                        'params' => ['from' => '{{range.from}}', 'to' => '{{range.to}}'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The validator's closed lists, as scripts/widgets/generate-sdk-reference.ts wrote them.
     *
     * @return array<string, mixed>
     */
    private function validatorLists(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(base_path(self::LISTS)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The precompiled classes, folded by utility so the list stays readable:
     * `text-{xs,sm,base}`. Container variants share one list.
     *
     * @return array<string, mixed>
     */
    private function classes(): array
    {
        /** @var array{classes: list<string>} $file */
        $file = json_decode((string) file_get_contents(base_path(self::CLASSES)), true, flags: JSON_THROW_ON_ERROR);

        $byVariant = [];

        foreach ($file['classes'] as $class) {
            $separator = strrpos($class, ':');
            $variant = $separator === false ? '' : substr($class, 0, $separator + 1);
            $byVariant[$variant][] = substr($class, $separator === false ? 0 : $separator + 1);
        }

        $containerVariants = array_values(array_intersect(self::CONTAINER_VARIANTS, array_keys($byVariant)));

        return [
            'note' => 'Only these classes exist in widgets; anything else, arbitrary values like w-[37%] included, is rejected. Write them as literals or cn() of literals.',
            'base' => $this->fold($byVariant[''] ?? []),
            'dark:' => $this->fold($byVariant['dark:'] ?? []),
            'hover:' => $this->fold($byVariant['hover:'] ?? []),
            'container_variants' => [
                'variants' => $containerVariants,
                'classes' => $this->fold($byVariant[$containerVariants[0] ?? ''] ?? []),
                'note' => 'The widget is an inline-size container: on a desktop grid a 1/3 widget is about @sm, 1/2 about @xl and 1/1 about @3xl; on a phone every widget is under @sm. Use these, not viewport breakpoints.',
            ],
        ];
    }

    /**
     * @param  list<string>  $classes
     * @return list<string>
     */
    private function fold(array $classes): array
    {
        $groups = [];

        foreach ($classes as $class) {
            $negative = str_starts_with($class, '-') ? '-' : '';
            [$utility, $rest] = array_pad(explode('-', ltrim($class, '-'), 2), 2, null);
            $groups[$negative.$utility][] = $rest;
        }

        $folded = [];

        foreach ($groups as $utility => $suffixes) {
            $bare = in_array(null, $suffixes, true);
            $suffixes = array_values(array_filter($suffixes, fn (?string $suffix): bool => $suffix !== null));

            if ($bare) {
                $folded[] = $utility;
            }

            if ($suffixes !== []) {
                $folded[] = count($suffixes) === 1 ? "{$utility}-{$suffixes[0]}" : $utility.'-{'.implode(',', $suffixes).'}';
            }
        }

        return $folded;
    }

    /**
     * @return list<array{endpoint: string, params: array<string, 'list'|'value'>, listable: bool}>
     */
    private function endpointList(): array
    {
        return array_values(array_map(fn (Endpoint $endpoint): array => [
            'endpoint' => $endpoint->name(),
            'params' => $endpoint->parameters(),
            'listable' => $endpoint->listable,
        ], $this->endpoints->all()));
    }
}
