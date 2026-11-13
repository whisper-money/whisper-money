import { ProBadge } from '@/components/pro-badge';
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
import {
    trackAiGuideOpened,
    trackAiGuidePromptCopied,
    trackUpgradePrompted,
} from '@/lib/custom-dashboards-telemetry';
import { cn } from '@/lib/utils';
import documentation from '@/routes/documentation';
import { index as aiConnector } from '@/routes/mcp';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Check, Copy, Monitor, Sparkles } from 'lucide-react';
import { type ReactNode, useEffect } from 'react';
import { SeePlansButton } from './standard-plan-notice';

/** The custom dashboards page of the documentation; sections stay out of slugs. */
const DOCS_SLUG = 'custom-dashboards';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Without the Standard plan the guide still explains, and sells the plan. */
    hasPlan: boolean;
}

/**
 * What "Create a dashboard with AI" opens from the dashboard picker: how to
 * connect the AI Connector and ask the assistant for a dashboard. A dialog on
 * a computer, a bottom drawer on a phone.
 */
export function AiDashboardGuideDialog({ open, onOpenChange, hasPlan }: Props) {
    const isMobile = useIsMobile();

    useEffect(() => {
        if (!open) {
            return;
        }

        trackAiGuideOpened(hasPlan);

        if (!hasPlan) {
            trackUpgradePrompted('ai_dashboard_guide');
        }
    }, [open, hasPlan]);

    const title = __('Create a dashboard with AI');
    const description = __(
        'Your AI assistant can build a dashboard made for you, with widgets it writes just for you. Connect it and ask in your own words.',
    );
    const body = (
        <div className="flex flex-col gap-5">
            {!hasPlan && <StandardPlanBanner />}
            <GuideSteps />
        </div>
    );

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerContent data-testid="ai-dashboard-guide">
                    {/* The header scrolls with the steps, so a short phone keeps the buttons in reach. */}
                    <div className="overflow-y-auto">
                        <DrawerHeader className="items-start gap-3 group-data-[vaul-drawer-direction=bottom]/drawer-content:text-left">
                            <IconTile />
                            <DrawerTitle>{title}</DrawerTitle>
                            <DrawerDescription>{description}</DrawerDescription>
                        </DrawerHeader>
                        <div className="px-4">{body}</div>
                    </div>
                    <DrawerFooter className="gap-3 border-t">
                        {hasPlan ? (
                            <OpenAiConnectorButton className="h-11 w-full" />
                        ) : (
                            <>
                                <SeePlansButton
                                    trigger="ai_dashboard_guide"
                                    className="h-11 w-full"
                                />
                                <OpenAiConnectorButton
                                    variant="outline"
                                    className="h-11 w-full"
                                />
                            </>
                        )}
                        <DocsLink className="min-h-11 justify-center" />
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
                {body}
                <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                    <DocsLink />
                    <div className="flex items-center gap-2">
                        {hasPlan ? (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {__('Close')}
                                </Button>
                                <OpenAiConnectorButton />
                            </>
                        ) : (
                            <>
                                <OpenAiConnectorButton variant="outline" />
                                <SeePlansButton trigger="ai_dashboard_guide" />
                            </>
                        )}
                    </div>
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

function StandardPlanBanner() {
    return (
        <div
            data-testid="ai-dashboard-guide-plan-banner"
            className="flex flex-col items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <ProBadge className="bg-amber-900 text-[10px] tracking-wide text-amber-50 uppercase dark:bg-amber-200 dark:text-amber-950" />
            <p className="text-sm font-semibold">
                {__("It's a Standard plan feature")}
            </p>
            <p className="text-sm">
                {__(
                    "Custom dashboards and the AI Connector are part of the Standard plan. Here's how it works, for when you take the step.",
                )}
            </p>
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
                            "Add Whisper Money as a connector in Claude, ChatGPT or Perplexity. In :place you'll find the URL and the steps for each app.",
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

    function copyPrompt() {
        copy(prompt).then((ok) => {
            if (ok) {
                trackAiGuidePromptCopied();
            }
        });
    }

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
                onClick={copyPrompt}
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

function OpenAiConnectorButton({
    variant = 'default',
    className,
}: {
    variant?: 'default' | 'outline';
    className?: string;
}) {
    return (
        <Button asChild variant={variant} className={className}>
            <Link href={aiConnector().url}>{__('Open AI Connector')}</Link>
        </Button>
    );
}

function DocsLink({ className }: { className?: string }) {
    return (
        <a
            href={documentation.show.url(DOCS_SLUG)}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(
                'inline-flex items-center gap-1 text-sm font-medium underline underline-offset-4',
                className,
            )}
        >
            {__('Read the full guide')}
            <ArrowUpRight className="size-4" aria-hidden="true" />
        </a>
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
