import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { BirdIcon, CheckCircleIcon, CopyIcon } from 'lucide-react';
import { useState } from 'react';

interface ThankYouProps {
    position: number;
    referralUrl: string;
}

export default function ThankYou({ position, referralUrl }: ThankYouProps) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(referralUrl).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        });
    };

    return (
        <>
            <Head title={__("You're on the waiting list — Whisper Money")}>
                <meta
                    name="description"
                    content={__(
                        "You've joined the Whisper Money waiting list. Share your referral link to move up the queue.",
                    )}
                />
            </Head>

            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <main className="flex flex-1 flex-col items-center justify-center px-6 py-32">
                    <div className="mx-auto flex w-full max-w-lg flex-col items-center gap-8 text-center">
                        {/* Icon */}
                        <div className="flex size-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950">
                            <CheckCircleIcon className="size-8 text-emerald-600 dark:text-emerald-400" />
                        </div>

                        {/* Heading */}
                        <div className="flex flex-col gap-3">
                            <h1 className="font-heading text-3xl font-semibold sm:text-4xl">
                                {__("You're on the list!")}
                            </h1>
                            <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    "Check your inbox — we've sent you an email.",
                                )}
                            </p>
                        </div>

                        {/* Position badge */}
                        <div className="w-full rounded-2xl border border-[#e3e3e0] bg-[#f8f8f7] px-6 py-5 dark:border-[#3E3E3A] dark:bg-[#161615]">
                            <p className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">
                                {__('Your position in the queue')}
                            </p>
                            <p className="mt-1 text-5xl font-bold tracking-tight">
                                #{position}
                            </p>
                        </div>

                        {/* Referral section */}
                        <div className="flex w-full flex-col gap-4 text-left">
                            <div className="flex flex-col gap-1">
                                <p className="font-medium">
                                    {__('Move up 10 spots per referral')}
                                </p>
                                <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    {__(
                                        'Share your personal link. Every person who joins through it moves you 10 positions forward.',
                                    )}
                                </p>
                            </div>

                            {/* Referral link box */}
                            <div className="flex w-full items-center gap-2 overflow-hidden rounded-xl border border-[#e3e3e0] bg-[#f8f8f7] p-1.5 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <p className="min-w-0 flex-1 truncate px-3 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    {referralUrl}
                                </p>
                                <Button
                                    onClick={handleCopy}
                                    size="sm"
                                    className="shrink-0 gap-1.5"
                                >
                                    <CopyIcon className="size-3.5" />
                                    {copied ? __('Copied!') : __('Copy link')}
                                </Button>
                            </div>
                        </div>

                        {/* Footer note */}
                        <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            <span className="inline-flex items-center gap-1">
                                <BirdIcon className="size-3" />
                                Whisper Money
                            </span>
                        </p>
                    </div>
                </main>
            </div>
        </>
    );
}
