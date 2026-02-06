import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePwaInstall } from '@/hooks/use-pwa-install';
import { DownloadIcon, PlusSquareIcon, ShareIcon } from 'lucide-react';
import { useState } from 'react';

export default function InstallAppButton() {
    const { platform, canInstall, promptInstall } = usePwaInstall();
    const [showIosDialog, setShowIosDialog] = useState(false);

    if (platform === 'android') {
        return (
            <Button
                onClick={() => {
                    if (canInstall) {
                        promptInstall();
                    }
                }}
                disabled={!canInstall}
                className="text-shadow h-14 w-full cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:bg-[#eeeeec] dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:bg-white hover:dark:from-zinc-50 dark:hover:shadow-md"
            >
                <DownloadIcon className="size-5" />
                Install App
            </Button>
        );
    }

    if (platform === 'ios') {
        return (
            <>
                <Button
                    onClick={() => setShowIosDialog(true)}
                    className="text-shadow h-14 w-full cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:bg-[#eeeeec] dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:bg-white hover:dark:from-zinc-50 dark:hover:shadow-md"
                >
                    <DownloadIcon className="size-5" />
                    Install App
                </Button>

                <Dialog open={showIosDialog} onOpenChange={setShowIosDialog}>
                    <DialogContent className="max-w-sm">
                        <DialogHeader>
                            <DialogTitle>Install Whisper Money</DialogTitle>
                            <DialogDescription>
                                Add the app to your home screen for the best
                                experience.
                            </DialogDescription>
                        </DialogHeader>

                        <ol className="flex flex-col gap-5 py-2">
                            <li className="flex items-center gap-4">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <ShareIcon className="size-5 text-zinc-600 dark:text-zinc-400" />
                                </span>
                                <span className="text-sm">
                                    Tap the{' '}
                                    <strong className="font-semibold">
                                        Share
                                    </strong>{' '}
                                    button in your browser toolbar
                                </span>
                            </li>
                            <li className="flex items-center gap-4">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <PlusSquareIcon className="size-5 text-zinc-600 dark:text-zinc-400" />
                                </span>
                                <span className="text-sm">
                                    Tap{' '}
                                    <strong className="font-semibold">
                                        Add to Home Screen
                                    </strong>
                                </span>
                            </li>
                        </ol>

                        <Button
                            variant="secondary"
                            className="mt-2 w-full"
                            onClick={() => setShowIosDialog(false)}
                        >
                            Got it
                        </Button>
                    </DialogContent>
                </Dialog>
            </>
        );
    }

    return null;
}
