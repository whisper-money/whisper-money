/**
 * Entry of the widget preview MCP App (App\Mcp\Resources\WidgetPreview),
 * built into one self-contained file by vite.widget-preview.config.ts and
 * inlined into the resource's HTML: the chat client's iframe has no session
 * with the app and may load nothing but what the HTML carries.
 *
 * It waits for the result of create_widget, update_widget or get_widget and
 * draws the widget from the payload in its `_meta`
 * (App\Services\Widgets\WidgetPreviewPayload::MetaKey).
 */
import { StrictMode, useSyncExternalStore } from 'react';
import { createRoot } from 'react-dom/client';
import '../../../css/app.css';
import '../../../css/widgets.css';
import { type WidgetRuntimeError } from '../runtime/widget-error-boundary';
import { WidgetPreview, type WidgetPreviewPayload } from './widget-preview';

const META_KEY = 'whisper-money/widget-preview';

type Theme = 'light' | 'dark';

interface ToolResult {
    _meta?: Record<string, unknown>;
}

interface HostContext {
    theme?: Theme;
}

interface McpApp {
    getHostContext: () => HostContext | null;
    onToolResult: (callback: (result: ToolResult) => void) => void;
    onHostContextChanged: (callback: (context: HostContext) => void) => void;
    updateModelContext: (params: unknown) => Promise<unknown>;
    autoResize: () => void;
}

declare global {
    interface Window {
        createMcpApp?: (
            setup: (app: McpApp) => Promise<void> | void,
        ) => Promise<void>;
    }
}

/**
 * What the host told the app so far. The handlers are registered before the
 * first render, so a result that arrives right after the handshake is kept.
 */
const host = {
    payload: null as WidgetPreviewPayload | null,
    theme: 'light' as Theme,
    listeners: new Set<() => void>(),
    update(change: Partial<{ payload: WidgetPreviewPayload; theme: Theme }>) {
        Object.assign(host, change);
        document.documentElement.classList.toggle(
            'dark',
            host.theme === 'dark',
        );
        host.listeners.forEach((listener) => listener());
    },
    subscribe(listener: () => void) {
        host.listeners.add(listener);

        return () => host.listeners.delete(listener);
    },
};

function Preview({ app }: { app: McpApp }) {
    const payload = useSyncExternalStore(host.subscribe, () => host.payload);
    const theme = useSyncExternalStore(host.subscribe, () => host.theme);

    // The agent cannot see the preview: tell it when the widget breaks.
    const reportError = (error: WidgetRuntimeError) => {
        void app
            .updateModelContext({
                content: [
                    {
                        type: 'text',
                        text: `The preview of widget ${payload?.widget_id} (revision ${payload?.revision}) failed while ${error.phase === 'load' ? 'loading' : 'rendering'}: ${error.message}`,
                    },
                ],
            })
            .catch(() => undefined);
    };

    if (payload === null) {
        return (
            <div className="p-4 text-sm text-muted-foreground">
                Loading the widget preview…
            </div>
        );
    }

    return (
        <WidgetPreview payload={payload} theme={theme} onError={reportError} />
    );
}

void window.createMcpApp?.((app) => {
    host.update({ theme: app.getHostContext()?.theme ?? 'light' });
    app.onToolResult((result) => {
        const payload = result._meta?.[META_KEY];

        if (payload && typeof payload === 'object') {
            host.update({ payload: payload as WidgetPreviewPayload });
        }
    });
    app.onHostContextChanged((context) => {
        if (context.theme) {
            host.update({ theme: context.theme });
        }
    });
    app.autoResize();

    createRoot(document.getElementById('widget-preview')!).render(
        <StrictMode>
            <Preview app={app} />
        </StrictMode>,
    );
});
