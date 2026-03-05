import InputError from '@/components/input-error';
import InstallAppButton from '@/components/landing/install-app-button';
import Header from '@/components/partials/header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { usePwaInstall } from '@/hooks/use-pwa-install';
import { cn } from '@/lib/utils';
import { store as storeUserLead } from '@/routes/user-leads';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { CheckIcon, ChevronDownIcon, LockIcon } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

const LANDING_IMAGES = [
    {
        key: 'bank-accounts',
        light: '/images/landing/whisper.money_light_3.png',
        dark: '/images/landing/whisper.money_dark_3.png',
        alt: 'Your transactions at a glance',
        className: 'left-[-24%] group-hover:left-[-32%]',
    },
    {
        key: 'unlock-key',
        light: '/images/landing/whisper.money_light_2.png',
        dark: '/images/landing/whisper.money_dark_2.png',
        alt: 'Manage all your accounts in a single place',
        className: '',
    },
    {
        key: 'transactions',
        light: '/images/landing/whisper.money_light_1.png',
        dark: '/images/landing/whisper.money_dark_1.png',
        alt: 'Analyze your money, how it evolves, and how do you spent it',
        className: 'left-[32%] group-hover:left-[48%]',
    },
] as const;

function getBillingLabel(billingPeriod: string | null): string {
    if (!billingPeriod) {
        return 'one-time';
    }
    return '/month';
}

function FeatureScreenshot({
    light,
    dark,
    alt,
    className,
}: {
    light: string;
    dark: string;
    alt: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950',
                className,
            )}
        >
            <img
                src={light}
                alt={alt}
                className="w-full dark:hidden"
                loading="lazy"
            />
            <img
                src={dark}
                alt={alt}
                className="hidden w-full dark:block"
                loading="lazy"
            />
        </div>
    );
}

function FeatureCard({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]',
                className,
            )}
        >
            {children}
        </div>
    );
}

function FreePlanCard({ planFeatures }: { planFeatures: string[] }) {
    return (
        <div className="flex flex-col overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]">
            <div className="flex flex-1 flex-col p-6 pt-2 sm:pt-12">
                <h3 className="text-lg font-semibold">{__('Free')}</h3>

                <div className="mt-3 flex items-baseline gap-2">
                    <span className="text-4xl font-bold tracking-tight">
                        {__('Free')}
                    </span>
                    <span className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        {__('forever')}
                    </span>
                </div>

                <p className="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    {__(
                        'Get started at no cost. No bank connections included.',
                    )}
                </p>

                <div className="my-5 h-px bg-[#e3e3e0] dark:bg-[#3E3E3A]" />

                <ul className="flex-1 space-y-2.5">
                    {planFeatures.map((feature) => (
                        <li key={feature} className="flex items-center gap-2.5">
                            <CheckIcon className="size-4 shrink-0 text-[#1b1b18] dark:text-[#EDEDEC]" />
                            <span className="text-sm">{__(feature)}</span>
                        </li>
                    ))}
                </ul>

                <Link href="/register" className="mt-8">
                    <Button
                        className="w-full cursor-pointer border-[#e3e3e0] bg-transparent py-5 text-base text-[#1b1b18] shadow-sm transition-all hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1f1f1e]"
                        variant="outline"
                    >
                        {__('Get Started Free')}
                    </Button>
                </Link>
            </div>
        </div>
    );
}

