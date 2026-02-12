import InstallAppButton from '@/components/landing/install-app-button';
import Header from '@/components/partials/header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePwaInstall } from '@/hooks/use-pwa-install';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { __ } from '@/utils/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BellIcon,
    BrainIcon,
    Building2Icon,
    CheckIcon,
    CodeIcon,
    EyeOffIcon,
    FileUpIcon,
    LockIcon,
    PieChartIcon,
    ShieldCheckIcon,
    SmartphoneIcon,
    TrendingUpIcon,
    ZapIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

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
    return `/${billingPeriod}`;
}

function LandingPlanCard({
    plan,
    isDefault,
    isBestValue,
}: {
    plan: Plan;
    isDefault: boolean;
    isBestValue: boolean;
    promoEnabled: boolean;
    promoBadge: string;
}) {
    return (
        <div
            className={cn(
                'relative flex flex-col overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]',
                isDefault &&
                    'border-emerald-500 shadow-xl ring-2 ring-emerald-500',
                isBestValue && 'border-blue-500 shadow-xl ring-1 ring-blue-500',
            )}
        >
            {isDefault && (
                <div className="bg-emerald-500 p-3 text-center text-xs font-semibold text-white uppercase">
                    {__('Most Popular')}
                </div>
            )}
            {isBestValue && (
                <div className="bg-blue-50 p-3 text-center text-xs font-semibold text-blue-500 uppercase">
                    {__('Best Value')}
                </div>
            )}
            <div
                className={cn(
                    'absolute -top-12 -right-12 h-32 w-32 rounded-full bg-gradient-to-br from-emerald-500/20 to-teal-500/20 blur-3xl',
                    isBestValue && 'from-blue-500/20 to-cyan-500/20',
                )}
            />

            <div className="relative flex flex-1 flex-col p-6">
                <div className="flex items-center gap-3">
                    <h3 className="text-xl font-semibold">{__(plan.name)}</h3>
                </div>

                <div className="mt-4 flex items-baseline gap-2">
                    {plan.original_price && (
                        <span className="text-xl font-medium text-[#706f6c] line-through decoration-2 dark:text-[#A1A09A]">
                            ${plan.original_price}
                        </span>
                    )}
                    <span className="text-4xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        ${plan.price}
                    </span>
                    <span className="text-base text-[#706f6c] dark:text-[#A1A09A]">
                        {getBillingLabel(plan.billing_period)}
                    </span>
                </div>

                <p className="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    {__(
                        'Everything you need to manage your finances securely.',
                    )}
                </p>

                <ul className="mt-5 flex-1 space-y-2.5">
                    {plan.features.map((feature) => (
                        <li key={feature} className="flex items-center gap-2.5">
                            <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                            <span className="text-sm">{__(feature)}</span>
                        </li>
                    ))}
                </ul>

                <Link href="/register" className="mt-9">
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

