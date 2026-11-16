/**
 * How the validator checks the value of one prop of an imported component.
 *
 * - `value`: any expression except a function or a JSX element (also inside
 *   object and array literals), so it can never become a render prop or a
 *   callback that receives events or DOM nodes.
 * - `boolean`: a literal `true` or `false`.
 * - `text`: a string or number literal, or a template literal.
 * - `color`: a color, or `url(#id)` as a literal (`fill`, `stroke`).
 * - `style`: an object literal whose keys are in STYLE_PROPERTIES.
 * - `className`: literal classes from the precompiled widget CSS.
 * - `formatter`: a function that receives values (never events), or a value.
 * - `handler`: a function without parameters.
 * - `tick`: a boolean literal, or an object literal with font and fill keys.
 */
export type PropKind =
    | 'value'
    | 'boolean'
    | 'text'
    | 'color'
    | 'style'
    | 'className'
    | 'formatter'
    | 'handler'
    | 'tick';

export type ComponentProps = Readonly<Record<string, PropKind>>;

const KEY_PROP = { key: 'value' } as const;

const CARTESIAN_CHART: ComponentProps = {
    ...KEY_PROP,
    data: 'value',
    layout: 'value',
    margin: 'value',
    width: 'value',
    height: 'value',
    barGap: 'value',
    barCategoryGap: 'value',
    barSize: 'value',
    maxBarSize: 'value',
    stackOffset: 'value',
    reverseStackOrder: 'value',
    accessibilityLayer: 'boolean',
    className: 'className',
};

const SERIES: ComponentProps = {
    ...KEY_PROP,
    dataKey: 'formatter',
    name: 'value',
    unit: 'value',
    fill: 'color',
    stroke: 'color',
    strokeWidth: 'value',
    strokeDasharray: 'value',
    fillOpacity: 'value',
    strokeOpacity: 'value',
    stackId: 'value',
    xAxisId: 'value',
    yAxisId: 'value',
    legendType: 'value',
    hide: 'value',
    isAnimationActive: 'value',
    animationDuration: 'value',
};

const LINE_LIKE: ComponentProps = {
    ...SERIES,
    type: 'value',
    connectNulls: 'value',
    dot: 'boolean',
    activeDot: 'boolean',
};

const AXIS: ComponentProps = {
    ...KEY_PROP,
    dataKey: 'formatter',
    type: 'value',
    hide: 'value',
    width: 'value',
    height: 'value',
    orientation: 'value',
    reversed: 'value',
    allowDecimals: 'value',
    allowDuplicatedCategory: 'value',
    domain: 'value',
    interval: 'value',
    minTickGap: 'value',
    padding: 'value',
    scale: 'value',
    unit: 'value',
    tickCount: 'value',
    tickMargin: 'value',
    tickSize: 'value',
    tickLine: 'boolean',
    axisLine: 'boolean',
    mirror: 'value',
    stroke: 'color',
    tick: 'tick',
    tickFormatter: 'formatter',
    xAxisId: 'value',
    yAxisId: 'value',
};

/**
 * Recharts components a widget may import, each with its closed list of props.
 * Event handlers (`onClick`, `onMouseMove`…) and render props (`content`,
 * `shape`, `label`, `customized`, `dot` as a function or element) are left out
 * on purpose: they receive the native event or props holding DOM nodes. Custom
 * tooltips go through the SDK's `<ChartTooltip>`.
 */
