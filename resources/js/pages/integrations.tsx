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
    type IntegrationsLabels,
    type IntegrationsProvider,
} from '@/types/integrations';
import { ChevronRightIcon, KeyRoundIcon, SearchIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type IntegrationsProps = {
    content: IntegrationsContent;
    countries: IntegrationsCountry[];
    providers: IntegrationsProvider[];
    labels: IntegrationsLabels;
    pageLocale: string;
    alternates: Record<string, string>;
    canRegister: boolean;
};

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
            className="group rounded-xl border border-[#e3e3e0] px-4 py-3 dark:border-[#3E3E3A]"
        >
            <summary className="flex cursor-pointer list-none items-center gap-2 text-sm font-semibold">
                <ChevronRightIcon
                    aria-hidden
                    className="size-4 shrink-0 transition-transform group-open:rotate-90"
                />
                {country.name}
                <span className="font-normal text-[#706f6c] dark:text-[#A1A09A]">
                    ({country.banks.length})
                </span>
            </summary>
            <ul className="mt-3 grid gap-x-6 gap-y-1 text-sm text-[#706f6c] sm:grid-cols-2 lg:grid-cols-3 dark:text-[#A1A09A]">
                {country.banks.map((bank) => (
                    <li key={bank}>{bank}</li>
                ))}
            </ul>
        </details>
    );
}

export default function Integrations({
    content,
    countries,
    providers,
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

    // The whole catalogue is in the served HTML, which is the point of the page;
    // the filter only narrows what is already there, with no request.
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
                          banks: country.banks.filter((bank) =>
                              matchesAll(bank, needles),
                          ),
                      },
            )
            .filter((country) => country.banks.length > 0);
    }, [countries, needles]);

    const matchCount = matches.reduce(
        (total, country) => total + country.banks.length,
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

                    {/* Banks first: "is my bank here" is the question that brought
                        the visitor, so the search box comes before everything. */}
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
                                              ':count',
                                              matchCount.toLocaleString(
                                                  pageLocale,
                                              ),
                                          ))}
                            </p>
                        </div>

                        <p className="text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {content.banks_note}
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
                            {content.providers_title}
                        </h2>
                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {content.providers_intro}
                        </p>
                        <ul className="grid gap-4 sm:grid-cols-2">
                            {providers.map((provider) => (
                                <li
                                    key={provider.name}
                                    className="flex flex-col gap-2 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-5 dark:border-[#3E3E3A] dark:bg-[#161615]"
                                >
                                    <h3 className="flex items-center gap-2 font-semibold">
                                        <KeyRoundIcon
                                            aria-hidden
                                            className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        />
                                        {provider.name}
                                    </h3>
                                    <p className="text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                        {provider.description}
                                    </p>
                                </li>
                            ))}
                        </ul>
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