export default function Welcome({
    canRegister,
    hideAuthButtons,
}: {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
}) {
    const { appUrl, subscriptionsEnabled, pricing, locale } =
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
                                <h1 className="font-heading max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl leading-tight font-semibold text-transparent drop-shadow-2xl sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight dark:from-[#EDEDEC] dark:to-[#A1A09A]">
                                    {__(
                                        'All your money in one place. No spreadsheets. Private.',
                                    )}
                                </h1>
                                <p className="mb-4 max-w-[840px] text-lg leading-8 font-medium text-[#706f6c] lg:text-xl lg:leading-8 dark:text-[#A1A09A]">
                                    {__(
                                        'Understand your finances and make better decisions without the friction. Track expenses, create budgets, and achieve your goals\u2014all in one place.',
                                    )}
                                </p>
                                <div className="flex w-full max-w-lg flex-col gap-4">
                                    {isMobile ? (
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
                                        {__('Your data stays private. Always.')}
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

                    <section className="px-4 py-12 sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold text-balance sm:text-5xl sm:leading-tight">
                                    {__('Your Data, Your Rules')}
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    {__(
                                        'We built Whisper Money with one principle: your financial data belongs to you.',
                                    )}
                                </p>
                            </div>

                            <div className="grid w-full gap-8 sm:grid-cols-3">
                                {[
                                    {
                                        icon: EyeOffIcon,
                                        title: __('No Third-Party Sharing'),
                                        description: __(
                                            'Your financial data is never shared with advertisers, data brokers, or any third party. Period.',
                                        ),
                                    },
                                    {
                                        icon: ShieldCheckIcon,
                                        title: __('No AI Snooping'),
                                        description: __(
                                            'We never feed your transactions into AI systems. Your spending habits are yours alone.',
                                        ),
                                    },
                                    {
                                        icon: LockIcon,
                                        title: __('You Own Your Data'),
                                        description: __(
                                            'Export or delete your data anytime. We store it securely and never use it for anything other than providing you the service.',
                                        ),
                                    },
                                ].map((item) => (
                                    <div
                                        key={item.title}
                                        className="flex flex-col items-center justify-center gap-4 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-6 text-center dark:border-[#3E3E3A] dark:bg-[#161615]"
                                    >
                                        <div className="flex size-16 items-center justify-center rounded-full bg-emerald-500/10">
                                            <item.icon className="size-8 text-emerald-600 dark:text-emerald-400" />
                                        </div>
                                        <h3 className="text-xl font-semibold">
                                            {item.title}
                                        </h3>
                                        <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {item.description}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                    {__('Privacy by Design')}
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    {__(
                                        'No AI. No bank connections. Your privacy is\n                                    our priority.',
                                    )}
                                </p>
                            </div>

                            <div className="grid w-full gap-8 sm:grid-cols-2">
                                <div className="flex flex-col gap-6 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                    <div className="flex items-center gap-4">
                                        <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-red-500/10">
                                            <BrainIcon className="size-6 text-red-600 dark:text-red-400" />
                                        </div>
                                        <h3 className="text-2xl font-semibold">
                                            {__('No AI Snooping')}
                                        </h3>
                                    </div>
                                    <p className="text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            "We believe your financial data should never be fed into AI systems that you don't control. Your transactions stay between you and Whisper Money.",
                                        )}
                                    </p>
                                </div>

                                <div className="flex flex-col gap-6 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                    <div className="flex items-center gap-4">
                                        <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-red-500/10">
                                            <Building2Icon className="size-6 text-red-600 dark:text-red-400" />
                                        </div>
                                        <h3 className="text-2xl font-semibold">
                                            {__('No Bank Access Required')}
                                        </h3>
                                    </div>
                                    <p className="text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            "We don't need direct access to your bank\n                                        accounts. No sharing credentials, no\n                                        third-party integrations, no security\n                                        risks. You stay in complete control of\n                                        your financial data.",
                                        )}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold text-balance sm:text-5xl sm:leading-tight">
                                    {__('Import Your Transactions in Seconds')}
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    {__(
                                        'Get started quickly with your existing\n                                    financial data.',
                                    )}
                                </p>
                            </div>

                            <div className="flex w-full max-w-4xl flex-col items-center gap-8 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-8 sm:p-12 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <div className="flex size-20 items-center justify-center rounded-full bg-emerald-500/10">
                                    <FileUpIcon className="size-10 text-emerald-600 dark:text-emerald-400" />
                                </div>

                                <div className="flex flex-col gap-6 text-center">
                                    <h3 className="text-2xl font-semibold">
                                        {__('Lightning-Fast CSV/XLS Import')}
                                    </h3>
                                    <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            "Import a year's worth of transactions in under 10 seconds. Simply export a CSV or XLS file from your bank and drag it into Whisper Money.",
                                        )}
                                    </p>
                                </div>

                                <div className="grid w-full gap-4 sm:grid-cols-3">
                                    <div className="flex items-center gap-3 rounded-lg border border-[#e3e3e0] bg-background p-4 dark:border-[#3E3E3A]">
                                        <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                        <span className="text-sm font-medium">
                                            {__('Export from any bank')}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 rounded-lg border border-[#e3e3e0] bg-background p-4 dark:border-[#3E3E3A]">
                                        <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                        <span className="text-sm font-medium">
                                            {__('Secure upload')}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 rounded-lg border border-[#e3e3e0] bg-background p-4 dark:border-[#3E3E3A]">
                                        <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                        <span className="text-sm font-medium">
                                            {__('Import in seconds')}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-24 md:py-32 dark:border-[#3E3E3A]">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-6 sm:gap-20">
                            <h2 className="max-w-[560px] text-center text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                {__("Everything you need. Nothing you don't.")}
                            </h2>
                            <div className="grid auto-rows-fr grid-cols-2 gap-0 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <ShieldCheckIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Privacy-first')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Your financial data stays private. We never share, sell, or use it for anything other than your benefit.',
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <TrendingUpIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Smart budgeting')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Create budgets that adapt to your\n                                        spending habits and goals.',
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <BellIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Intelligent insights')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Are you overspending? Know exactly where\n                                        you stand.',
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <PieChartIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Visual insights')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Understand your spending with beautiful\n                                        charts and reports.',
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <SmartphoneIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Works everywhere')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Access your finances on any device,\n                                        anytime, anywhere.',
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <ZapIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Lightning fast')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Built for speed with instant sync and\n                                        smooth interactions.',
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <EyeOffIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Zero tracking')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            "We don't track, sell, or share your\n                                        data. Ever.",
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <CodeIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        {__('Open source')}
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Fully transparent and open source.\n                                        Review the code yourself.',
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {subscriptionsEnabled &&
                        !hideAuthButtons &&
                        planEntries.length > 0 && (
                            <section
                                id="pricing"
                                className="px-4 py-12 sm:py-24 md:py-32"
                            >
                                <div className="mx-auto flex max-w-6xl flex-col items-center gap-8 sm:gap-12">
                                    <div className="flex flex-col items-center gap-4 text-center">
                                        <p className="max-w-[600px] text-sm tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]">
                                            {__(
                                                'Choose the plan that works for you',
                                            )}
                                        </p>
                                        <h2 className="text-2xl leading-tight font-semibold sm:text-4xl sm:leading-tight">
                                            {__('Simple, transparent pricing')}
                                        </h2>
                                    </div>

                                    <div
                                        className={cn(
                                            'grid w-full gap-6',
                                            planEntries.length === 1 &&
                                                'mx-auto max-w-md',
                                            planEntries.length === 2 &&
                                                'mx-auto max-w-3xl grid-cols-1 sm:grid-cols-2',
                                            planEntries.length >= 3 &&
                                                'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
                                        )}
                                    >
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
                                            />
                                        ))}
                                    </div>

                                    {pricing.promo.enabled && (
                                        <p className="text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                '\uD83C\uDF89 Get a founder discount \u2022',
                                            )}{' '}
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <a
                                                            href="https://https://discord.gg/2WZmDW9QZ8"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="font-semibold text-[#5865F2] underline-offset-2 hover:underline"
                                                        >
                                                            {__(
                                                                'Join our Discord',
                                                            )}
                                                        </a>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        {__(
                                                            "You'll receive an\n                                                        exclusive promo code via\n                                                        DM!",
                                                        )}
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </p>
                                    )}
                                </div>
                            </section>
                        )}

                    <section className="w-full overflow-hidden px-0 py-12 sm:py-24 md:py-32 dark:border-[#3E3E3A]">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-4 px-1 text-center sm:gap-16">
                            <div className="flex flex-col items-center gap-4 px-4 sm:gap-4">
                                <h2 className="max-w-[720px] text-2xl leading-tight font-semibold sm:text-3xl sm:leading-tight">
                                    {__(
                                        'Trusted by people who value their privacy',
                                    )}
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    {__(
                                        'Join thousands of users who have taken\n                                    control of their finances without\n                                    compromising their privacy.',
                                    )}
                                </p>
                            </div>
                            <div className="relative flex w-full flex-col items-center justify-center overflow-hidden">
                                <div className="group flex flex-row [gap:var(--gap)] overflow-hidden p-2 [--duration:20s] [--gap:1rem]">
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Sarah M.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@sarahm_finance')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "Finally, a finance app that respects my privacy. Knowing my data isn't being shared gives me peace of mind.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Michael R.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@mike_tech')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "The budgeting features are\n                                                intuitive and the dark mode is\n                                                gorgeous. Best finance app I've\n                                                used.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Emma L.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@emmalou')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Love that my financial data stays private. No more worrying about who has access to my spending habits!',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Sarah M.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@sarahm_finance')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "Finally, a finance app that respects my privacy. Knowing my data isn't being shared gives me peace of mind.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Michael R.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@mike_tech')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "The budgeting features are\n                                                intuitive and the dark mode is\n                                                gorgeous. Best finance app I've\n                                                used.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Emma L.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@emmalou')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Love that my financial data stays private. No more worrying about who has access to my spending habits!',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Sarah M.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@sarahm_finance')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "Finally, a finance app that respects my privacy. Knowing my data isn't being shared gives me peace of mind.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Michael R.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@mike_tech')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "The budgeting features are\n                                                intuitive and the dark mode is\n                                                gorgeous. Best finance app I've\n                                                used.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Emma L.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@emmalou')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Love that my financial data stays private. No more worrying about who has access to my spending habits!',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Sarah M.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@sarahm_finance')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "Finally, a finance app that respects my privacy. Knowing my data isn't being shared gives me peace of mind.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Michael R.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@mike_tech')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    "The budgeting features are\n                                                intuitive and the dark mode is\n                                                gorgeous. Best finance app I've\n                                                used.",
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Emma L.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@emmalou')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Love that my financial data stays private. No more worrying about who has access to my spending habits!',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div className="group flex flex-row [gap:var(--gap)] overflow-hidden p-2 [--duration:20s] [--gap:1rem]">
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] [animation-direction:reverse] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        David K.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @davidk_dev
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'As a developer, I appreciate the\n                                                security architecture. This is\n                                                how finance apps should be\n                                                built.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Jessica P.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@jessicap')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'The automation rules save me so\n                                                much time. And knowing my data\n                                                is private? Priceless.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Alex T.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@alext_money')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Clean interface, powerful\n                                                features, and zero compromise on\n                                                privacy. What more could you\n                                                want?',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] [animation-direction:reverse] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        David K.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @davidk_dev
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'As a developer, I appreciate the\n                                                security architecture. This is\n                                                how finance apps should be\n                                                built.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Jessica P.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@jessicap')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'The automation rules save me so\n                                                much time. And knowing my data\n                                                is private? Priceless.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Alex T.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@alext_money')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Clean interface, powerful\n                                                features, and zero compromise on\n                                                privacy. What more could you\n                                                want?',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] [animation-direction:reverse] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        David K.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @davidk_dev
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'As a developer, I appreciate the\n                                                security architecture. This is\n                                                how finance apps should be\n                                                built.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Jessica P.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@jessicap')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'The automation rules save me so\n                                                much time. And knowing my data\n                                                is private? Priceless.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Alex T.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@alext_money')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Clean interface, powerful\n                                                features, and zero compromise on\n                                                privacy. What more could you\n                                                want?',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] [animation-direction:reverse] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        David K.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @davidk_dev
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'As a developer, I appreciate the\n                                                security architecture. This is\n                                                how finance apps should be\n                                                built.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Jessica P.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@jessicap')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'The automation rules save me so\n                                                much time. And knowing my data\n                                                is private? Priceless.',
                                                )}
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        {__('Alex T.')}
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        {__('@alext_money')}
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {__(
                                                    'Clean interface, powerful\n                                                features, and zero compromise on\n                                                privacy. What more could you\n                                                want?',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div className="pointer-events-none absolute inset-y-0 left-0 hidden w-1/3 bg-linear-to-r from-background sm:block"></div>
                                <div className="pointer-events-none absolute inset-y-0 right-0 hidden w-1/3 bg-linear-to-l from-background sm:block"></div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="py-8 dark:border-[#3E3E3A]">
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
