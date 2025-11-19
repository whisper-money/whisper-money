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
} from 'lucide-react';

export default function Welcome({
    canRegister = true,
    hideAuthButtons = false,
}: {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
}) {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome">
                <meta
                    name="description"
                    content="The most secure personal finance app with end-to-end encryption. Track expenses, create budgets, and manage your money privately."
                />
                <link rel="canonical" href={window.location.origin} />

                <meta property="og:title" content="Whisper Money - The Most Secure Personal Finance App" />
                <meta
                    property="og:description"
                    content="Your financial data stays private with end-to-end encryption. The most secure way to manage your personal finances."
                />
                <meta property="og:type" content="website" />
                <meta property="og:url" content={window.location.origin} />
                <meta property="og:image" content={`${window.location.origin}/og-image.png`} />

                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:title" content="Whisper Money - The Most Secure Personal Finance App" />
                <meta
                    name="twitter:description"
                    content="Your financial data stays private with end-to-end encryption. The most secure way to manage your personal finances."
                />
                <meta name="twitter:image" content={`${window.location.origin}/og-image.png`} />
            </Head>
            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <header className="w-full fade-bottom bg-background/5 fixed top-0 z-50 h-16 backdrop-blur-lg">
                    <div className="mx-auto flex max-w-5xl items-center justify-between py-4">
                        <div className="flex items-center gap-2">
                            <img
                                src="/images/whisper_money_logo.png"
                                alt="Whisper Money"
                                className="h-8 w-auto"
                            />
                            <span className='font-medium'>Whisper Money</span>
                        </div>
                        {!hideAuthButtons && (
                            <nav className="flex items-center gap-4">
                                {auth.user ? (
                                    <Link
                                        href={dashboard()}
                                        className="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                                    >
                                        Dashboard
                                    </Link>
                                ) : (
                                    <>
                                        <Link
                                            href="/login"
                                            className="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                                        >
                                            Log in
                                        </Link>
                                        {canRegister && (
                                            <Link
                                                href="/register"
                                                className="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                                            >
                                                Register
                                            </Link>
                                        )}
                                    </>
                                )}
                            </nav>
                        )}
                    </div>
                </header>

                <main className="flex flex-1 flex-col">
                    <section className="relative w-full overflow-hidden px-6 py-24 sm:py-32 md:py-40">
                        <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-12">
                            <div className="max-w-5xl w-full mx-auto relative z-10 flex flex-col items-start gap-2 sm:gap-8">
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-[#e3e3e0] py-1 px-2.5 text-[0.8rem] font-medium dark:border-[#3E3E3A]">
                                    <LockIcon className='size-3.5 opacity-75' />
                                    <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                        Military Grade Encryption
                                    </span>
                                </span>
                                <h1 className="max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl font-semibold leading-tight text-transparent drop-shadow-2xl dark:from-[#EDEDEC] dark:to-[#A1A09A] sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight">
                                    The most secure way to manage your money
                                </h1>
                                <p className="max-w-[840px] mb-4 text-lg leading-8 font-medium text-[#706f6c] dark:text-[#A1A09A] lg:text-xl lg:leading-8">
                                    Your financial data stays private with
                                    end-to-end encryption. Track expenses,
                                    create budgets, and achieve your goals—all
                                    while keeping your information completely
                                    secure.
                                </p>
                                <div className="flex w-full max-w-md flex-col gap-4">
                                    <Form
                                        {...store.form()}
                                        className="flex sm:items-center justify-center flex-col gap-2 sm:flex-row"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <Input
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
                                                    className="h-12 bg-gradient-to-t from-zinc-700 to-zinc-900 text-shadow text-white shadow-sm hover:bg-black dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
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
                                            <div className="aspect-[16/10] rounded-lg border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                <div className="flex h-full items-center justify-center">
                                                    <div className="text-center">
                                                        <div className="mb-2 text-4xl font-bold text-[#e3e3e0] dark:text-[#3E3E3A]">
                                                            1
                                                        </div>
                                                        <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                            Screenshot
                                                            placeholder
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="relative z-10 h-[24px] rotate-[-24deg] skew-y-12 transition-all delay-200 duration-700 ease-in-out group-hover:rotate-[-12deg] group-hover:skew-y-6">
                                    <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                        <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                            <div className="aspect-[16/10] rounded-lg border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                <div className="flex h-full items-center justify-center">
                                                    <div className="text-center">
                                                        <div className="mb-2 text-4xl font-bold text-[#e3e3e0] dark:text-[#3E3E3A]">
                                                            2
                                                        </div>
                                                        <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                            Screenshot
                                                            placeholder
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="relative left-[32%] z-10 rotate-[-24deg] skew-y-12 transition-all delay-200 duration-700 ease-in-out group-hover:left-[48%] group-hover:rotate-[-12deg] group-hover:skew-y-6">
                                    <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                        <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                            <div className="aspect-[16/10] rounded-lg border border-[#e3e3e0] bg-[#FDFDFC] p-8 dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                <div className="flex h-full items-center justify-center">
                                                    <div className="text-center">
                                                        <div className="mb-2 text-4xl font-bold text-[#e3e3e0] dark:text-[#3E3E3A]">
                                                            3
                                                        </div>
                                                        <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                            Screenshot
                                                            placeholder
                                                        </p>
                                                    </div>
                                                </div>
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
                                            <LockIcon className="size-5 stroke-1 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                        </div>
                                        Privacy first
                                    </h3>
                                    <div className="flex max-w-[240px] flex-col gap-2 text-balance text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Built from the ground up with privacy as
                                        the core principle.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="py-8 dark:border-[#3E3E3A]">
                    <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-6 text-sm text-[#706f6c] dark:text-[#A1A09A] sm:flex-row lg:px-8">
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
