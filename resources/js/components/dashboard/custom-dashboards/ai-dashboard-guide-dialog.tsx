import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { useClipboard } from '@/hooks/use-clipboard';
import { useIsMobile } from '@/hooks/use-mobile';
import { index as aiConnector } from '@/routes/mcp';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Check, Copy, Monitor, Sparkles } from 'lucide-react';
import { type ReactNode } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * What "Create a dashboard with AI" opens from the dashboard picker: how to
 * connect the AI Connector and ask the assistant for a dashboard. A dialog on
 * a computer, a bottom drawer on a phone.
 */
export function AiDashboardGuideDialog({ open, onOpenChange }: Props) {
    const isMobile = useIsMobile();

    const title = __('Create a dashboard with AI');
    const description = __(
        'Your AI assistant can build a dashboard made for you, with widgets it writes just for you. Connect it and ask in your own words.',
    );

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerContent data-testid="ai-dashboard-guide">
                    {/* The header scrolls with the steps, so a short phone keeps the button in reach. */}
                    <div className="overflow-y-auto">
                        <DrawerHeader className="items-start gap-3 group-data-[vaul-drawer-direction=bottom]/drawer-content:text-left">
                            <IconTile />
                            <DrawerTitle>{title}</DrawerTitle>
                            <DrawerDescription>{description}</DrawerDescription>
                        </DrawerHeader>
                        <div className="px-4">
                            <GuideSteps />
                        </div>
                    </div>
                    <DrawerFooter className="gap-3 border-t">
                        <OpenAiConnectorButton className="h-11 w-full" />
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="gap-5 sm:max-w-[540px]"
                data-testid="ai-dashboard-guide"
            >
                <DialogHeader className="gap-3">
                    <IconTile />
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <GuideSteps />
                <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {__('Close')}
                    </Button>
                    <OpenAiConnectorButton />
                </div>
            </DialogContent>
        </Dialog>
    );
}

function IconTile() {
    return (
        <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
            <Sparkles className="size-5" aria-hidden="true" />
        </div>
    );
}

function GuideSteps() {
    return (
        <ol className="flex flex-col gap-4">
            <GuideStep number={1} title={__('Connect your assistant')}>
                <p>
                    {withEmphasis(
                        __(
                            "Add Whisper Money as a connector in Claude or ChatGPT. In :place you'll find the URL and the steps for each app.",
                        ),
                        __('Settings → AI Connector'),
                    )}
                </p>
                <p className="flex items-center gap-1.5 text-xs">
                    <Monitor className="size-3.5 shrink-0" aria-hidden="true" />
                    {__(
                        'Do it from a computer: approving the connection often fails on a phone.',
                    )}
                </p>
            </GuideStep>
            <GuideStep number={2} title={__('Ask for your dashboard')}>
                <p>{__('Tell it what you want to see. For example:')}</p>
                <ExamplePrompt />
            </GuideStep>
            <GuideStep number={3} title={__('Open it here')}>
                <p>
                    {withEmphasis(
                        __(
                            'It will show up in this picker. From :place you can move its widgets, resize them or ask your assistant to adjust them.',
                        ),
                        __('Edit'),
                    )}
                </p>
            </GuideStep>
        </ol>
    );
}

function GuideStep({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="flex gap-3">
            <span
                aria-hidden="true"
                className="flex size-[26px] shrink-0 items-center justify-center rounded-full border text-xs font-medium"
            >
                {number}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <p className="text-sm font-medium">{title}</p>
                <div className="flex flex-col gap-2 text-sm text-muted-foreground">
                    {children}
                </div>
            </div>
        </li>
    );
}

function ExamplePrompt() {
    const prompt = __(
        'Build me a dashboard for my motorbike with the total cost, what I spend on it each month and the list of transactions I have labelled Motorbike.',
    );
    // The content unmounts on close, so "Copied" lasts until the guide does.
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === prompt;

    return (
        <div className="flex items-start gap-3 rounded-lg bg-muted p-3">
            <p
                className="min-w-0 flex-1 text-foreground"
                data-testid="ai-dashboard-guide-prompt"
            >
                {prompt}
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="shrink-0"
                onClick={() => void copy(prompt)}
            >
                {copied ? (
                    <Check className="size-4" />
                ) : (
                    <Copy className="size-4" />
                )}
                <span aria-live="polite">
                    {copied ? __('Copied') : __('Copy')}
                </span>
            </Button>
        </div>
    );
}

function OpenAiConnectorButton({ className }: { className?: string }) {
    return (
        <Button asChild className={className}>
            <Link href={aiConnector().url}>{__('Open AI Connector')}</Link>
        </Button>
    );
}

/**
 * A translated sentence with `:place` swapped for a bolded phrase, so the
 * phrase can sit anywhere the language puts it.
 */
function withEmphasis(sentence: string, phrase: string): ReactNode {
    const [before, after = ''] = sentence.split(':place');

    return (
        <>
            {before}
            <strong className="font-medium text-foreground">{phrase}</strong>
            {after}
        </>
    );
}
