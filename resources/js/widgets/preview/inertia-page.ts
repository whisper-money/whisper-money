/**
 * What `@inertiajs/react` is in the widget preview build
 * (vite.widget-preview.config.ts aliases it here). The chat client's iframe
 * runs no Inertia app, yet SDK components draw app components that read the
 * page: `<TransactionList>`'s rows and day totals take the locale and the
 * user's currency from it. This page carries those two, from the preview
 * payload, and nothing else; there is nowhere to navigate to.
 */
interface PreviewPage {
    props: {
        locale: string;
        auth: { user: { currency_code: string } };
    };
}

let page: PreviewPage = {
    props: { locale: 'en-US', auth: { user: { currency_code: 'EUR' } } },
};

/** Called with each payload, before the widget it carries is drawn. */
export function setPreviewPage({
    locale,
    currency,
}: {
    locale: string;
    currency: string;
}): void {
    page = { props: { locale, auth: { user: { currency_code: currency } } } };
}

export function usePage(): PreviewPage {
    return page;
}
