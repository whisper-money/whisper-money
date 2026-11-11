import { registerWidgetModules } from '../load-widget-module';
import * as widgetSdk from '../sdk';
import { SDK_COMPONENTS, SDK_VALUES } from '../validator/sdk-spec';
import { createWidgetModuleRegistry } from '../widget-module-registry';

let ready: Promise<void> | null = null;

/** The SDK object widgets link against: exactly the names of the contract. */
export function widgetSdkModule(): Readonly<Record<string, unknown>> {
    const names = [...SDK_VALUES, ...Object.keys(SDK_COMPONENTS)];

    return Object.freeze(
        Object.fromEntries(
            names.map((name) => [
                name,
                (widgetSdk as Record<string, unknown>)[name],
            ]),
        ),
    );
}

/**
 * Everything user widgets need before the first one loads, fetched once per
 * page: the module registry (with the SDK, Recharts and lucide, which only
 * pages with user widgets download) and the precompiled widget stylesheet.
 * A failure is not cached, so the next widget tries again.
 */
export function ensureWidgetRuntime(): Promise<void> {
    ready ??= Promise.all([
        import('../../../css/widgets.css'),
        createWidgetModuleRegistry(widgetSdkModule()),
    ]).then(
        ([, registry]) => registerWidgetModules(registry),
        (error: unknown) => {
            ready = null;

            throw error;
        },
    );

    return ready;
}
