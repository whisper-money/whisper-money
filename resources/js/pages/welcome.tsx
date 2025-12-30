import EncryptionVideoPlayer from '@/components/landing/encryption-video-player';
import Header from '@/components/partials/header';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { LEAD_FUNNEL_EVENT_UUID } from '@/lib/constants';
import { trackEvent } from '@/lib/track-event';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { Head, Link, usePage } from '@inertiajs/react';
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
import { useEffect, useRef } from 'react';

const LANDING_IMAGES = [
    {
        key: 'bank-accounts',
        light: '/images/landing/whisper.money_light_3.png',
        dark: '/images/landing/whisper.money_dark_3.png',
        alt: 'Transactions encrypted',
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
                    Most Popular
                </div>
            )}
            {isBestValue && (
                <div className="bg-blue-50 p-3 text-center text-xs font-semibold text-blue-500 uppercase">
                    Best Value
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
                    <h3 className="text-xl font-semibold">{plan.name}</h3>
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
                    Everything you need to manage your finances securely.
                </p>

                <ul className="mt-5 flex-1 space-y-2.5">
                    {plan.features.map((feature) => (
                        <li key={feature} className="flex items-center gap-2.5">
                            <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                            <span className="text-sm">{feature}</span>
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
                        Get Started
                    </Button>
                </Link>
            </div>
        </div>
    );
}

export default function Welcome({
    canRegister = true,
    hideAuthButtons = false,
}: {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
}) {
    const { appUrl, subscriptionsEnabled, pricing } =
        usePage<SharedData>().props;
    const planEntries = Object.entries(pricing.plans);
    const visitTrackedRef = useRef(false);

    useEffect(() => {
        if (visitTrackedRef.current) {
            return;
        }
        visitTrackedRef.current = true;
        trackEvent(LEAD_FUNNEL_EVENT_UUID, {
            step: 'Visit',
        });
    }, []);

    return (
        <>
            <Head title="Whisper Money - The Most Secure Personal Finance App">
                <meta
                    name="description"
                    content="The most secure personal finance app with end-to-end encryption. Track expenses, create budgets, and manage your money privately."
                />
                <meta
                    name="keywords"
                    content="finance app, budgeting, expense tracking, end-to-end encryption, secure finance, personal finance, money management, privacy, encrypted finance app"
                />
                <link rel="canonical" href={appUrl} />

                <meta property="og:site_name" content="Whisper Money" />
                <meta
                    property="og:title"
                    content="Whisper Money - The Most Secure Personal Finance App"
                />
                <meta
                    property="og:description"
                    content="Your financial data stays private with end-to-end encryption. The most secure way to manage your personal finances."
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
                    content="Whisper Money - Secure Personal Finance App"
                />
                <meta property="og:locale" content="en_US" />

                <meta name="twitter:card" content="summary_large_image" />
                <meta
                    name="twitter:title"
                    content="Whisper Money - The Most Secure Personal Finance App"
                />
                <meta
                    name="twitter:description"
                    content="Your financial data stays private with end-to-end encryption. The most secure way to manage your personal finances."
                />
                <meta
                    name="twitter:image"
                    content={`${appUrl}/images/og_whisper_money.png`}
                />
                <meta
                    name="twitter:image:alt"
                    content="Whisper Money - Secure Personal Finance App"
                />

                <script type="application/ld+json">
                    {JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'WebApplication',
                        name: 'Whisper Money',
                        description:
                            'The most secure personal finance app with end-to-end encryption. Track expenses, create budgets, and manage your money privately.',
                        url: appUrl,
                        applicationCategory: 'FinanceApplication',
                        offers: {
                            '@type': 'Offer',
                            price: '0',
                            priceCurrency: 'USD',
                        },
                        featureList: [
                            'End-to-end encryption',
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
                                        E2E Encryption
                                    </span>
                                </span>
                                <h1 className="font-heading max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl leading-tight font-semibold text-transparent drop-shadow-2xl sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight dark:from-[#EDEDEC] dark:to-[#A1A09A]">
                                    The most secure way to understand your
                                    finances
                                </h1>
                                <p className="mb-4 max-w-[840px] text-lg leading-8 font-medium text-[#706f6c] lg:text-xl lg:leading-8 dark:text-[#A1A09A]">
                                    Your financial data stays private with
                                    end-to-end encryption. Track expenses,
                                    create budgets, and achieve your goals—all
                                    while keeping your information completely
                                    secure.
                                </p>
                                <div className="flex w-full max-w-xs flex-col gap-4">
                                    <Link href="/register">
                                        <Button className="text-shadow duration h-14 w-full cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:bg-[#eeeeec] dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:bg-white hover:dark:from-zinc-50 dark:hover:shadow-md">
                                            Get Started
                                        </Button>
                                    </Link>
                                    <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                        Your data is yours alone. Sign up to get
                                        started.
                                    </p>
                                </div>
                            </div>

                            <div className="group relative sm:px-24">
                                {LANDING_IMAGES.map((image, index) => (
                                    <div
                                        key={image.key}
                                        className={cn(
                                            'relative z-10 rotate-[-24deg] skew-y-12 transition-all delay-200 duration-700 ease-in-out group-hover:rotate-[-12deg] group-hover:skew-y-6',
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
                                                        className="h-full w-auto rounded-lg dark:hidden"
                                                    />
                                                    <img
                                                        src={image.dark}
                                                        alt={image.alt}
                                                        className="hidden h-full w-auto rounded-lg dark:block"
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
                                    How End-to-End Encryption Works
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    Your financial data is encrypted on your
                                    device before it ever reaches our servers.
                                </p>
                            </div>

                            <div className="flex flex-col gap-8 sm:flex-row">
                                <div className="flex w-full grow flex-col items-center gap-4 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-6 text-center dark:border-[#3E3E3A] dark:bg-[#161615]">
                                    <EncryptionVideoPlayer
                                        lightSrc="/images/landing_videos/Whisper Money - Light - Encryption.mp4"
                                        darkSrc="/images/landing_videos/Whisper Money - Dark - Encryption.mp4"
                                        className="w-full max-w-4xl"
                                    />
                                    <h3 className="text-xl font-semibold">
                                        Your Private Key
                                    </h3>
                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        A unique encryption key is generated on
                                        your device. Only you have access to
                                        it—we never see or store it.
                                    </p>
                                </div>

                                <div className="grid grow-0 gap-8">
                                    {[
                                        {
                                            icon: LockIcon,
                                            title: 'Client-Side Encryption',
                                            description:
                                                'Your transactions, accounts, and budgets are encrypted on your device before syncing to the cloud.',
                                        },
                                        {
                                            icon: ShieldCheckIcon,
                                            title: 'Zero-Knowledge Architecture',
                                            description:
                                                "We store encrypted data we can't read. Even if our servers were compromised, your data stays secure.",
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
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                    Privacy by Design
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    No AI. No bank connections. Your privacy is
                                    our priority.
                                </p>
                            </div>

                            <div className="grid w-full gap-8 sm:grid-cols-2">
                                <div className="flex flex-col gap-6 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                    <div className="flex items-center gap-4">
                                        <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-red-500/10">
                                            <BrainIcon className="size-6 text-red-600 dark:text-red-400" />
                                        </div>
                                        <h3 className="text-2xl font-semibold">
                                            No AI Snooping
                                        </h3>
                                    </div>
                                    <p className="text-[#706f6c] dark:text-[#A1A09A]">
                                        AI can't help you with your transactions
                                        because they're end-to-end encrypted.
                                        This is intentional—we believe your
                                        financial data should never be fed into
                                        AI systems that you don't control.
                                    </p>
                                </div>

                                <div className="flex flex-col gap-6 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                    <div className="flex items-center gap-4">
                                        <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-red-500/10">
                                            <Building2Icon className="size-6 text-red-600 dark:text-red-400" />
                                        </div>
                                        <h3 className="text-2xl font-semibold">
                                            No Bank Access Required
                                        </h3>
                                    </div>
                                    <p className="text-[#706f6c] dark:text-[#A1A09A]">
                                        We don't need direct access to your bank
                                        accounts. No sharing credentials, no
                                        third-party integrations, no security
                                        risks. You stay in complete control of
                                        your financial data.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold text-balance sm:text-5xl sm:leading-tight">
                                    Import Your Transactions in Seconds
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    Get started quickly with your existing
                                    financial data.
                                </p>
                            </div>

                            <div className="flex w-full max-w-4xl flex-col items-center gap-8 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-8 sm:p-12 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <div className="flex size-20 items-center justify-center rounded-full bg-emerald-500/10">
                                    <FileUpIcon className="size-10 text-emerald-600 dark:text-emerald-400" />
                                </div>

                                <div className="flex flex-col gap-6 text-center">
                                    <h3 className="text-2xl font-semibold">
                                        Lightning-Fast CSV/XLS Import
                                    </h3>
                                    <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                                        Import a year's worth of transactions in
                                        under 10 seconds. Simply export a CSV or
                                        XLS file from your bank and drag it into
                                        Whisper Money. All data is encrypted
                                        locally before upload.
                                    </p>
                                </div>

                                <div className="grid w-full gap-4 sm:grid-cols-3">
                                    <div className="flex items-center gap-3 rounded-lg border border-[#e3e3e0] bg-background p-4 dark:border-[#3E3E3A]">
                                        <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                        <span className="text-sm font-medium">
                                            Export from any bank
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 rounded-lg border border-[#e3e3e0] bg-background p-4 dark:border-[#3E3E3A]">
                                        <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                        <span className="text-sm font-medium">
                                            Encrypted on your device
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 rounded-lg border border-[#e3e3e0] bg-background p-4 dark:border-[#3E3E3A]">
                                        <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                        <span className="text-sm font-medium">
                                            Import in seconds
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-24 md:py-32 dark:border-[#3E3E3A]">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-6 sm:gap-20">
                            <h2 className="max-w-[560px] text-center text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                Everything you need. Nothing you don't.
                            </h2>
                            <div className="grid auto-rows-fr grid-cols-2 gap-0 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <ShieldCheckIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        End-to-end encryption
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Your financial data is encrypted on your
                                        device. Only you can access it.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <TrendingUpIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Smart budgeting
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Create budgets that adapt to your
                                        spending habits and goals.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <BellIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Intelligent insights
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Are you overspending? Know exactly where
                                        you stand.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <PieChartIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Visual insights
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Understand your spending with beautiful
                                        charts and reports.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <SmartphoneIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Works everywhere
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Access your finances on any device,
                                        anytime, anywhere.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <ZapIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Lightning fast
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Built for speed with instant sync and
                                        smooth interactions.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <EyeOffIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Zero tracking
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        We don't track, sell, or share your
                                        data. Ever.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm leading-none font-semibold tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <CodeIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Open source
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-sm text-balance text-[#706f6c] dark:text-[#A1A09A]">
                                        Fully transparent and open source.
                                        Review the code yourself.
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
                                            Choose the plan that works for you
                                        </p>
                                        <h2 className="text-2xl leading-tight font-semibold sm:text-4xl sm:leading-tight">
                                            Simple, transparent pricing
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
                                            🎉 Get a founder discount •{' '}
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <a
                                                            href="https://discord.gg/9UQWZECDDv"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="font-semibold text-[#5865F2] underline-offset-2 hover:underline"
                                                        >
                                                            Join our Discord
                                                        </a>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        You'll receive an
                                                        exclusive promo code via
                                                        DM!
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
                                    Trusted by people who value their privacy
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    Join thousands of users who have taken
                                    control of their finances without
                                    compromising their privacy.
                                </p>
                            </div>
                            <div className="relative flex w-full flex-col items-center justify-center overflow-hidden">
                                <div className="group flex flex-row [gap:var(--gap)] overflow-hidden p-2 [--duration:20s] [--gap:1rem]">
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Sarah M.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @sarahm_finance
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Finally, a finance app that
                                                respects my privacy. The
                                                encryption gives me peace of
                                                mind.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Michael R.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @mike_tech
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The budgeting features are
                                                intuitive and the dark mode is
                                                gorgeous. Best finance app I've
                                                used.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying
                                                about data breaches!
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Sarah M.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @sarahm_finance
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Finally, a finance app that
                                                respects my privacy. The
                                                encryption gives me peace of
                                                mind.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Michael R.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @mike_tech
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The budgeting features are
                                                intuitive and the dark mode is
                                                gorgeous. Best finance app I've
                                                used.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying
                                                about data breaches!
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Sarah M.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @sarahm_finance
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Finally, a finance app that
                                                respects my privacy. The
                                                encryption gives me peace of
                                                mind.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Michael R.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @mike_tech
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The budgeting features are
                                                intuitive and the dark mode is
                                                gorgeous. Best finance app I've
                                                used.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying
                                                about data breaches!
                                            </p>
                                        </div>
                                    </div>
                                    <div className="animate-marquee flex shrink-0 flex-row justify-around [gap:var(--gap)] group-hover:[animation-play-state:paused]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Sarah M.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @sarahm_finance
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Finally, a finance app that
                                                respects my privacy. The
                                                encryption gives me peace of
                                                mind.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Michael R.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @mike_tech
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The budgeting features are
                                                intuitive and the dark mode is
                                                gorgeous. Best finance app I've
                                                used.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying
                                                about data breaches!
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
                                                As a developer, I appreciate the
                                                security architecture. This is
                                                how finance apps should be
                                                built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data
                                                is private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Alex T.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @alext_money
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Clean interface, powerful
                                                features, and zero compromise on
                                                privacy. What more could you
                                                want?
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
                                                As a developer, I appreciate the
                                                security architecture. This is
                                                how finance apps should be
                                                built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data
                                                is private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Alex T.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @alext_money
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Clean interface, powerful
                                                features, and zero compromise on
                                                privacy. What more could you
                                                want?
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
                                                As a developer, I appreciate the
                                                security architecture. This is
                                                how finance apps should be
                                                built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data
                                                is private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Alex T.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @alext_money
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Clean interface, powerful
                                                features, and zero compromise on
                                                privacy. What more could you
                                                want?
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
                                                As a developer, I appreciate the
                                                security architecture. This is
                                                how finance apps should be
                                                built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data
                                                is private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md leading-none font-semibold">
                                                        Alex T.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @alext_money
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Clean interface, powerful
                                                features, and zero compromise on
                                                privacy. What more could you
                                                want?
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
                            © {new Date().getFullYear()} Whisper Money. All
                            rights reserved.
                        </p>
                        <div className="flex gap-6">
                            <Link
                                href="/privacy"
                                className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                Privacy Policy
                            </Link>
                            <Link
                                href="/terms"
                                className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                Terms of Service
                            </Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
