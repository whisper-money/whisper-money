import { BankLogo } from '@/components/bank-logo';
import {
    MarketingFooter,
    MarketingHead,
    MarketingLanguageSwitch,
} from '@/components/marketing-page';
import Header from '@/components/partials/header';
import { Input } from '@/components/ui/input';
import {
    type IntegrationsContent,
    type IntegrationsCountry,
    type IntegrationsEntry,
    type IntegrationsLabels,
} from '@/types/integrations';
import { ChevronRightIcon, KeyRoundIcon, SearchIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type IntegrationsProps = {
    content: IntegrationsContent;
    countries: IntegrationsCountry[];
    labels: IntegrationsLabels;
    pageLocale: string;
    alternates: Record<string, string>;
    canRegister: boolean;
};

/** The group that holds what is not tied to one country. */
const WORLDWIDE = 'WW';

/**
 * Lowercase and strip accents, so someone typing "halsinglands" still finds
 * "Hälsinglands Sparbank" and "caja rural" finds "Caja Rural de Navarra".
 */
function searchable(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/**
 * Every word has to appear somewhere, in any order, so "santander banco" finds
 * "Banco Santander" the way a visitor expects it to.
 */
function matchesAll(haystack: string, needles: string[]): boolean {
    const value = searchable(haystack);

    return needles.every((needle) => value.includes(needle));
}

/**
 * A country code as its flag, straight from the two letters: 'ES' is the pair
 * of regional indicator symbols that renders as 🇪🇸. No asset, no request, and
 * a platform that has no flag glyphs falls back to showing the letters.
 */
function flag(code: string): string {
    if (code === WORLDWIDE) {
        return '🌍';
    }

    return String.fromCodePoint(
        ...[...code].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65),
    );
}

function Entry({ entry }: { entry: IntegrationsEntry }) {
    return (
        <li className="flex items-start gap-2.5 py-1">
            <BankLogo
                src={entry.logo}
                name={entry.name}
                loading="lazy"
                fallback="letter"
                className="mt-px size-6 shrink-0 bg-white text-[10px] dark:bg-white/90"
            />
            <span className="leading-snug">{entry.name}</span>
            {entry.key && (
                <KeyRoundIcon
                    aria-hidden
                    className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
            )}
        </li>
    );
}

/**
 * One country, collapsed by default. `details` keeps every name in the served
 * HTML — which is the entire SEO point of the page — while stopping the largest
 * country from burying the rest of the list. A filter forces it open, so results
 * are never hidden behind a disclosure the visitor has to find.
 */
function CountryGroup({
    country,
    open,
}: {
    country: IntegrationsCountry;
    open: boolean;
}) {
    return (
        <details
            open={open}
            className="group rounded-xl border border-[#e3e3e0] px-4 py-3.5 dark:border-[#3E3E3A]"
        >
            <summary className="flex cursor-pointer list-none items-center gap-2 text-sm font-semibold">
                <ChevronRightIcon
                    aria-hidden
                    className="size-4 shrink-0 transition-transform group-open:rotate-90"
                />
                <span aria-hidden className="text-base leading-none">
                    {flag(country.code)}
                </span>
                {country.name}
                <span className="font-normal text-[#706f6c] dark:text-[#A1A09A]">
                    ({country.entries.length})
                </span>
            </summary>
            <ul className="mt-4 grid gap-x-8 gap-y-1.5 text-sm text-[#706f6c] sm:grid-cols-2 lg:grid-cols-3 dark:text-[#A1A09A]">
                {country.entries.map((entry) => (
                    <Entry key={entry.name} entry={entry} />
                ))}
            </ul>
        </details>
    );
}

export default function Integrations({
    content,
    countries,
    labels,
    pageLocale,
    alternates,
    canRegister,
}: IntegrationsProps) {
    const [query, setQuery] = useState('');

    const needles = useMemo(
        () => searchable(query).split(/\s+/).filter(Boolean),
        [query],
    );

    // The whole list is in the served HTML, which is the point of the page; the
    // filter only narrows what is already there, with no request.
    const matches = useMemo(() => {
        if (needles.length === 0) {
            return countries;
        }

        return countries
            .map((country) =>
                // Searching a country name keeps that country whole, so someone
                // typing "Spain" gets the country rather than the empty state.
                matchesAll(country.name, needles)
                    ? country
                    : {
                          ...country,
                          entries: country.entries.filter((entry) =>
                              matchesAll(entry.name, needles),
                          ),
                      },
            )
            .filter((country) => country.entries.length > 0);
    }, [countries, needles]);

    const matchCount = matches.reduce(
        (total, country) => total + country.entries.length,
        0,
    );

    const isFiltering = needles.length > 0;

    return (
        <>
            <MarketingHead
                title={content.title}
                description={content.description}
                pageLocale={pageLocale}
                alternates={alternates}
            />

            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <Header canRegister={canRegister} />

                <main className="mx-auto flex w-full max-w-3xl flex-col gap-12 px-6 pt-28 pb-12 lg:pt-32 lg:pb-16">
                    <header className="flex flex-col gap-4">
                        <h1 className="text-3xl leading-tight font-semibold sm:text-4xl">
                            {content.heading}
                        </h1>
                        <p className="text-lg leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {content.intro}
                        </p>
                        <MarketingLanguageSwitch
                            pageLocale={pageLocale}
                            alternates={alternates}
                            label={labels.other_language}
                        />
                    </header>

                    {/* One list, banks and apps together: "can I connect this"
                        is the same question whichever of the two it is. */}
                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {content.banks_title}
                        </h2>
                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {content.banks_intro}
                        </p>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="bank-filter" className="sr-only">
                                {content.search_label}
                            </label>
                            <div className="relative">
                                <SearchIcon
                                    aria-hidden
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#706f6c] dark:text-[#A1A09A]"
                                />
                                <Input
                                    id="bank-filter"
                                    type="search"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder={content.search_placeholder}
                                    className="pl-9"
                                />
                            </div>
                            <p
                                aria-live="polite"
                                className="min-h-4 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {isFiltering &&
                                    (matchCount === 1
                                        ? content.matches_one
                                        : content.matches.replace(
                                              ':matches',
                                              matchCount.toLocaleString(
                                                  pageLocale,
                                              ),
                                          ))}
                            </p>
                        </div>

                        <p className="flex gap-2 text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            <KeyRoundIcon
                                aria-hidden
                                className="mt-1 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                            />
                            <span>{content.banks_note}</span>
                        </p>

                        {matches.length > 0 ? (
                            <div className="flex flex-col gap-2">
                                {matches.map((country) => (
                                    <CountryGroup
                                        key={country.code}
                                        country={country}
                                        open={isFiltering}
                                    />
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col gap-2 rounded-2xl border border-[#e3e3e0] p-6 dark:border-[#3E3E3A]">
                                <h3 className="font-semibold">
                                    {content.empty_title}
                                </h3>
                                <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                    {content.empty_body}
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {content.importer_title}
                        </h2>
                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {content.importer_body}
                        </p>
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {content.notes_title}
                        </h2>
                        <ul className="flex list-disc flex-col gap-3 pl-5">
                            {content.notes.map((note, index) => (
                                <li
                                    key={index}
                                    className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {note}
                                </li>
                            ))}
                        </ul>
                    </section>

                    <MarketingFooter
                        title={content.closing_title}
                        body={content.closing_body}
                        canRegister={canRegister}
                        ctaLabel={labels.cta}
                        pricingLabel={labels.pricing}
                        backLabel={labels.back}
                    />
                </main>
            </div>
        </>
    );
}
