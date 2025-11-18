import { dashboard } from '@/routes';
import { store } from '@/routes/user-leads';
import { type SharedData } from '@/types';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';

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
                <header className="w-full border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4 lg:px-8">
                        <div className="text-lg font-semibold">
                            Whisper Money
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
                            <div className="relative z-10 flex flex-col items-start gap-6 sm:gap-8">
                                <span className="inline-flex items-center gap-2 rounded-full border border-[#e3e3e0] px-2.5 py-1 text-xs font-semibold dark:border-[#3E3E3A]">
                                    <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                        🔒 End-to-end encrypted
                                    </span>
                                </span>
                                <h1 className="max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl font-semibold leading-tight text-transparent drop-shadow-2xl dark:from-[#EDEDEC] dark:to-[#A1A09A] sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight">
                                    The Most Secure Way to Manage Your Money
                                </h1>
                                <p className="max-w-[840px] text-lg font-medium text-[#706f6c] dark:text-[#A1A09A] lg:text-xl">
                                    Your financial data stays private with
                                    end-to-end encryption. Track expenses,
                                    create budgets, and achieve your goals—all
                                    while keeping your information completely
                                    secure.
                                </p>
                                <div className="flex w-full max-w-md flex-col gap-4">
                                    <Form
                                        {...store.form()}
                                        className="flex flex-col gap-2 sm:flex-row"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <Input
                                                    type="email"
                                                    name="email"
                                                    placeholder="Enter your email"
                                                    required
                                                    className="flex-1 border-[#e3e3e0]/50 bg-[#FDFDFC]/50 dark:border-[#3E3E3A]/50 dark:bg-[#0a0a0a]/50"
                                                    autoComplete="email"
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="bg-[#1b1b18] text-white shadow-sm hover:bg-black dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
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
                            </div>

                            <div className="absolute inset-0 top-[50%] mt-32 w-full lg:mt-4">
                                <div className="absolute left-1/2 h-[256px] w-[60%] -translate-x-1/2 -translate-y-1/2 scale-[2.5] rounded-[50%] bg-gradient-to-r from-[#f53003]/50 to-[#f53003]/0 opacity-20 sm:h-[512px] dark:opacity-100" />
                                <div className="absolute left-1/2 h-[128px] w-[40%] -translate-x-1/2 -translate-y-1/2 scale-200 rounded-[50%] bg-gradient-to-r from-[#f53003]/30 to-[#FF4433]/0 opacity-20 sm:h-[256px] dark:opacity-100" />
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-[#e3e3e0] py-8 dark:border-[#3E3E3A]">
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
