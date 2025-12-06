import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { Head, Link } from '@inertiajs/react';
import { BirdIcon, CheckCircleIcon, PartyPopperIcon } from 'lucide-react';

export default function Success() {
    return (
        <>
            <Head title="Welcome to Pro! - Whisper Money" />
            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <header className="w-full border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                        <div className="flex items-center gap-3 font-mono">
                            <BirdIcon className="size-5" />
                            <span className="font-medium">Whisper Money</span>
                        </div>
                    </div>
                </header>

                <main className="flex flex-1 flex-col items-center justify-center px-4 py-12">
                    <div className="w-full max-w-md text-center">
                        <div className="mb-6 flex justify-center gap-2">
                            <PartyPopperIcon className="size-12 text-amber-500" />
                        </div>

                        <div className="mb-4 flex justify-center">
                            <CheckCircleIcon className="size-16 text-emerald-500" />
                        </div>

                        <h1 className="mb-2 text-3xl font-bold">
                            Welcome to Pro!
                        </h1>
                        <p className="mb-8 text-[#706f6c] dark:text-[#A1A09A]">
                            Your subscription is now active. You have full
                            access to all Whisper Money features.
                        </p>

                        <Link href={dashboard()}>
                            <Button className="cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 px-8 py-6 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] hover:dark:from-zinc-50">
                                Go to Dashboard
                            </Button>
                        </Link>

                        <p className="mt-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            Thank you for supporting Whisper Money. Your privacy
                            is our priority.
                        </p>
                    </div>
                </main>
            </div>
        </>
    );
}
