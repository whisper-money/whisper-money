import {
    MarketingFooter,
    MarketingHead,
    MarketingLanguageSwitch,
} from '@/components/marketing-page';
import Header from '@/components/partials/header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { tailwindColorClasses } from '@/components/user-info';
import {
    type ComparisonLabels,
    type ComparisonPageContent,
    type ComparisonTestimonial,
} from '@/types/comparison';
import { Facehash } from 'facehash';
import { CheckIcon } from 'lucide-react';

type ComparisonProps = {
    page: ComparisonPageContent;
    labels: ComparisonLabels;
    pageLocale: string;
    alternates: Record<string, string>;
    canRegister: boolean;
};

function withRival(template: string, rival: string): string {
    return template.replace(':rival', rival);
}

function Testimonial({ testimonial }: { testimonial: ComparisonTestimonial }) {
    return (
        <figure className="flex flex-col rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-6 shadow-sm dark:border-[#3E3E3A] dark:bg-[#161615]">
            <blockquote className="text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                {testimonial.text}
            </blockquote>
            <figcaption className="mt-4 flex items-center gap-3">
                <Avatar className="size-10">
                    <AvatarImage
                        src={
                            testimonial.avatar ??
                            (testimonial.gravatar
                                ? `https://www.gravatar.com/avatar/${testimonial.gravatar}?s=160&d=404`
                                : undefined)
                        }
                        alt={testimonial.name}
                        loading="lazy"
                        className="object-cover"
                    />
                    <AvatarFallback>
                        <Facehash
                            name={testimonial.name}
                            size={40}
                            colorClasses={tailwindColorClasses}
                            intensity3d="dramatic"
                            className="rounded-full"
                        />
                    </AvatarFallback>
                </Avatar>
                <span className="text-sm font-semibold">
                    {testimonial.name}
                </span>
            </figcaption>
        </figure>
    );
}

function ComparisonTable({
    page,
    dimensionLabel,
}: {
    page: ComparisonPageContent;
    dimensionLabel: string;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-left text-sm">
                <thead>
                    <tr className="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th className="w-1/5 py-3 pr-4 font-semibold">
                            {dimensionLabel}
                        </th>
                        <th className="w-2/5 py-3 pr-4 font-semibold">
                            {page.rival}
                        </th>
                        <th className="w-2/5 py-3 font-semibold">
                            Whisper Money
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {page.rows.map((row) => (
                        <tr
                            key={row.dimension}
                            className="border-b border-[#e3e3e0] align-top dark:border-[#3E3E3A]"
                        >
                            <th scope="row" className="py-4 pr-4 font-medium">
                                {row.dimension}
                            </th>
                            <td className="py-4 pr-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {row.rival}
                            </td>
                            <td className="py-4">{row.whisper}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function Comparison({
    page,
    labels,
    pageLocale,
    alternates,
    canRegister,
}: ComparisonProps) {
    return (
        <>
            <MarketingHead
                title={page.title}
                description={page.description}
                pageLocale={pageLocale}
                alternates={alternates}
            />

            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <Header canRegister={canRegister} />

                <main className="mx-auto flex w-full max-w-3xl flex-col gap-12 px-6 pt-28 pb-12 lg:pt-32 lg:pb-16">
                    <header className="flex flex-col gap-4">
                        <h1 className="text-3xl leading-tight font-semibold sm:text-4xl">
                            {page.heading}
                        </h1>
                        <p className="text-lg leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {page.intro}
                        </p>
                        <MarketingLanguageSwitch
                            pageLocale={pageLocale}
                            alternates={alternates}
                            label={labels.other_language}
                        />
                    </header>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {labels.head_to_head}
                        </h2>
                        <ComparisonTable
                            page={page}
                            dimensionLabel={labels.dimension}
                        />
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {page.narrative_title}
                        </h2>
                        {page.narrative.map((paragraph) => (
                            <p
                                key={paragraph.slice(0, 40)}
                                className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {paragraph}
                            </p>
                        ))}
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {withRival(labels.changes, page.rival)}
                        </h2>
                        <ul className="flex flex-col gap-3">
                            {page.bullets.map((bullet) => (
                                <li key={bullet} className="flex gap-3">
                                    <CheckIcon
                                        aria-hidden
                                        className="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                    />
                                    <span className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                        {bullet}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {withRival(labels.migration, page.rival)}
                        </h2>
                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {page.migration_intro}
                        </p>
                        <ol className="flex flex-col gap-6">
                            {page.migration_steps.map((step, index) => (
                                <li key={step.title} className="flex gap-4">
                                    <span
                                        aria-hidden
                                        className="flex size-8 shrink-0 items-center justify-center rounded-full border border-[#e3e3e0] text-sm font-semibold dark:border-[#3E3E3A]"
                                    >
                                        {index + 1}
                                    </span>
                                    <div className="flex flex-col gap-1">
                                        <h3 className="font-semibold">
                                            {step.title}
                                        </h3>
                                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                            {step.body}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {labels.testimonials}
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {page.testimonials.map((testimonial) => (
                                <Testimonial
                                    key={testimonial.name}
                                    testimonial={testimonial}
                                />
                            ))}
                        </div>
                    </section>

                    <MarketingFooter
                        title={withRival(labels.closing, page.rival)}
                        body={page.closing_body}
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
