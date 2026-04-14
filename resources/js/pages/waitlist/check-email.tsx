import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { MailCheckIcon } from 'lucide-react';

export default function CheckEmail({ email }: { email: string }) {
    const errors = usePage<{ errors: { email?: string } }>().props.errors ?? {};

    return (
        <>
            <Head title={__('Confirm your email - Whisper Money')}>
                <meta
                    name="description"
                    content={__(
                        'Confirm your email address to reserve your Whisper Money waitlist spot.',
                    )}
                />
            </Head>

            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <main className="flex flex-1 items-center justify-center px-6 py-32">
                    <div className="mx-auto flex w-full max-w-xl flex-col items-center gap-8 text-center">
                        <div className="flex size-16 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-950/60">
                            <MailCheckIcon className="size-8 text-blue-600 dark:text-blue-300" />
                        </div>

                        <div className="flex flex-col gap-3">
                            <h1 className="font-heading text-3xl font-semibold sm:text-4xl">
                                {__('Confirm your email to join the list')}
                            </h1>
                            <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We sent a confirmation link to :email. Click it to reserve your waitlist spot and unlock your referral link.',
                                    { email },
                                )}
                            </p>
                        </div>

                        {errors.email && (
                            <div className="w-full rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 dark:border-red-950 dark:bg-red-950/30 dark:text-red-200">
                                {errors.email}
                            </div>
                        )}

                        <div className="w-full rounded-3xl border border-[#e3e3e0] bg-[#f8f8f7] px-6 py-6 text-left dark:border-[#3E3E3A] dark:bg-[#161615]">
                            <p className="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                {__('What happens after you confirm?')}
                            </p>
                            <div className="mt-4 grid gap-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                <p>
                                    {__('Your place in the queue is reserved.')}
                                </p>
                                <p>
                                    {__('You get your personal referral link.')}
                                </p>
                                <p>
                                    {__(
                                        'Each confirmed referral moves you 10 spots forward.',
                                    )}
                                </p>
                            </div>
                        </div>

                        <Button asChild size="lg" variant="secondary">
                            <Link href="/">{__('Back to home')}</Link>
                        </Button>
                    </div>
                </main>
            </div>
        </>
    );
}