function LandingPlanCard({
    plan,
    isDefault,
    isBestValue,
    currency,
    locale,
}: {
    plan: Plan;
    isDefault: boolean;
    isBestValue: boolean;
    promoEnabled: boolean;
    promoBadge: string;
    currency: string;
    locale: string;
}) {
    const { features } = usePage<SharedData>().props;
    const monthlyEquivalent =
        plan.billing_period === 'year' ? plan.price / 12 : plan.price;

    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]',
                isDefault && 'ring-2 ring-[#1b1b18] dark:ring-[#EDEDEC]',
            )}
        >
            {(isDefault || isBestValue) && (
                <div
                    className={cn(
                        'px-6 pt-6 text-xs font-semibold uppercase',
                        isDefault && 'text-[#1b1b18]/75 dark:text-[#aaa]',
                        isBestValue &&
                            !isDefault &&
                            'text-[#706f6c] dark:text-[#A1A09A]',
                    )}
                >
                    {isDefault ? __('Most Popular') : __('Best Value')}
                </div>
            )}

            <div
                className={cn(
                    'flex flex-1 flex-col p-6 pt-2',
                    !isDefault && !isBestValue && 'sm:pt-12',
                )}
            >
                <h3 className="text-lg font-semibold">{__(plan.name)}</h3>

                <div className="mt-3 flex items-baseline gap-2">
                    {plan.original_price && (
                        <span className="text-lg font-medium text-[#706f6c] line-through dark:text-[#A1A09A]">
                            {formatCurrency(
                                (plan.billing_period === 'year'
                                    ? plan.original_price / 12
                                    : plan.original_price) * 100,
                                currency,
                                locale,
                            )}
                        </span>
                    )}
                    <span className="text-4xl font-bold tracking-tight">
                        {formatCurrency(
                            monthlyEquivalent * 100,
                            currency,
                            locale,
                        )}
                    </span>
                    <span className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        {getBillingLabel(plan.billing_period)}
                    </span>
                </div>

                {plan.billing_period === 'year' && (
                    <p className="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        {__('Billed annually at')}{' '}
                        {formatCurrency(plan.price * 100, currency, locale)}
                    </p>
                )}

                <p className="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    {__(
                        'Everything you need to manage your finances securely.',
                    )}
                </p>

                <div className="my-5 h-px bg-[#e3e3e0] dark:bg-[#3E3E3A]" />

                <ul className="flex-1 space-y-2.5">
                    {(features['open-banking']
                        ? [__('Connect bank accounts'), ...plan.features]
                        : plan.features
                    ).map((feature) => (
                        <li key={feature} className="flex items-center gap-2.5">
                            <CheckIcon className="size-4 shrink-0 text-[#1b1b18] dark:text-[#EDEDEC]" />
                            <span className="text-sm">{__(feature)}</span>
                        </li>
                    ))}
                </ul>

                <Link href="/register" className="mt-8">
                    <Button
                        className={cn(
                            'w-full cursor-pointer py-5 text-base shadow-sm transition-all',
                            isDefault
                                ? 'bg-gradient-to-t from-zinc-700 to-zinc-900 text-white hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] hover:dark:from-zinc-50'
                                : 'border-[#e3e3e0] bg-transparent text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1f1f1e]',
                        )}
                        variant={isDefault ? 'default' : 'outline'}
                    >
                        {__('Get Started')}
                    </Button>
                </Link>
            </div>
        </div>
    );
}

function FaqItem({ question, answer }: { question: string; answer: string }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
            <button
                type="button"
                className="flex w-full cursor-pointer items-center justify-between py-5 text-left"
                onClick={() => setOpen(!open)}
            >
                <span className="text-base font-medium">{question}</span>
                <ChevronDownIcon
                    className={cn(
                        'size-5 shrink-0 text-[#706f6c] transition-transform duration-200 dark:text-[#A1A09A]',
                        open && 'rotate-180',
                    )}
                />
            </button>
            <div
                className={cn(
                    'grid transition-all duration-200',
                    open ? 'grid-rows-[1fr] pb-5' : 'grid-rows-[0fr]',
                )}
            >
                <div className="overflow-hidden">
                    <p className="text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                        {answer}
                    </p>
                </div>
            </div>
        </div>
    );
}

