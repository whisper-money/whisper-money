import { card } from '@/actions/App/Http/Controllers/AchievementController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { type AchievementMedal } from '@/types';
import { __ } from '@/utils/i18n';
import { DownloadIcon, Share2Icon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/**
 * Posting one medal.
 *
 * Two shapes and two skins, because a card goes into a feed at 4:5 and into a
 * story at 9:16, and one posted into a dark feed reads better dark. The picture
 * is drawn on the server the first time it is asked for.
 *
 * The preview is not decoration: painting it is what puts the PNG in the
 * browser's cache, and that is what lets the share button hand the file over
 * inside the tap that pressed it — see {@see useShareImage}. So the button
 * waits for the preview rather than racing it.
 *
 * A money medal writes its amount by default and the reader can take it off.
 * Off, the medal still says what it is: the name and the tier carry it, and the
 * picture stops being a statement about how much money somebody has.
 */
type Format = 'feed' | 'story';
type Theme = 'light' | 'dark';

const FORMATS: Record<Format, () => string> = {
    feed: () => __('Post · 4:5'),
    story: () => __('Story · 9:16'),
};

const THEMES: Record<Theme, () => string> = {
    light: () => __('Light'),
    dark: () => __('Dark'),
};

/** The medal's own figure is an amount, so hiding it is a choice worth having. */
function hasAmount(medal: AchievementMedal): boolean {
    return medal.figure?.type === 'money';
}

function Preview({
    src,
    format,
    alt,
    onReady,
}: {
    src: string;
    format: Format;
    alt: string;
    onReady: (ready: boolean) => void;
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
                            onReady(true);
                        }}
                        onError={() => {
                            setStatus('failed');
                            onReady(false);
                        }}
                    />
                </>
            )}
        </div>
    );
}

export function ShareMedalDialog({
    medal,
    children,
}: {
    medal: AchievementMedal;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [format, setFormat] = useState<Format>('feed');
    const [theme, setTheme] = useState<Theme>('light');
    const [amount, setAmount] = useState(true);
    const [ready, setReady] = useState(false);
    const { canShareFiles, sharing, shareImage } = useShareImage();

    const query = { amount: amount ? 1 : 0 };
    const base = card({ medal: medal.key, format, theme });
    const previewUrl = `${base.url}?preview=1&amount=${query.amount}`;
    const downloadUrl = `${base.url}?amount=${query.amount}`;
    const filename = `whisper-money-${medal.key.replace('.', '-')}-${format}-${theme}.png`;

    // Every knob changes which picture this is, so the preview has to be
    // awaited again before the share button can promise a file.
    const previewKey = `${format}-${theme}-${amount}`;

    const onShare = async () => {
        const outcome = await shareImage({
            url: previewUrl,
            filename,
            title: medal.name ?? __('Achievement'),
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
                    <DialogTitle>{__('Share this medal')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Pick a shape and a skin. Nothing leaves your device until you send it.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Preview
                    key={previewKey}
                    src={previewUrl}
                    format={format}
                    alt={medal.name ?? __('Achievement')}
                    onReady={setReady}
                />

                <div className="flex flex-col gap-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={format}
                        onValueChange={(next) => {
                            if (next) {
                                setReady(false);
                                setFormat(next as Format);
                            }
                        }}
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
                        onValueChange={(next) => {
                            if (next) {
                                setReady(false);
                                setTheme(next as Theme);
                            }
                        }}
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

                    {hasAmount(medal) && (
                        <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3">
                            <Checkbox
                                checked={!amount}
                                onCheckedChange={(checked) => {
                                    setReady(false);
                                    setAmount(checked !== true);
                                }}
                                className="mt-0.5"
                            />
                            <span className="flex flex-col gap-0.5">
                                <span className="text-sm font-medium">
                                    {__('Leave the amount off')}
                                </span>
                                <span className="text-xs text-pretty text-muted-foreground">
                                    {__(
                                        'The medal, its name and its tier still show. The figure does not.',
                                    )}
                                </span>
                            </span>
                        </label>
                    )}
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
                        <a href={downloadUrl} download={filename}>
                            <DownloadIcon className="size-4" />
                            {__('Save the picture')}
                        </a>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
