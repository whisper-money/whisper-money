import type { ComponentType } from 'react';
import type { WidgetModuleRegistry } from './load-widget-module';
import { sanitizeChartData } from './sanitize-chart-data';
import { REACT_EXPORTS, SDK_MODULE } from './validator/allowlist';
import { isLucideIcon, RECHARTS_COMPONENTS } from './validator/component-props';
import { createWidgetJsxRuntime } from './widget-jsx-runtime';

/**
 * The module instances user widgets run against, ready for
 * `registerWidgetModules()`. Recharts and lucide are imported here, lazily, so
 * only pages that render user widgets pay for the whole of them (a widget may
 * use any icon). Every module is cut down to what the validator lets widgets
 * import, so a module that slipped past it still cannot link `createElement`,
 * `Customized` or lucide's raw `Icon`. Recharts components that take `data`
 * get it through sanitizeChartData, since rows can carry handlers and
 * attributes the validator never sees.
 *
 * The SDK is passed in: it is built in T8 on top of this.
 */
export async function createWidgetModuleRegistry(
    sdk: Readonly<Record<string, unknown>>,
): Promise<WidgetModuleRegistry> {
    const [react, jsxRuntime, recharts, lucide] = await Promise.all([
        import('react'),
        import('react/jsx-runtime'),
        import('recharts'),
        import('lucide-react'),
    ]);

    return {
        react: pick(react, REACT_EXPORTS),
        'react/jsx-runtime': createWidgetJsxRuntime(
            jsxRuntime as Parameters<typeof createWidgetJsxRuntime>[0],
        ),
        recharts: Object.fromEntries(
            Object.entries(
                pick(recharts, new Set(Object.keys(RECHARTS_COMPONENTS))),
            ).map(([name, component]) => [
                name,
                Object.hasOwn(RECHARTS_COMPONENTS[name], 'data')
                    ? withSanitizedData(
                          react.createElement,
                          component as ComponentType<{ data?: unknown }>,
                      )
                    : component,
            ]),
        ),
        'lucide-react': Object.fromEntries(
            Object.entries(lucide).filter(([name, value]) =>
                isLucideIcon(name, value),
            ),
        ),
        [SDK_MODULE]: sdk,
    };
}

function pick(
    module: object,
    names: ReadonlySet<string>,
): Readonly<Record<string, unknown>> {
    return Object.fromEntries(
        Object.entries(module).filter(([name]) => names.has(name)),
    );
}

function withSanitizedData(
    createElement: typeof import('react').createElement,
    Chart: ComponentType<{ data?: unknown }>,
): ComponentType<{ data?: unknown }> {
    const SanitizedChart = (props: { data?: unknown }) =>
        createElement(Chart, { ...props, data: sanitizeChartData(props.data) });

    SanitizedChart.displayName = Chart.displayName;

    return SanitizedChart;
}
