import Header from '@/components/partials/header';
import { Button } from '@/components/ui/button';
import { checkout } from '@/routes/subscribe';
import { Head } from '@inertiajs/react';
import { CheckIcon, ShieldCheckIcon } from 'lucide-react';

export default function Paywall() {
    return (
        <>
            <Head title="Upgrade to Pro - Whisper Money" />
            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <Header canRegister={false} hideExternalButtons hideAuthButtons />

                <main className="flex flex-1 flex-col items-center justify-center px-4 py-12">
                    <div className="w-full max-w-lg">
                        <div className="mb-8 text-center">
                            <div className="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                <ShieldCheckIcon className="size-8 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <h1 className="mb-2 text-3xl font-bold">
                                Upgrade to Pro
                            </h1>
                            <p className="text-[#706f6c] dark:text-[#A1A09A]">
                                Unlock all features and take control of your
                                finances with military-grade encryption.
                            </p>
                        </div>

                        <div className="overflow-hidden rounded-2xl border border-[#e3e3e0] bg-white shadow-xl dark:border-[#3E3E3A] dark:bg-[#161615]">
                            <div className="bg-gradient-to-br from-emerald-50 to-teal-50 p-6 dark:from-emerald-900/20 dark:to-teal-900/20">
                                <div className="flex items-baseline gap-2">
                                    <span className="text-4xl font-bold">
                                        $9
                                    </span>
                                    <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                        /month
                                    </span>
                                </div>
                                <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Billed monthly. Cancel anytime.
                                </p>
                            </div>

                            <div className="p-6">
                                <ul className="mb-6 space-y-3">
                                    {[
                                        'Unlimited bank accounts',
                                        'Unlimited transactions',
                                        'End-to-end encryption',
                                        'Smart categorization',
                                        'Automation rules',
                                        'Visual insights & reports',
                                        'Priority support',
                                    ].map((feature) => (
                                        <li
                                            key={feature}
                                            className="flex items-center gap-3"
                                        >
                                            <CheckIcon className="size-5 shrink-0 text-emerald-500" />
                                            <span className="text-sm">
                                                {feature}
                                            </span>
                                        </li>
                                    ))}
                                </ul>

                                <a href={checkout.url()}>
                                    <Button className="w-full cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 py-6 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] hover:dark:from-zinc-50">
                                        Subscribe Now
                                    </Button>
                                </a>

                                <p className="mt-4 text-center text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                    Use code{' '}
                                    <span className="font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                        FOUNDER
                                    </span>{' '}
                                    for $8 off your first month
                                </p>
                            </div>
                        </div>

                        <p className="mt-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            Secure payment powered by Stripe. Your payment
                            information is never stored on our servers.
                        </p>
                    </div>
                </main>
            </div>
        </>
    );
}
