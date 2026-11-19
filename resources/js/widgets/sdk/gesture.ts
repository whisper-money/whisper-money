import { type WidgetDialogRequest } from './open-values';

/**
 * The user gesture the SDK's ways out of a widget (`wm.download`, `wm.open`)
 * need: a click (or a key that clicks) on the widget. `<UserWidget>` opens it
 * in the capture phase of the click and closes it once the click has bubbled
 * back, so a widget that calls them from an effect, a timer or on mount is
 * refused.
 */
export interface WidgetGesture {
    /**
     * The host lets this widget take the user to another page. Off unless
     * the host says so: a dashboard being edited and the MCP preview never do.
     */
    canNavigate: boolean;
    /**
     * Opens one of the app's dialogs (new transaction, import) over the
     * page. Only the host gives it, never the widget; absent where the host
     * has none to offer.
     */
    openDialog?: OpenWidgetDialog;
}

export type OpenWidgetDialog = (request: WidgetDialogRequest) => void;

export interface WidgetGestureOptions {
    canNavigate?: boolean;
    openDialog?: OpenWidgetDialog;
}

let current: WidgetGesture | null = null;

/**
 * Called by the runtime when a click on a widget starts (capture phase). The
 * timeout is the backstop: it fires once the whole dispatch is over, even if
 * a widget's handler threw before the click bubbled back.
 */
export function beginWidgetGesture(
    event?: { nativeEvent?: { isTrusted?: boolean } },
    options: WidgetGestureOptions = {},
): void {
    // A click the page dispatched itself (`el.click()`) is not the user's.
    if (event?.nativeEvent?.isTrusted === false) {
        return;
    }

    current = {
        canNavigate: options.canNavigate === true,
        openDialog: options.openDialog,
    };
    setTimeout(endWidgetGesture, 0);
}

/** Called by the runtime once that click has bubbled back to the widget. */
export function endWidgetGesture(): void {
    current = null;
}

/** The click the widget is handling right now, or null outside one. */
export function currentWidgetGesture(): WidgetGesture | null {
    if (current === null) {
        return null;
    }

    // Belt and braces where the browser can tell: the click must be a real one.
    const activation = (
        globalThis.navigator as { userActivation?: { isActive: boolean } }
    )?.userActivation;

    return activation === undefined || activation.isActive ? current : null;
}
