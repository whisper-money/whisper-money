import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

/** The muted link treatment every public marketing page uses. */
export const MUTED_LINK_CLASSES =
    'text-sm text-[#706f6c] underline underline-offset-4 hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]';

/** The same, without the underline, for the trailing "back to home" link. */
export const BACK_LINK_CLASSES =
    'text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]';

/**
 * The head block every public marketing page needs: description, canonical, the
 * hreflang set and Open Graph. Shared so the comparison landings and the
 * integrations page cannot drift apart on the SEO markup.
 *
 * The lang attribute is deliberately absent. It comes from the Blade root, which
 * reads the locale the route pinned; setting <html> here instead crashes
 * Inertia's head manager, because that is a Vue-only idiom.
 */
export function MarketingHead({
    title,
    description,
    pageLocale,
    alternates,
}: {
    title: string;
    description: string;
    pageLocale: string;
    alternates: Record<string, string>;
}) {
    const canonical = alternates[pageLocale];

    return (
        <Head title={title}>
            <meta name="description" content={description} />
            <link rel="canonical" href={canonical} />
            <meta name="robots" content="index, follow" />

            {/* Each language is a separate URL; hreflang ties them together and
                x-default names the one to serve an unmatched language. */}
            {Object.entries(alternates).map(([locale, url]) => (
                <link
                    key={locale}
                    rel="alternate"
                    hrefLang={locale}
                    href={url}
                />
            ))}
            <link rel="alternate" hrefLang="x-default" href={alternates.en} />
            <link
                rel="alternate"
                type="text/markdown"
                href={`${canonical}.md`}
            />

            <meta property="og:site_name" content="Whisper Money" />
            <meta property="og:title" content={title} />
            <meta property="og:description" content={description} />
            <meta property="og:type" content="article" />
            <meta property="og:url" content={canonical} />
            <meta
                property="og:locale"
                content={pageLocale === 'es' ? 'es_ES' : 'en_US'}
            />
        </Head>
    );
}

/**
 * Register, or go where a visitor who is already signed in wants to go instead.
 */
export function MarketingCta({
    canRegister,
    label,
}: {
    canRegister: boolean;
    label: string;
}) {
    const { auth } = usePage<SharedData>().props;

    if (auth.user) {
        return (
            <Button asChild size="lg">
                <Link href={dashboard()}>Dashboard</Link>
            </Button>
        );
    }

    if (!canRegister) {
        return (
            <Button asChild size="lg">
                <Link href={login()}>Log in</Link>
            </Button>
        );
    }

    return (
        <Button asChild size="lg">
            <Link href="/register">{label}</Link>
        </Button>
    );
}

/**
 * How every public marketing page ends: a closing card with the CTA, and the
 * link back home.
 *
 * Pricing is a link and never a figure, because a live price experiment means
 * each visitor is shown their own arm.
 */
export function MarketingFooter({
    title,
    body,
    canRegister,
    ctaLabel,
    pricingLabel,
    backLabel,
}: {
    title: string;
    body: string;
    canRegister: boolean;
    ctaLabel: string;
    pricingLabel: string;
    backLabel: string;
}) {
    return (
        <>
            <section className="flex flex-col items-start gap-4 rounded-2xl border border-[#e3e3e0] p-8 dark:border-[#3E3E3A]">
                <h2 className="text-2xl font-semibold">{title}</h2>
                <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                    {body}
                </p>
                <div className="flex flex-wrap items-center gap-4">
                    <MarketingCta canRegister={canRegister} label={ctaLabel} />
                    <Link href="/#pricing" className={MUTED_LINK_CLASSES}>
                        {pricingLabel}
                    </Link>
                </div>
            </section>

            <Link href="/" className={BACK_LINK_CLASSES}>
                ← {backLabel}
            </Link>
        </>
    );
}

/**
 * The visible switch to the same page in another language, labelled in the
 * language it leads to.
 */
export function MarketingLanguageSwitch({
    pageLocale,
    alternates,
    label,
}: {
    pageLocale: string;
    alternates: Record<string, string>;
    label: string;
}) {
    const otherLocale = Object.keys(alternates).find(
        (locale) => locale !== pageLocale,
    );

    if (!otherLocale) {
        return null;
    }

    return (
        <a
            href={alternates[otherLocale]}
            hrefLang={otherLocale}
            className={MUTED_LINK_CLASSES}
        >
            {label}
        </a>
    );
}