function WaitlistForm() {
    const [referrerCode, setReferrerCode] = useState('');
    const { locale } = usePage<SharedData>().props;

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const ref = params.get('ref');
        if (ref) {
            setReferrerCode(ref);
        }
    }, []);

    return (
        <Form
            {...storeUserLead.form()}
            className="flex w-full flex-col gap-3"
            disableWhileProcessing
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="referrer_code"
                        value={referrerCode}
                    />
                    <input type="hidden" name="locale" value={locale} />
                    <div className="flex w-full flex-col gap-1.5">
                        <div className="flex w-full flex-row gap-2">
                            <Input
                                type="email"
                                name="email"
                                required
                                autoComplete="email"
                                placeholder={__('Your email address')}
                                className="h-14 flex-1 text-base"
                            />
                            <Button
                                type="submit"
                                className="text-shadow h-14 shrink-0 cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 px-6 text-base text-white shadow-sm transition-all duration-200 hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:from-zinc-50"
                            >
                                {processing && <Spinner />}
                                {__('Join Waitlist')}
                            </Button>
                        </div>
                        <InputError message={errors.email} />
                    </div>
                </>
            )}
        </Form>
    );
}

export default function Welcome({
    canRegister,
    hideAuthButtons,
}: {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
}) {
    const { appUrl, subscriptionsEnabled, pricing, locale, features } =
        usePage<SharedData>().props;
    const planEntries = Object.entries(pricing.plans);
    const { isMobile } = usePwaInstall();

    // Handle localStorage for language preference
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        const langParam = urlParams.get('lang');

        if (langParam && ['en', 'es'].includes(langParam)) {
            // Store the preference in localStorage
            localStorage.setItem('whisper_landing_locale', langParam);
        } else {
            // No query param - check if we have a stored preference
            const storedLocale = localStorage.getItem('whisper_landing_locale');

            if (
                storedLocale &&
                storedLocale !== locale &&
                ['en', 'es'].includes(storedLocale)
            ) {
                // Redirect to stored preference
                window.location.href = `/?lang=${storedLocale}`;
                return;
            } else if (!storedLocale && locale) {
                // First visit - store the detected locale from session/header
                localStorage.setItem('whisper_landing_locale', locale);
            }
        }
    }, [locale]);

    const [isPwa] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            ('standalone' in navigator &&
                (navigator as Navigator & { standalone: boolean }).standalone)
        );
    });

    useEffect(() => {
        if (isPwa) {
            router.visit('/dashboard');
        }
    }, [isPwa]);

    if (isPwa) {
        return (
            <div className="flex h-screen items-center justify-center">
                <Spinner className="size-8" />
            </div>
        );
    }

    return (
        <>
            <Head
                title={__(
                    'Whisper Money - The Most Secure Personal Finance App',
                )}
            >
                <meta
                    name="description"
                    content={__(
                        'The most secure privacy-first personal finance app. Track expenses, create budgets, and manage your money privately.',
                    )}
                />

                <meta
                    name="keywords"
                    content={__(
                        'finance app, budgeting, expense tracking, secure finance, personal finance, money management, privacy, privacy-first finance app',
                    )}
                />

                <link rel="canonical" href={appUrl} />

                <meta property="og:site_name" content="Whisper Money" />
                <meta
                    property="og:title"
                    content={__(
                        'Whisper Money - The Most Secure Personal Finance App',
                    )}
                />

                <meta
                    property="og:description"
                    content={__(
                        'Your financial data stays private. The most secure way to manage your personal finances.',
                    )}
                />

                <meta property="og:type" content="website" />
                <meta property="og:url" content={appUrl} />
                <meta
                    property="og:image"
                    content={`${appUrl}/images/og_whisper_money.png`}
                />

                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta
                    property="og:image:alt"
                    content={__('Whisper Money - Secure Personal Finance App')}
                />

                <meta property="og:locale" content={__('en_US')} />

                <meta name="twitter:card" content={__('summary_large_image')} />
                <meta
                    name="twitter:title"
                    content={__(
                        'Whisper Money - The Most Secure Personal Finance App',
                    )}
                />

                <meta
                    name="twitter:description"
                    content={__(
                        'Your financial data stays private. The most secure way to manage your personal finances.',
                    )}
                />

                <meta
                    name="twitter:image"
                    content={`${appUrl}/images/og_whisper_money.png`}
                />

                <meta
                    name="twitter:image:alt"
                    content={__('Whisper Money - Secure Personal Finance App')}
                />

                <script type="application/ld+json">
                    {JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'WebApplication',
                        name: 'Whisper Money',
                        description:
                            'The most secure privacy-first personal finance app. Track expenses, create budgets, and manage your money privately.',
                        url: appUrl,
                        applicationCategory: 'FinanceApplication',
                        featureList: [
                            'Privacy-first design',
                            'Smart budgeting',
                            'Expense tracking',
                            'Visual insights',
                            'Zero tracking',
                            'Open source',
                        ],
                    })}
                </script>
            </Head>
            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <Header
                    canRegister={canRegister}
                    hideAuthButtons={hideAuthButtons}
                />

                <main className="flex flex-1 flex-col">
                    <section className="relative w-full overflow-hidden px-6 py-28 sm:py-32 md:py-40">
                        <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-12">
                            <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col items-start gap-6 sm:gap-8">
                                <span className="inline-flex items-center gap-2 rounded-full border border-[#e3e3e0] px-2.5 py-1 text-[0.8rem] font-medium dark:border-[#3E3E3A]">
                                    <LockIcon className="size-3.5 opacity-75" />
                                    <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                        {__('Private & Secure')}
                                    </span>
                                </span>
                                <h1 className="font-heading max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl leading-tight font-semibold text-balance text-transparent drop-shadow-2xl sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight dark:from-[#EDEDEC] dark:to-[#A1A09A]">
                                    {__(
                                        'All your money in one place. No spreadsheets. Private.',
                                    )}
                                </h1>
                                <p className="mb-4 max-w-[840px] text-lg leading-8 font-medium text-balance text-[#706f6c] lg:text-xl lg:leading-8 dark:text-[#A1A09A]">
                                    {__(
                                        'Understand your finances and make better decisions without the friction. Track expenses, create budgets, and achieve your goals\u2014all in one place.',
                                    )}
                                </p>
                                <div className="flex w-full max-w-lg flex-col gap-4">
                                    {hideAuthButtons ? (
                                        <WaitlistForm />
                                    ) : isMobile ? (
                                        <InstallAppButton />
                                    ) : (
                                        <div className="flex w-full flex-row gap-4">
                                            <Link
                                                href="/register"
                                                className="w-full"
                                            >
                                                <Button className="text-shadow duration h-14 w-full cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:bg-[#eeeeec] dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:bg-white hover:dark:from-zinc-50 dark:hover:shadow-md">
                                                    {__('Get Started')}
                                                </Button>
                                            </Link>
                                            <Link href="/login?demo=1">
                                                <Button
                                                    variant={'secondary'}
                                                    size={'lg'}
                                                    className="h-14"
                                                >
                                                    {__('Check Demo')}
                                                </Button>
                                            </Link>
                                        </div>
                                    )}
                                    <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                        {hideAuthButtons
                                            ? __(
                                                  "Join the waiting list. We'll let you know when you're in.",
                                              )
                                            : __(
                                                  'Your data stays private. Always.',
                                              )}
                                    </p>
                                </div>
                            </div>

                            <div className="group relative sm:px-24">
                                {LANDING_IMAGES.map((image, index) => (
                                    <div
                                        key={image.key}
                                        className={cn(
                                            'relative z-10 transition-all delay-200 duration-700 ease-in-out',
                                            'rotate-[-24deg] skew-y-12 group-hover:rotate-[-12deg] group-hover:skew-y-6',
                                            'max-sm:rotate-[-16deg] max-sm:skew-y-8 max-sm:group-hover:rotate-[-8deg] max-sm:group-hover:skew-y-4',
                                            index < LANDING_IMAGES.length - 1 &&
                                                'h-[24px]',
                                            image.className,
                                        )}
                                    >
                                        <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                            <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                                <div className="rounded-lg border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                    <img
                                                        src={image.light}
                                                        alt={image.alt}
                                                        className="w-full rounded-lg dark:hidden"
                                                    />

                                                    <img
                                                        src={image.dark}
                                                        alt={image.alt}
                                                        className="hidden w-full rounded-lg dark:block"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}

                                <div
                                    data-slot="glow"
                                    className="animate-appear-zoom absolute top-[50%] mt-32 w-full opacity-0 delay-2000 lg:mt-4"
                                >
                                    <div className="absolute left-1/2 h-[256px] w-[60%] -translate-x-1/2 -translate-y-1/2 scale-[2.5] rounded-[50%] bg-radial from-[#1b1b18]/50 from-10% to-[#1b1b18]/0 to-60% opacity-20 sm:h-[512px] dark:from-[#EDEDEC]/50 dark:to-[#EDEDEC]/0 dark:opacity-100"></div>
                                    <div className="absolute left-1/2 h-[128px] w-[40%] -translate-x-1/2 -translate-y-1/2 scale-200 rounded-[50%] bg-radial from-[#1b1b18]/30 from-10% to-[#1b1b18]/0 to-60% opacity-20 sm:h-[256px] dark:from-[#EDEDEC]/30 dark:to-[#EDEDEC]/0 dark:opacity-100"></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-6 px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto grid max-w-7xl gap-6 sm:grid-cols-3">
                            <FeatureCard>
                                <div className="p-2">
                                    <FeatureScreenshot
                                        light="/images/landing/features/accounts_light.png"
                                        dark="/images/landing/features/accounts_dark.png"
                                        alt={__(
                                            'All your accounts at a glance',
                                        )}
                                    />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('All Your Accounts')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'See every account in one place. Track balances, monitor changes, and always know where you stand.',
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>

                            <FeatureCard>
                                <div className="p-2">
                                    <FeatureScreenshot
                                        light="/images/landing/features/transactions_light.png"
                                        dark="/images/landing/features/transactions_dark.png"
                                        alt={__('Every transaction tracked')}
                                    />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('Every Transaction')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Search, filter, and categorize with ease. Understand exactly where your money goes.',
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>

                            <FeatureCard>
                                <div className="p-2">
                                    <FeatureScreenshot
                                        light="/images/landing/features/privacy_light.png"
                                        dark="/images/landing/features/privacy_dark.png"
                                        alt={__(
                                            'Your data encrypted and private',
                                        )}
                                    />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('Your Data, Your Rules')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'No third-party sharing, no AI snooping. Your financial data belongs to you and only you.',
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>
                        </div>

                        <div className="mx-auto max-w-7xl">
                            <FeatureCard>
                                <div className="grid items-center gap-0 sm:grid-cols-2">
                                    <div className="p-8 sm:p-12">
                                        <h2 className="text-3xl leading-tight font-semibold sm:text-4xl sm:leading-tight">
                                            {__('Import in Seconds')}
                                        </h2>
                                        <p className="mt-4 text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                "Export a CSV or XLS from your bank and drag it in. A year's worth of transactions imported in under 10 seconds.",
                                            )}
                                        </p>
                                        <ul className="mt-6 space-y-3">
                                            <li className="flex items-center gap-2.5">
                                                <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                                <span className="text-sm">
                                                    {__('Export from any bank')}
                                                </span>
                                            </li>
                                            <li className="flex items-center gap-2.5">
                                                <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                                <span className="text-sm">
                                                    {__('Secure upload')}
                                                </span>
                                            </li>
                                            <li className="flex items-center gap-2.5">
                                                <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                                <span className="text-sm">
                                                    {__(
                                                        'Automatic categorization',
                                                    )}
                                                </span>
                                            </li>
                                        </ul>
                                    </div>
                                    <div className="p-2">
                                        <FeatureScreenshot
                                            light="/images/landing/features/import_light.png"
                                            dark="/images/landing/features/import_dark.png"
                                            alt={__(
                                                'Import transactions in seconds',
                                            )}
                                            className="aspect-auto min-h-[320px]"
                                        />
                                    </div>
                                </div>
                            </FeatureCard>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto flex max-w-7xl flex-col gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                    {__('Smart Budgets')}
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    {__(
                                        'Create budgets that adapt to your spending habits and help you reach your goals.',
                                    )}
                                </p>
                            </div>

                            <div className="grid gap-6 sm:grid-cols-3">
                                <FeatureCard>
                                    <div className="p-2">
                                        <FeatureScreenshot
                                            light="/images/landing/features/budgets_light.png"
                                            dark="/images/landing/features/budgets_dark.png"
                                            alt={__('Set your budget goals')}
                                        />
                                    </div>
                                    <div className="p-6 pt-4">
                                        <h3 className="text-xl font-semibold">
                                            {__('Set Your Goals')}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'Define monthly budgets by category. Know exactly how much you can spend.',
                                            )}
                                        </p>
                                    </div>
                                </FeatureCard>

                                <FeatureCard>
                                    <div className="p-2">
                                        <FeatureScreenshot
                                            light="/images/landing/features/budget_detail_light.png"
                                            dark="/images/landing/features/budget_detail_dark.png"
                                            alt={__(
                                                'Track your budget progress',
                                            )}
                                        />
                                    </div>
                                    <div className="p-6 pt-4">
                                        <h3 className="text-xl font-semibold">
                                            {__('Track Progress')}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'See where you stand in real-time. Visual progress bars show spending vs. budget.',
                                            )}
                                        </p>
                                    </div>
                                </FeatureCard>

                                <FeatureCard>
                                    <div className="p-2">
                                        <FeatureScreenshot
                                            light="/images/landing/features/budget_edit_light.png"
                                            dark="/images/landing/features/budget_edit_dark.png"
                                            alt={__(
                                                'Budget insights and alerts',
                                            )}
                                        />
                                    </div>
                                    <div className="p-6 pt-4">
                                        <h3 className="text-xl font-semibold">
                                            {__('Stay on Track')}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                "Get notified when you're close to your limit. Never overspend again.",
                                            )}
                                        </p>
                                    </div>
                                </FeatureCard>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto max-w-7xl">
                            <FeatureCard>
                                <div className="grid items-center gap-0 sm:grid-cols-2">
                                    <div className="p-2">
                                        <FeatureScreenshot
                                            light="/images/landing/features/cashflow_light.png"
                                            dark="/images/landing/features/cashflow_dark.png"
                                            alt={__('Cashflow visualization')}
                                            className="aspect-auto min-h-[320px]"
                                        />
                                    </div>
                                    <div className="p-8 sm:p-12">
                                        <h2 className="text-3xl leading-tight font-semibold sm:text-4xl sm:leading-tight">
                                            {__('Cashflow at a Glance')}
                                        </h2>
                                        <p className="mt-4 text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'Visualize your money flow over time. See income vs. expenses and spot trends before they become problems.',
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </FeatureCard>
                        </div>
                    </section>

                    <section className="w-full overflow-hidden px-0 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 px-4 text-center sm:gap-12">
                            <div className="flex flex-col items-center gap-4">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold text-balance sm:text-4xl sm:leading-tight">
                                    {__(
                                        'Trusted by people who value their privacy',
                                    )}
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                    {__(
                                        'Join thousands of users who have taken control of their finances without compromising their privacy.',
                                    )}
                                </p>
                            </div>

                            <div className="relative w-full">
                                <div className="group flex flex-row [gap:var(--gap)] overflow-hidden p-2 [--duration:25s] [--gap:1rem]">
                                    {[0, 1].map((copy) => (
                                        <div
                                            key={copy}
                                            className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]"
                                        >
                                            {[
                                                {
                                                    name: __('Sarah M.'),
                                                    handle: '@sarahm_finance',
                                                    text: __(
                                                        "Finally, a finance app that respects my privacy. Knowing my data isn't being shared gives me peace of mind.",
                                                    ),
                                                },
                                                {
                                                    name: __('Michael R.'),
                                                    handle: '@mike_tech',
                                                    text: __(
                                                        "The budgeting features are intuitive and the dark mode is gorgeous. Best finance app I've used.",
                                                    ),
                                                },
                                                {
                                                    name: __('Emma L.'),
                                                    handle: '@emmalou',
                                                    text: __(
                                                        'Love that my financial data stays private. No more worrying about who has access to my spending habits!',
                                                    ),
                                                },
                                                {
                                                    name: __('David K.'),
                                                    handle: '@davidk_dev',
                                                    text: __(
                                                        'As a developer, I appreciate the security architecture. This is how finance apps should be built.',
                                                    ),
                                                },
                                                {
                                                    name: __('Jessica P.'),
                                                    handle: '@jessicap',
                                                    text: __(
                                                        'The automation rules save me so much time. And knowing my data is private? Priceless.',
                                                    ),
                                                },
                                                {
                                                    name: __('Alex T.'),
                                                    handle: '@alext_money',
                                                    text: __(
                                                        'Clean interface, powerful features, and zero compromise on privacy. What more could you want?',
                                                    ),
                                                },
                                            ].map((testimonial) => (
                                                <div
                                                    key={`${copy}-${testimonial.handle}`}
                                                    className="flex w-[300px] shrink-0 flex-col rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-6 text-start shadow-sm sm:w-[360px] dark:border-[#3E3E3A] dark:bg-[#161615]"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex size-10 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                            {testimonial.name.charAt(
                                                                0,
                                                            )}
                                                        </div>
                                                        <div className="flex flex-col items-start">
                                                            <h3 className="text-sm leading-none font-semibold">
                                                                {
                                                                    testimonial.name
                                                                }
                                                            </h3>
                                                            <p className="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                                                {
                                                                    testimonial.handle
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <p className="mt-4 text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                                        {testimonial.text}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                                </div>

                                <div className="pointer-events-none absolute inset-y-0 left-0 hidden w-1/6 bg-linear-to-r from-[#FDFDFC] sm:block dark:from-[#0a0a0a]"></div>
                                <div className="pointer-events-none absolute inset-y-0 right-0 hidden w-1/6 bg-linear-to-l from-[#FDFDFC] sm:block dark:from-[#0a0a0a]"></div>
                            </div>
                        </div>
                    </section>

                    {subscriptionsEnabled &&
                        !hideAuthButtons &&
                        planEntries.length > 0 && (
                            <section
                                id="pricing"
                                className="px-4 py-12 sm:py-16 md:py-20"
                            >
                                <div className="mx-auto flex max-w-5xl flex-col items-center gap-8 sm:gap-12">
                                    <div className="flex flex-col items-center gap-4 text-center">
                                        <h2 className="text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                            {__('Simple, transparent pricing')}
                                        </h2>
                                        <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                            {__(
                                                'Choose the plan that works for you. No hidden fees.',
                                            )}
                                        </p>
                                    </div>

                                    <div
                                        className={cn(
                                            'grid w-full gap-6',
                                            planEntries.length +
                                                (features['open-banking']
                                                    ? 1
                                                    : 0) ===
                                                1 && 'mx-auto max-w-md',
                                            planEntries.length +
                                                (features['open-banking']
                                                    ? 1
                                                    : 0) ===
                                                2 &&
                                                'mx-auto max-w-3xl grid-cols-1 sm:grid-cols-2',
                                            planEntries.length +
                                                (features['open-banking']
                                                    ? 1
                                                    : 0) >=
                                                3 &&
                                                'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
                                        )}
                                    >
                                        {features['open-banking'] && (
                                            <FreePlanCard
                                                planFeatures={planEntries[0][1].features.filter(
                                                    (f) =>
                                                        f !==
                                                        'Priority support',
                                                )}
                                            />
                                        )}
                                        {planEntries.map(([key, plan]) => (
                                            <LandingPlanCard
                                                key={key}
                                                plan={plan}
                                                isDefault={
                                                    key === pricing.defaultPlan
                                                }
                                                isBestValue={
                                                    key ===
                                                    pricing.bestValuePlan
                                                }
                                                promoEnabled={
                                                    pricing.promo.enabled
                                                }
                                                promoBadge={pricing.promo.badge}
                                                currency={pricing.currency}
                                                locale={locale}
                                            />
                                        ))}
                                    </div>
                                </div>
                            </section>
                        )}

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto max-w-3xl">
                            <div className="mb-8 flex flex-col items-center gap-4 text-center sm:mb-12">
                                <h2 className="text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                    {__('Frequently Asked Questions')}
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                    {__(
                                        'Everything you need to know before getting started.',
                                    )}
                                </p>
                            </div>

                            <div className="rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] px-6 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <FaqItem
                                    question={__(
                                        'How is my financial data kept private?',
                                    )}
                                    answer={__(
                                        "Your data is stored securely and never shared with third parties. We don't use your financial data for AI training, advertising, or any purpose other than providing you the service.",
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'Do you connect directly to my bank?',
                                    )}
                                    answer={__(
                                        'No. We never ask for your bank credentials. You import transactions by exporting a CSV or XLS file from your bank and uploading it to Whisper Money. This keeps your bank account secure.',
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'Can I export or delete my data?',
                                    )}
                                    answer={__(
                                        'Absolutely. You own your data. You can export all your financial data at any time, and you can permanently delete your account and all associated data whenever you want.',
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'What file formats are supported for import?',
                                    )}
                                    answer={__(
                                        'We support CSV and XLS files. Most banks allow you to export your transaction history in one of these formats. The import process automatically maps columns and categorizes transactions.',
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'Is Whisper Money open source?',
                                    )}
                                    answer={__(
                                        'Yes! Whisper Money is fully open source. You can review the code, suggest improvements, or even self-host it. Transparency is a core part of our privacy commitment.',
                                    )}
                                />
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto max-w-7xl">
                            <div className="flex flex-col items-center gap-6 px-6 py-12 text-center sm:px-12 sm:py-16 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <h2 className="max-w-[600px] text-3xl leading-tight font-semibold text-balance sm:text-4xl sm:leading-tight">
                                    {__(
                                        'Ready to take control of your finances?',
                                    )}
                                </h2>
                                <p className="max-w-[480px] text-balance text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                    {__(
                                        'Start managing your money privately. No credit card required.',
                                    )}
                                </p>
                                <div className="flex flex-col gap-3 sm:flex-row">
                                    <Link href="/register">
                                        <Button className="h-12 cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 px-8 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] hover:dark:from-zinc-50">
                                            {__('Get Started for Free')}
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="py-8 lg:mt-12 dark:border-[#3E3E3A]">
                    <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-4 px-6 text-sm text-[#706f6c] sm:flex-row lg:px-8 dark:text-[#A1A09A]">
                        <p>
                            © {new Date().getFullYear()}
                            {__(
                                'Whisper Money. All\n                            rights reserved.',
                            )}
                        </p>
                        <div className="flex gap-6">
                            <Link
                                href="/privacy"
                                className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                {__('Privacy Policy')}
                            </Link>
                            <Link
                                href="/terms"
                                className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                {__('Terms of Service')}
                            </Link>
                            <a
                                href={`/?lang=${locale === 'es' ? 'en' : 'es'}`}
                                className="cursor-pointer hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                {locale === 'es' ? 'English' : 'Español'}
                            </a>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
