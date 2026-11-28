/**
 * The widget preview without a chat client around it, for render_widget:
 * scripts/render-widget.mjs opens the preview's HTML in headless Chromium
 * with a `RenderRequest` already on `window`, waits for the cell to leave
 * `loading` (`data-render-status`), then reads what was drawn through
 * `window.__whisperMoneyInspect`.
 *
 * The cell is drawn alone at the pixel size the dashboard's grid gives it,
 * with the same SDK, error boundary and stylesheet as the chat preview.
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { type WidgetRuntimeError } from '../runtime/widget-error-boundary';
import { compactWidgetHtml, type CompactHtml } from './compact-html';
import { setPreviewPage } from './inertia-page';
import { layoutDiagnostics, type LayoutReport } from './layout-diagnostics';
import { WidgetPreviewCell, type WidgetPreviewPayload } from './widget-preview';

export interface RenderRequest {
    payload: WidgetPreviewPayload;
    theme: 'light' | 'dark';
    /** In CSS pixels; a null height is an `a` cell, as tall as its content. */
    cell: { width: number; height: number | null };
}

export type RenderInspection = CompactHtml &
    LayoutReport & {
        status: string | null;
        errors: WidgetRuntimeError[];
    };

declare global {
    interface Window {
        __whisperMoneyRender?: RenderRequest;
        __whisperMoneyInspect?: (maxHtmlLength: number) => RenderInspection;
    }
}

export function renderForInspection(
    request: RenderRequest,
    container: HTMLElement,
): void {
    const errors: WidgetRuntimeError[] = [];

    setPreviewPage(request.payload);
    document.documentElement.classList.toggle('dark', request.theme === 'dark');

    window.__whisperMoneyInspect = (maxHtmlLength) => {
        const cell = container.querySelector<HTMLElement>(
            '[data-render-status]',
        )!;

        return {
            status: cell.dataset.renderStatus ?? null,
            errors,
            ...layoutDiagnostics(cell),
            ...compactWidgetHtml(cell, maxHtmlLength),
        };
    };

    createRoot(container).render(
        <StrictMode>
            <WidgetPreviewCell
                payload={request.payload}
                theme={request.theme}
                privacyMode={false}
                onError={(error) => errors.push(error)}
                style={{
                    width: `${request.cell.width}px`,
                    height:
                        request.cell.height === null
                            ? undefined
                            : `${request.cell.height}px`,
                }}
            />
        </StrictMode>,
    );
}
