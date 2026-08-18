import Header from '@/components/partials/header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { tailwindColorClasses } from '@/components/user-info';
import {
    findComparisonPage,
    type ComparisonPage,
    type ComparisonTestimonial,
} from '@/data/comparison-pages';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { Facehash } from 'facehash';
import { CheckIcon } from 'lucide-react';

const CTA_LABEL = 'Crear mi cuenta gratis';

/**
 * A slot with no real quote yet. Rendering it in production would ship an empty
 * testimonial card, so it is only visible while developing.
 */
function isPending(
    testimonial: ComparisonTestimonial,
): testimonial is { pending: string } {
    return 'pending' in testimonial;
}

function Cta({ canRegister, label }: { canRegister: boolean; label: string }) {
    const { auth } = usePage<SharedData>().props;

    if (auth.user) {
        return (
            <Button asChild size="lg">
                <Link href={dashboard()}>{__('Go to Dashboard')}</Link>
            </Button>
        );
    }

    if (!canRegister) {
        return (
            <Button asChild size="lg">
                <Link href={login()}>{__('Log in')}</Link>
            </Button>
        );
    }

    return (
        <Button asChild size="lg">
            <Link href="/register">{label}</Link>
        </Button>
    );
}

function Testimonial({
    testimonial,
}: {
    testimonial: Exclude<ComparisonTestimonial, { pending: string }>;
}) {
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

function ComparisonTable({ page }: { page: ComparisonPage }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-left text-sm">
                <thead>
                    <tr className="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <th className="w-1/5 py-3 pr-4 font-semibold">
                            Dimensión
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
    slug,
    canRegister,
}: {
    slug: string;
    canRegister: boolean;
}) {
    const { appUrl } = usePage<SharedData>().props;
    const page = findComparisonPage(slug);

    if (!page) {
        return null;
    }

    const url = `${appUrl}/comparativa/${page.slug}`;

    return (
        <>
            <Head title={page.title}>
                <meta name="description" content={page.description} />
                <link rel="canonical" href={url} />
                <meta name="robots" content="index, follow" />
                <meta property="og:site_name" content="Whisper Money" />
                <meta property="og:title" content={page.title} />
                <meta property="og:description" content={page.description} />
                <meta property="og:type" content="article" />
                <meta property="og:url" content={url} />
                <meta property="og:locale" content="es_ES" />
                <meta
                    property="og:image"
                    content={`${appUrl}/images/og_whisper_money.png`}
                />
            </Head>

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
                    </header>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">Cara a cara</h2>
                        <ComparisonTable page={page} />
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-2xl font-semibold">
                            {page.narrativeTitle}
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
                            {`Lo que cambia al pasar de ${page.rival} a Whisper Money`}
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
                            {`Cómo migrar de ${page.rival} a Whisper Money, paso a paso`}
                        </h2>
                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {page.migrationIntro}
                        </p>
                        <ol className="flex flex-col gap-6">
                            {page.migrationSteps.map((step, index) => (
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
                            Lo que dicen quienes ya lo usan
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {page.testimonials.map((testimonial) =>
                                isPending(testimonial) ? (
                                    import.meta.env.DEV ? (
                                        <p
                                            key={testimonial.pending}
                                            className="rounded-2xl border border-dashed border-amber-500 p-6 text-sm text-amber-700 dark:text-amber-400"
                                        >
                                            {testimonial.pending}
                                        </p>
                                    ) : null
                                ) : (
                                    <Testimonial
                                        key={testimonial.name}
                                        testimonial={testimonial}
                                    />
                                ),
                            )}
                        </div>
                    </section>

                    <section className="flex flex-col items-start gap-4 rounded-2xl border border-[#e3e3e0] p-8 dark:border-[#3E3E3A]">
                        <h2 className="text-2xl font-semibold">
                            {`Únete a quienes migraron desde ${page.rival}`}
                        </h2>
                        <p className="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                            {page.closingBody}
                        </p>
                        <div className="flex flex-wrap items-center gap-4">
                            <Cta canRegister={canRegister} label={CTA_LABEL} />
                            <Link
                                href="/#pricing"
                                className="text-sm text-[#706f6c] underline underline-offset-4 hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                            >
                                Ver todos los precios
                            </Link>
                        </div>
                    </section>

                    <Link
                        href="/"
                        className="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                    >
                        {__('← Back to home')}
                    </Link>
                </main>
            </div>
        </>
    );
}
