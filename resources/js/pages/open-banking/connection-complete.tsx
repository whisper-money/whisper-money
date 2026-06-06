import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';

interface ConnectionCompleteProps {
    status: 'success' | 'error';
    message: string;
}

export default function ConnectionComplete({
    status,
    message,
}: ConnectionCompleteProps) {
    const isSuccess = status === 'success';
    const title = isSuccess
        ? __('Bank account connected')
        : __('Connection unsuccessful');

    return (
        <>
            <Head title={title} />

            <div className="flex min-h-svh flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <main className="flex flex-1 items-center justify-center px-6 py-32">
                    <div className="mx-auto flex w-full max-w-xl flex-col items-center gap-8 text-center">
                        {isSuccess ? (
                            <div className="flex size-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950/60">
                                <CheckCircle2 className="size-8 text-emerald-600 dark:text-emerald-300" />
                            </div>
                        ) : (
                            <div className="flex size-16 items-center justify-center rounded-full bg-red-100 dark:bg-red-950/60">
                                <XCircle className="size-8 text-red-600 dark:text-red-300" />
                            </div>
                        )}

                        <div className="flex flex-col gap-3">
                            <h1 className="font-heading text-3xl font-semibold sm:text-4xl">
                                {title}
                            </h1>
                            <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                                {message}
                            </p>
                            <p className="text-base text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You can close this window and go back to the app to continue.',
                                )}
                            </p>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
