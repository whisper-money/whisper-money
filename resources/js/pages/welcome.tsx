import { dashboard } from '@/routes';
import { store } from '@/routes/user-leads';
import { type SharedData } from '@/types';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import {
    LockIcon,
    ShieldCheckIcon,
    TrendingUpIcon,
    BellIcon,
    PieChartIcon,
    SmartphoneIcon,
    ZapIcon,
    EyeOffIcon,
    BirdIcon,
    CodeIcon,
    Github,
    GithubIcon,
    LucideGithub,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { trackEvent } from '@/lib/track-event';
import { LEAD_FUNNEL_EVENT_UUID } from '@/lib/constants';
import { Separator } from '@radix-ui/react-separator';

export default function Welcome({
    canRegister = true,
    hideAuthButtons = false,
}: {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
}) {
    const { auth, appUrl } = usePage<SharedData>().props;
    const emailInputRef = useRef<HTMLInputElement>(null);
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

                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
                <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Code:ital,wght@0,300..800;1,300..800&family=Stack+Sans+Text:wght@200..700&display=swap" rel="stylesheet" />

                <meta property="og:site_name" content="Whisper Money" />
                <meta property="og:title" content="Whisper Money - The Most Secure Personal Finance App" />
                <meta
                    property="og:description"
                    content="Your financial data stays private with end-to-end encryption. The most secure way to manage your personal finances."
                />
                <meta property="og:type" content="website" />
                <meta property="og:url" content={appUrl} />
                <meta property="og:image" content={`${appUrl}/images/og_whisper_money.png`} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta property="og:image:alt" content="Whisper Money - Secure Personal Finance App" />
                <meta property="og:locale" content="en_US" />

                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:title" content="Whisper Money - The Most Secure Personal Finance App" />
                <meta
                    name="twitter:description"
                    content="Your financial data stays private with end-to-end encryption. The most secure way to manage your personal finances."
                />
                <meta name="twitter:image" content={`${appUrl}/images/og_whisper_money.png`} />
                <meta name="twitter:image:alt" content="Whisper Money - Secure Personal Finance App" />

                <script type="application/ld+json">
                    {JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'WebApplication',
                        name: 'Whisper Money',
                        description: 'The most secure personal finance app with end-to-end encryption. Track expenses, create budgets, and manage your money privately.',
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
                <header className="w-full fade-bottom bg-background/5 fixed top-0 z-50 backdrop-blur-lg">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-2 py-4 lg:py-6">
                        <div className="flex items-center gap-4 font-mono">
                            <BirdIcon className="size-5 text-[#1b1b18] dark:text-[#EDEDEC]" />
                            <span className='font-medium'>Whisper Money</span>
                        </div>
                        <nav className="flex items-center gap-4">
                            <a
                                href="https://github.com/whisper-money/whisper-money"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button variant={'ghost'} className='cursor-pointer opacity-70 hover:opacity-100 transition-all duration-200'>
                                    <Github className="size-5" />
                                    Github
                                </Button>
                            </a>
                            {!hideAuthButtons && (
                                <>
                                    <Separator orientation="vertical"
                                        className="data-[orientation=vertical]:bg-border data-[orientation=vertical]:w-[1px] data-[orientation=vertical]:h-6"
                                    />
                                    {auth.user ? (
                                        <Link
                                            href={dashboard()}
                                        >
                                            <Button className='cursor-pointer'>
                                                Dashboard
                                            </Button>
                                        </Link>
                                    ) : (
                                        <>
                                            <Link
                                                href="/login"
                                            >
                                                <Button variant={'ghost'} className='cursor-pointer'>
                                                    Log in
                                                </Button>
                                            </Link>
                                            {canRegister && (
                                                <Link
                                                    href="/register"
                                                >
                                                    <Button variant='default' className='cursor-pointer'>
                                                        Register
                                                    </Button>
                                                </Link>
                                            )}
                                        </>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="flex flex-1 flex-col">
                    <section className="relative w-full overflow-hidden px-6 py-24 sm:py-32 md:py-40">
                        <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-12">
                            <div className="max-w-5xl w-full mx-auto relative z-10 flex flex-col items-start gap-2 sm:gap-8">
                                <span className="inline-flex items-center gap-2 rounded-full border border-[#e3e3e0] py-1 px-2.5 text-[0.8rem] font-medium dark:border-[#3E3E3A]">
                                    <LockIcon className='size-3.5 opacity-75' />
                                    <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                        Military Grade Encryption
                                    </span>
                                </span>
                                <h1 className="max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl font-semibold leading-tight text-transparent drop-shadow-2xl dark:from-[#EDEDEC] dark:to-[#A1A09A] sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight font-heading">
                                    The most secure way to understand your finances
                                </h1>
                                <p className="max-w-[840px] mb-4 text-lg leading-8 font-medium text-[#706f6c] dark:text-[#A1A09A] lg:text-xl lg:leading-8">
                                    Your financial data stays private with
                                    end-to-end encryption. Track expenses,
                                    create budgets, and achieve your goals—all
                                    while keeping your information completely
                                    secure.
                                </p>
                                <div className="flex w-full max-w-lg flex-col gap-4">
                                    <Form
                                        {...store.form()}
                                        className="flex sm:items-center justify-center flex-col gap-2 sm:flex-row"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <Input
                                                    ref={emailInputRef}
                                                    type="email"
                                                    name="email"
                                                    placeholder="Enter your email"
                                                    required
                                                    className="h-12 border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
                                                    autoComplete="email"
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="h-12 bg-gradient-to-t px-6 from-zinc-700 to-zinc-900 dark:from-zinc-200 dark:to-zinc-300 text-shadow cursor-pointer disabled:cursor-default text-white shadow-sm transition-all duration hover:from-zinc-800 hover:to-black hover:shadow-md dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white dark:hover:shadow-md hover:dark:from-zinc-50"
                                                >
                                                    {processing
                                                        ? 'Submitting...'
                                                        : 'Get Early Access'}
                                                </Button>
                                                {errors.email && (
                                                    <div className="w-full">
                                                        <InputError
                                                            message={
                                                                errors.email
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </Form>
                                    <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                        Your data is yours alone. Join our
                                        waitlist for early access.
                                    </p>
                                </div>
                            </div>

                            <div className="group relative sm:px-24">
                                <div className="relative left-[-24%] z-10 h-[24px] rotate-[-24deg] skew-y-12 transition-all delay-200 duration-700 ease-in-out group-hover:left-[-32%] group-hover:rotate-[-12deg] group-hover:skew-y-6">
                                    <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                        <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                            <div className="aspect-[16/10] rounded-lg border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                <img src="/images/landing/bank-accounts.png" alt="Bank accounts securely stored" className="dark:hidden h-full w-auto rounded-lg" />
                                                <img src="/images/landing/dark_bank-accounts.png" alt="Bank accounts securely stored" className="hidden dark:block h-full w-auto rounded-lg" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="relative z-10 h-[24px] rotate-[-24deg] skew-y-12 transition-all delay-200 duration-700 ease-in-out group-hover:rotate-[-12deg] group-hover:skew-y-6">
                                    <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                        <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                            <div className="aspect-[16/10] rounded-lg border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                <img src="/images/landing/unlock-key.png" alt="Everything is encrypted with a private & local key you only have" className="dark:hidden h-full w-auto rounded-lg" />
                                                <img src="/images/landing/dark_unlock-key.png" alt="Everything is encrypted with a private & local key you only have" className="hidden dark:block h-full w-auto rounded-lg" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="relative left-[32%] z-10 rotate-[-24deg] skew-y-12 transition-all delay-200 duration-700 ease-in-out group-hover:left-[48%] group-hover:rotate-[-12deg] group-hover:skew-y-6">
                                    <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                        <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                            <div className="aspect-[16/10] rounded-lg border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                <img src="/images/landing/transactions.png" alt="You're transactions with a military grade encryption" className="dark:hidden h-full w-auto rounded-lg" />
                                                <img src="/images/landing/dark_transactions.png" alt="You're transactions with a military grade encryption" className="hidden dark:block h-full w-auto rounded-lg" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    data-slot="glow"
                                    className="absolute top-[50%] mt-32 w-full animate-appear-zoom opacity-0 delay-2000 lg:mt-4"
                                >
                                    <div className="absolute left-1/2 h-[256px] w-[60%] -translate-x-1/2 -translate-y-1/2 scale-[2.5] rounded-[50%] bg-radial from-[#1b1b18]/50 from-10% to-[#1b1b18]/0 to-60% opacity-20 sm:h-[512px] dark:from-[#EDEDEC]/50 dark:to-[#EDEDEC]/0 dark:opacity-100"></div>
                                    <div className="absolute left-1/2 h-[128px] w-[40%] -translate-x-1/2 -translate-y-1/2 scale-200 rounded-[50%] bg-radial from-[#1b1b18]/30 from-10% to-[#1b1b18]/0 to-60% opacity-20 sm:h-[256px] dark:from-[#EDEDEC]/30 dark:to-[#EDEDEC]/0 dark:opacity-100"></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 dark:border-[#3E3E3A] sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-6 sm:gap-20">
                            <h2 className="max-w-[560px] text-center text-3xl font-semibold leading-tight sm:text-5xl sm:leading-tight">
                                Everything you need. Nothing you don't.
                            </h2>
                            <div className="grid auto-rows-fr grid-cols-2 gap-0 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <ShieldCheckIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        End-to-end encryption
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Your financial data is encrypted on your
                                        device. Only you can access it.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <TrendingUpIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Smart budgeting
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Create budgets that adapt to your
                                        spending habits and goals.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <BellIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Intelligent insights
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Are you overspending?
                                        Know exactly where you stand.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <PieChartIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Visual insights
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Understand your spending with beautiful
                                        charts and reports.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <SmartphoneIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Works everywhere
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Access your finances on any device,
                                        anytime, anywhere.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <ZapIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Lightning fast
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Built for speed with instant sync and
                                        smooth interactions.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <EyeOffIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Zero tracking
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        We don't track, sell, or share your
                                        data. Ever.
                                    </div>
                                </div>
                                <div className="flex flex-col gap-4 p-4 text-[#1b1b18] dark:text-[#EDEDEC]">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold leading-none tracking-tight sm:text-base">
                                        <div className="flex items-center self-start">
                                            <CodeIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Open source
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Fully transparent and open source. Review
                                        the code yourself.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="w-full overflow-hidden px-0 py-12 dark:border-[#3E3E3A] sm:py-24 md:py-32">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-4 px-1 text-center sm:gap-16">
                            <div className="flex flex-col items-center gap-4 px-4 sm:gap-4">
                                <h2 className="max-w-[720px] text-2xl font-semibold leading-tight sm:text-3xl sm:leading-tight">
                                    Trusted by people who value their privacy
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] dark:text-[#A1A09A] sm:text-xl">
                                    Join thousands of users who have taken
                                    control of their finances without
                                    compromising their privacy.
                                </p>
                            </div>
                            <div className="relative flex w-full flex-col items-center justify-center overflow-hidden">
                                <div className="group flex flex-row overflow-hidden p-2 [--duration:20s] [--gap:1rem] [gap:var(--gap)]">
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying about
                                                data breaches!
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying about
                                                data breaches!
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying about
                                                data breaches!
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Emma L.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @emmalou
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                Love that my financial data is
                                                encrypted. No more worrying about
                                                data breaches!
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div className="group flex flex-row overflow-hidden p-2 [--duration:20s] [--gap:1rem] [gap:var(--gap)]">
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [animation-direction:reverse] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                how finance apps should be built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data is
                                                private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [animation-direction:reverse] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                how finance apps should be built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data is
                                                private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [animation-direction:reverse] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                how finance apps should be built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data is
                                                private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                    <div className="flex shrink-0 animate-marquee flex-row justify-around group-hover:[animation-play-state:paused] [animation-direction:reverse] [gap:var(--gap)]">
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                                how finance apps should be built.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
                                                        Jessica P.
                                                    </h3>
                                                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                        @jessicap
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="sm:text-md mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                The automation rules save me so
                                                much time. And knowing my data is
                                                private? Priceless.
                                            </p>
                                        </div>
                                        <div className="glass-3 flex max-w-[320px] flex-col rounded-lg p-4 text-start shadow-md sm:max-w-[420px] sm:p-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex flex-col items-start">
                                                    <h3 className="text-md font-semibold leading-none">
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
                                <div className="from-background pointer-events-none absolute inset-y-0 left-0 hidden w-1/3 bg-linear-to-r sm:block"></div>
                                <div className="from-background pointer-events-none absolute inset-y-0 right-0 hidden w-1/3 bg-linear-to-l sm:block"></div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="py-8 dark:border-[#3E3E3A]">
                    <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-4 px-6 text-sm text-[#706f6c] dark:text-[#A1A09A] sm:flex-row lg:px-8">
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
