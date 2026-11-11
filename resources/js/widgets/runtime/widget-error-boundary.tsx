import { __ } from '@/utils/i18n';
import { TriangleAlert } from 'lucide-react';
import { Component, type ErrorInfo, type ReactNode } from 'react';

/** A user widget's failure, as the runtime reports it to be kept with it. */
export interface WidgetRuntimeError {
    /** `load`: the module did not load; `render`: the component threw. */
    phase: 'load' | 'render';
    message: string;
    stack: string | null;
    componentStack: string | null;
}

export function toWidgetRuntimeError(
    phase: WidgetRuntimeError['phase'],
    error: unknown,
    componentStack: string | null = null,
): WidgetRuntimeError {
    const isError = error instanceof Error;

    return {
        phase,
        message: (isError ? error.message : String(error)).slice(0, 2000),
        stack: isError ? ((error.stack ?? null)?.slice(0, 8000) ?? null) : null,
        componentStack: componentStack?.slice(0, 8000) ?? null,
    };
}

/** The cell of a widget that failed: the dashboard around it keeps working. */
export function WidgetErrorState({ message }: { message: string }) {
    return (
        <div
            role="alert"
            data-slot="user-widget-error"
            className="flex h-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-destructive/40 p-4 text-center"
        >
            <TriangleAlert className="size-5 text-destructive" />
            <p className="text-sm font-medium">
                {__('This widget stopped working')}
            </p>
            <p className="text-xs text-muted-foreground">
                {__(
                    'Ask your agent to fix it, or remove it from the dashboard.',
                )}
            </p>
            <p
                title={message}
                className="line-clamp-2 max-w-full font-mono text-xs break-words text-muted-foreground"
            >
                {message}
            </p>
        </div>
    );
}

interface Props {
    /** The boundary tries again when this changes (new data can fix it). */
    resetKey: unknown;
    onError: (error: WidgetRuntimeError) => void;
    children: ReactNode;
}

interface State {
    error: WidgetRuntimeError | null;
}

/**
 * Catches what a user widget throws while rendering, so one broken widget
 * shows an error in its own cell instead of taking the dashboard down, and
 * hands the error to `onError` to be stored with the widget for its agent.
 */
export class WidgetErrorBoundary extends Component<Props, State> {
    override state: State = { error: null };

    static getDerivedStateFromError(error: unknown): State {
        return { error: toWidgetRuntimeError('render', error) };
    }

    override componentDidCatch(error: unknown, info: ErrorInfo): void {
        this.props.onError(
            toWidgetRuntimeError('render', error, info.componentStack ?? null),
        );
    }

    override componentDidUpdate(previous: Props): void {
        if (this.state.error && previous.resetKey !== this.props.resetKey) {
            this.setState({ error: null });
        }
    }

    override render(): ReactNode {
        if (this.state.error) {
            return <WidgetErrorState message={this.state.error.message} />;
        }

        return this.props.children;
    }
}
