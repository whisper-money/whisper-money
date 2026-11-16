import {
    DASHBOARD_GRID_CLASS_NAME,
    widgetSizeClassName,
} from '@/components/dashboard/custom-dashboards/grid-layout';
import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { SafeModeNotice } from '@/widgets/runtime/safe-mode-notice';
import { useUserWidgetsGate } from '@/widgets/runtime/use-user-widgets-gate';
import { UserWidget } from '@/widgets/runtime/user-widget';
import { type WidgetRuntimeError } from '@/widgets/runtime/widget-error-boundary';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface FixtureWidget {
    id: string;
    js: string;
    data: Record<string, unknown>;
    width: WidgetWidth;
    height: WidgetHeight;
}

/**
 * A page that only the browser tests route to (tests/Browser/UserWidgetsTest
 * registers it): compiled widgets from tests/Fixtures/widgets on the
 * dashboard's 12-column grid, with the runtime, the error boundary and safe
 * mode as a dashboard will use them once widget definitions are stored.
 */
export default function UserWidgetsFixture({
    widgets,
}: {
    widgets: FixtureWidget[];
}) {
    const gate = useUserWidgetsGate(widgets.map(({ id }) => id));
    const [errors, setErrors] = useState<Record<string, WidgetRuntimeError>>(
        {},
    );

    return (
        <AppSidebarLayout breadcrumbs={[]}>
            <Head title="User widgets" />
            <div className="flex flex-col gap-6 p-4">
                {gate.mode === 'safe' && (
                    <SafeModeNotice reason={gate.reason} onExit={gate.exit} />
                )}
                {gate.mode === 'run' && (
                    <div
                        className={DASHBOARD_GRID_CLASS_NAME}
                        data-testid="user-widgets-grid"
                    >
                        {widgets.map(({ id, js, data, width, height }) => (
                            <div
                                key={id}
                                data-widget-id={id}
                                data-error-phase={errors[id]?.phase}
                                className={widgetSizeClassName({
                                    width,
                                    height,
                                })}
                            >
                                <UserWidget
                                    js={js}
                                    data={data}
                                    size={{ width, height }}
                                    onSettled={() => gate.settle(id)}
                                    onError={(error) =>
                                        setErrors((previous) => ({
                                            ...previous,
                                            [id]: error,
                                        }))
                                    }
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppSidebarLayout>
    );
}
