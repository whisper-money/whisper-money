import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useShareImage } from '@/hooks/use-share-image';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { DownloadIcon, Share2Icon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

/**
 * Posting one picture, wherever it came from.
 *
 * Two shapes and two skins: a card goes into a feed at 4:5 and into a story at
 * 9:16, and one posted into a dark feed reads better dark. Both the monthly
 * summary and an achievement medal come through here, so the two screens cannot
 * drift into offering different things.
 *
 * The preview is not decoration: painting it is what puts the PNG in the
 * browser's cache, and that is what lets the share button hand the file over
 * inside the tap that pressed it — see {@see useShareImage}. So the button waits
 * for the preview rather than racing it, and "waits" is tracked as *which* URL
 * has painted, not as a flag somebody has to remember to reset: change a shape,
 * a skin or anything a caller folds into its own URL, and the button goes back
 * to waiting on its own.
 *
 * Saving the picture is always offered. It is the whole fallback for a browser
 * whose share sheet cannot carry files, so it can never be the thing hidden
 * behind the feature test.
 */
export type ShareFormat = 'feed' | 'story';
export type ShareTheme = 'light' | 'dark';

const FORMATS: Record<ShareFormat, () => string> = {
    feed: () => __('Post · 4:5'),
    story: () => __('Story · 9:16'),
};

const THEMES: Record<ShareTheme, () => string> = {
    light: () => __('Light'),
    dark: () => __('Dark'),
};

/**
 * Which skin to open on.
 *
 * The reader's own choice — light, dark or whatever the OS says — is already
 * resolved onto the document by the time React runs, so reading it back off the
 * class is shorter and less wrong than working it out a second time from the
 * preference and a media query. It has to be read after mount rather than as
 * initial state, because the page is server-rendered and the server cannot know
 * what the OS says.
 */
function documentTheme(): ShareTheme {
    return typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
}

function Preview({
    src,
    format,
    alt,
    onReady,
}: {
    src: string;
    format: ShareFormat;
    alt: string;
    onReady: () => void;
}) {
    const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );
    // A render that timed out usually works on the next go, and the browser
    // holds on to the failure, so a retry needs a URL it has not seen.
    const [attempt, setAttempt] = useState(0);

    return (
        <div
            className={cn(
                'relative mx-auto w-full overflow-hidden rounded-lg border bg-muted',
                format === 'feed'
                    ? 'aspect-[4/5] max-w-64'
                    : 'aspect-[9/16] max-w-44',
            )}
        >
            {status === 'failed' ? (
                <div className="flex size-full flex-col items-center justify-center gap-2 p-4 text-center">
                    <p className="text-xs text-muted-foreground">
                        {__('This picture could not be drawn.')}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            setAttempt(attempt + 1);
                            setStatus('loading');
                        }}
                    >
                        {__('Try again')}
                    </Button>
                </div>
            ) : (
                <>
                    {status === 'loading' && (
                        <Skeleton className="absolute inset-0 rounded-none" />
                    )}
                    <img
                        src={attempt === 0 ? src : `${src}&retry=${attempt}`}
                        alt={alt}
                        decoding="async"
                        className={cn(
                            'size-full object-contain transition-opacity',
                            status === 'ready' ? 'opacity-100' : 'opacity-0',
                        )}
                        onLoad={() => {
                            setStatus('ready');
                            onReady();
                        }}
                        onError={() => setStatus('failed')}
                    />
                </>
            )}
        </div>
    );
}

export function ShareCardDialog({
    title,
    description,
    subject,
    url,
    filename,
    controls,
    children,
}: {
    title: string;
    description: string;
    /** What the picture is of, for the share sheet and the preview's alt text. */
    subject: string;
    /** The picture at this shape and skin; `preview` paints it instead of saving it. */
    url: (shape: {
        format: ShareFormat;
        theme: ShareTheme;
        preview: boolean;
    }) => string;
    filename: (shape: { format: ShareFormat; theme: ShareTheme }) => string;
    /** Anything else the caller wants under the toggles, folded into its own url(). */
    controls?: React.ReactNode;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [format, setFormat] = useState<ShareFormat>('feed');
    const [theme, setTheme] = useState<ShareTheme>('light');
    const [painted, setPainted] = useState<string | null>(null);
    const { canShareFiles, sharing, shareImage } = useShareImage();

    useEffect(() => {
        if (open) {
            setTheme(documentTheme());
        }
    }, [open]);

    const previewUrl = url({ format, theme, preview: true });
    const ready = painted === previewUrl;

    const onShare = async () => {
        const outcome = await shareImage({
            url: previewUrl,
            filename: filename({ format, theme }),
            title: subject,
        });

        if (outcome === 'shared') {
            setOpen(false);

            return;
        }

        if (outcome === 'failed') {
            toast.error(
                __('That could not be shared. You can save it instead.'),
            );
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <Preview
                    key={previewUrl}
                    src={previewUrl}
                    format={format}
                    alt={subject}
                    onReady={() => setPainted(previewUrl)}
                />

                <div className="flex flex-col gap-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={format}
                        onValueChange={(next) =>
                            next && setFormat(next as ShareFormat)
                        }
                        className="w-full"
                    >
                        {Object.entries(FORMATS).map(([value, label]) => (
                            <ToggleGroupItem
                                key={value}
                                value={value}
                                className="flex-1 cursor-pointer text-xs aria-checked:bg-primary/10"
                            >
                                {label()}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>

                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={theme}
                        onValueChange={(next) =>
                            next && setTheme(next as ShareTheme)
                        }
                        className="w-full"
                    >
                        {Object.entries(THEMES).map(([value, label]) => (
                            <ToggleGroupItem
                                key={value}
                                value={value}
                                className="flex-1 cursor-pointer text-xs aria-checked:bg-primary/10"
                            >
                                {label()}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>

                    {controls}
                </div>

                <div className="flex flex-col gap-2 sm:flex-row-reverse">
                    {canShareFiles && (
                        <Button
                            onClick={onShare}
                            disabled={!ready || sharing}
                            className="flex-1 cursor-pointer"
                        >
                            <Share2Icon className="size-4" />
                            {sharing ? __('Sharing…') : __('Share')}
                        </Button>
                    )}
                    <Button
                        variant={canShareFiles ? 'outline' : 'default'}
                        asChild
                        className="flex-1 cursor-pointer"
                    >
                        {/* A plain link, because the response is already an
                            attachment: no fetch, no blob, and it works while a
                            render is still in flight. */}
                        <a
                            href={url({ format, theme, preview: false })}
                            download={filename({ format, theme })}
                        >
                            <DownloadIcon className="size-4" />
                            {__('Save the picture')}
                        </a>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
