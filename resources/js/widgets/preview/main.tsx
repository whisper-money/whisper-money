/**
 * Entry of the widget preview MCP App (App\Mcp\Resources\WidgetPreview),
 * built into one self-contained file by vite.widget-preview.config.ts and
 * inlined into the resource's HTML: the chat client's iframe has no session
 * with the app and may load nothing but what the HTML carries.
 *
 * It waits for the result of create_widget, update_widget or get_widget and
 * draws the widget from the payload in its `_meta`.
 */
import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../../../css/app.css';
import '../../../css/widgets.css';
import { type WidgetRuntimeError } from '../runtime/widget-error-boundary';
import { WidgetPreview, type WidgetPreviewPayload } from './widget-preview';

const META_KEY = 'whisper-money/widget-preview';

interface ToolResult {
    _meta?: Record<string, unknown>;
}

interface HostContext {
    theme?: 'light' | 'dark';
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

function applyTheme(theme: 'light' | 'dark'): void {
    document.documentElement.classList.toggle('dark', theme === 'dark');
}

function Preview({ app }: { app: McpApp }) {
    const [payload, setPayload] = useState<WidgetPreviewPayload | null>(null);
    const [theme, setTheme] = useState<'light' | 'dark'>(
        app.getHostContext()?.theme ?? 'light',
    );

    useEffect(() => {
        app.onToolResult((result) => {
            const next = result._meta?.[META_KEY];

            if (next && typeof next === 'object') {
                setPayload(next as WidgetPreviewPayload);
            }
        });
        app.onHostContextChanged((context) => {
            if (context.theme) {
                setTheme(context.theme);
            }
        });
        app.autoResize();
    }, [app]);

    useEffect(() => applyTheme(theme), [theme]);

    // The agent cannot see the preview: tell it when the widget breaks, the
    // way get_widget would after the dashboard caught it.
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
    createRoot(document.getElementById('widget-preview')!).render(
        <StrictMode>
            <Preview app={app} />
        </StrictMode>,
    );
});
