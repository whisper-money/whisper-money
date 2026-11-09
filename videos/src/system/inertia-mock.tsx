/**
 * Stands in for `@inertiajs/react` inside videos (aliased in remotion.config.ts).
 * A video has no server to boot a page from, so `usePage()` reads the props
 * <VideoRoot> sets, links render as plain anchors and navigation does nothing.
 * Only these exports exist: a component using another one (useForm, Form,
 * usePoll...) fails to bundle, so add it here or render a different piece.
 */
import { type AnchorHTMLAttributes, type ReactNode } from 'react';

let pageProps: Record<string, unknown> = {};

export function setPageProps(props: Record<string, unknown>): void {
    pageProps = props;
}

export function usePage<TProps = Record<string, unknown>>(): {
    props: TProps;
    url: string;
    component: string;
} {
    return { props: pageProps as TProps, url: '/', component: 'video' };
}

export function Link({
    href,
    children,
    ...props
}: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href?: string | { url: string };
    children?: ReactNode;
}) {
    return (
        <a href={typeof href === 'string' ? href : href?.url} {...props}>
            {children}
        </a>
    );
}

export function Head(): null {
    return null;
}

const noop = (): void => {};

export const router = {
    visit: noop,
    get: noop,
    post: noop,
    reload: noop,
    on: () => noop,
};
