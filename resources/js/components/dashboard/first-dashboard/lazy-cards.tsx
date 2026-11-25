import { type ComponentProps, lazy, Suspense } from 'react';

/**
 * The two cards that open the importer, loaded on their own. The importer
 * hook is shared with the lazy widget dialogs; bundled into the dashboard
 * page it would make that lazy chunk import the page's chunk, and the page
 * would drop out of the Vite manifest as an entry of its own.
 */
const LazyEstimatesSwapCard = lazy(() =>
    import('./estimates-swap-card').then((module) => ({
        default: module.EstimatesSwapCard,
    })),
);

const LazyCurrentAccountNeededCard = lazy(() =>
    import('./current-account-needed-card').then((module) => ({
        default: module.CurrentAccountNeededCard,
    })),
);

export function EstimatesSwapCard(
    props: ComponentProps<typeof LazyEstimatesSwapCard>,
) {
    return (
        <Suspense fallback={null}>
            <LazyEstimatesSwapCard {...props} />
        </Suspense>
    );
}

export function CurrentAccountNeededCard(
    props: ComponentProps<typeof LazyCurrentAccountNeededCard>,
) {
    return (
        <Suspense fallback={null}>
            <LazyCurrentAccountNeededCard {...props} />
        </Suspense>
    );
}
