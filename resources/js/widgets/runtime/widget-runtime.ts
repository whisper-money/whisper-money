import { registerWidgetModules } from '../load-widget-module';
import { SDK_COMPONENTS, SDK_VALUES } from '../validator/sdk-spec';
import { createWidgetModuleRegistry } from '../widget-module-registry';

let ready: Promise<void> | null = null;

/** The SDK object widgets link against: exactly the names of the contract. */
export function widgetSdkModule(
    sdk: Readonly<Record<string, unknown>>,
): Readonly<Record<string, unknown>> {
    const names = [...SDK_VALUES, ...Object.keys(SDK_COMPONENTS)];

    return Object.freeze(
        Object.fromEntries(
            names.map((name) => {
                if (sdk[name] === undefined) {
                    throw new Error(
                        `The widget SDK does not export "${name}".`,
                    );
                }

                return [name, sdk[name]];
            }),
        ),
    );
}

/**
 * Everything user widgets need before the first one loads, fetched once per
 * page and only by pages that draw one: the SDK, the module registry (with
 * Recharts and lucide) and the precompiled widget stylesheet. A failure is
 * not cached, so the next widget tries again.
 */
export function ensureWidgetRuntime(): Promise<void> {
    ready ??= Promise.all([
        import('../sdk'),
        import('../../../css/widgets.css'),
    ])
        .then(([sdk]) => createWidgetModuleRegistry(widgetSdkModule(sdk)))
        .then(registerWidgetModules, (error: unknown) => {
            ready = null;

            throw error;
        });

    return ready;
}