export const RECHARTS_COMPONENTS: Readonly<Record<string, ComponentProps>> = {
    ResponsiveContainer: {
        ...KEY_PROP,
        width: 'value',
        height: 'value',
        minWidth: 'value',
        minHeight: 'value',
        maxHeight: 'value',
        aspect: 'value',
        debounce: 'value',
        className: 'className',
    },
    BarChart: CARTESIAN_CHART,
    LineChart: CARTESIAN_CHART,
    AreaChart: CARTESIAN_CHART,
    PieChart: {
        ...KEY_PROP,
        width: 'value',
        height: 'value',
        margin: 'value',
        accessibilityLayer: 'boolean',
        className: 'className',
    },
    Bar: {
        ...SERIES,
        radius: 'value',
        barSize: 'value',
        maxBarSize: 'value',
        minPointSize: 'value',
    },
    Line: LINE_LIKE,
    Area: { ...LINE_LIKE, baseValue: 'value' },
    Pie: {
        ...KEY_PROP,
        data: 'value',
        dataKey: 'formatter',
        nameKey: 'formatter',
        cx: 'value',
        cy: 'value',
        innerRadius: 'value',
        outerRadius: 'value',
        startAngle: 'value',
        endAngle: 'value',
        paddingAngle: 'value',
        cornerRadius: 'value',
        minAngle: 'value',
        fill: 'color',
        stroke: 'color',
        strokeWidth: 'value',
        labelLine: 'boolean',
        legendType: 'value',
        isAnimationActive: 'value',
        animationDuration: 'value',
    },
    Cell: {
        ...KEY_PROP,
        fill: 'color',
        stroke: 'color',
        strokeWidth: 'value',
        fillOpacity: 'value',
        opacity: 'value',
    },
    XAxis: AXIS,
    YAxis: AXIS,
    CartesianGrid: {
        ...KEY_PROP,
        horizontal: 'boolean',
        vertical: 'boolean',
        stroke: 'color',
        strokeDasharray: 'value',
        strokeOpacity: 'value',
        className: 'className',
    },
    Tooltip: {
        ...KEY_PROP,
        formatter: 'formatter',
        labelFormatter: 'formatter',
        separator: 'value',
        offset: 'value',
        cursor: 'boolean',
        trigger: 'value',
        shared: 'value',
        filterNull: 'value',
        isAnimationActive: 'value',
        animationDuration: 'value',
        wrapperStyle: 'style',
        contentStyle: 'style',
        labelStyle: 'style',
        itemStyle: 'style',
    },
    Legend: {
        ...KEY_PROP,
        align: 'value',
        verticalAlign: 'value',
        layout: 'value',
        iconType: 'value',
        iconSize: 'value',
        width: 'value',
        height: 'value',
        formatter: 'formatter',
        wrapperStyle: 'style',
    },
    ReferenceLine: {
        ...KEY_PROP,
        x: 'value',
        y: 'value',
        stroke: 'color',
        strokeWidth: 'value',
        strokeDasharray: 'value',
        ifOverflow: 'value',
        label: 'text',
        xAxisId: 'value',
        yAxisId: 'value',
    },
};

/**
 * Props a lucide icon accepts. lucide passes every other prop down to its
 * `<svg>`, so `href`, handlers and friends must not get through.
 */
export const LUCIDE_ICON_PROPS: ComponentProps = {
    ...KEY_PROP,
    className: 'className',
    size: 'value',
    strokeWidth: 'value',
    title: 'text',
};

/** lucide exports that are not icons: they render arbitrary SVG nodes. */
export const LUCIDE_NON_ICON_EXPORTS: ReadonlySet<string> = new Set([
    'Icon',
    'LucideProvider',
    'createLucideIcon',
    'icons',
    'default',
]);

/**
 * Whether a lucide-react export is an icon component. Takes the export rather
 * than importing lucide, so the runtime registry can filter the module it
 * loads lazily without pulling lucide into the app bundle.
 */
export function isLucideIcon(name: string, value: unknown): boolean {
    return (
        /^[A-Z]/.test(name) &&
        !LUCIDE_NON_ICON_EXPORTS.has(name) &&
        typeof value === 'object' &&
        value !== null &&
        '$$typeof' in value
    );
}

/** `react`'s only component export. */
export const REACT_COMPONENTS: Readonly<Record<string, ComponentProps>> = {
    Fragment: { ...KEY_PROP },
};
