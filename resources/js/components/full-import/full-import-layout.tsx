import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import {
    ArrowLeft,
    Check,
    Info,
    TriangleAlert,
    X,
    type LucideIcon,
} from 'lucide-react';
import { type PropsWithChildren, type ReactNode } from 'react';

export type WizardVariant = 'page' | 'embedded';

export interface WizardPill {
    id: string;
    label: string;
}

/** The step pills: done ones ticked, the current one filled. */
function StepPills({
    steps,
    current,
}: {
    steps: WizardPill[];
    current: string;
}) {
    const currentIndex = steps.findIndex((step) => step.id === current);

    return (
        <ol
            aria-label={__('Steps')}
            className="flex flex-wrap items-center gap-1 text-[13px]"
        >
            {steps.map((step, index) => {
                const isCurrent = index === currentIndex;
                const isDone = currentIndex === -1 || index < currentIndex;

                return (
                    <li
                        key={step.id}
                        aria-current={isCurrent ? 'step' : undefined}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1',
                            isCurrent
                                ? 'bg-primary font-medium text-primary-foreground'
                                : 'text-muted-foreground',
                        )}
                    >
                        {isDone ? (
                            <Check className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                        ) : (
                            <span className="tabular-nums">{index + 1}</span>
                        )}
                        {step.label}
                    </li>
                );
            })}
        </ol>
    );
}

/**
 * The wizard's top: a close button, its name and the step pills. On its own
 * page it is a full-width bar; inside the onboarding it is a row in the column.
 */
export function WizardHeader({
    variant,
    steps,
    current,
    onClose,
}: {
    variant: WizardVariant;
    steps: WizardPill[];
    current: string;
    onClose: () => void;
}) {
    const close = (
        <Button
            variant="outline"
            size="icon"
            aria-label={variant === 'page' ? __('Close') : __('Back')}
            onClick={onClose}
        >
            {variant === 'page' ? (
                <X className="size-4" />
            ) : (
                <ArrowLeft className="size-4" />
            )}
        </Button>
    );

    if (variant === 'embedded') {
        return (
            <div className="flex flex-wrap items-center gap-3">
                {close}
                {steps.length > 0 && (
                    <StepPills steps={steps} current={current} />
                )}
            </div>
        );
    }

    return (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b px-4 pt-[calc(0.75rem+var(--safe-area-top))] pb-3 sm:px-6">
            <div className="flex items-center gap-3">
                {close}
                <span className="font-semibold">
                    {__('Import from another app')}
                </span>
            </div>
            {steps.length > 0 && <StepPills steps={steps} current={current} />}
        </header>
    );
}

/** One screen of the wizard: eyebrow, title, description, content and actions. */
export function WizardScreen({
    eyebrow,
    title,
    description,
    footer,
    children,
}: PropsWithChildren<{
    eyebrow?: string;
    title: ReactNode;
    description?: ReactNode;
    footer?: ReactNode;
}>) {
    return (
        <div className="flex flex-col gap-7">
            <div className="flex flex-col gap-2.5">
                {eyebrow && (
                    <span className="text-[13px] font-medium text-muted-foreground">
                        {eyebrow}
                    </span>
                )}
                <h1 className="text-3xl leading-[1.14] font-semibold tracking-tight text-balance md:text-[2rem]">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-2xl text-[15px] leading-relaxed text-pretty text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            {children}

            {footer && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-5">
                    {footer}
                </div>
            )}
        </div>
    );
}

/** The Back / Continue pair every step ends with. */
export function WizardFooter({
    onBack,
    backLabel,
    note,
    primary,
}: {
    onBack: () => void;
    backLabel?: string;
    note?: ReactNode;
    primary: ReactNode;
}) {
    return (
        <>
            <Button variant="ghost" onClick={onBack}>
                <ArrowLeft className="size-4" />
                {backLabel ?? __('Back')}
            </Button>
            <div className="flex flex-wrap items-center justify-end gap-3">
                {note && (
                    <span className="text-[13px] text-muted-foreground">
                        {note}
                    </span>
                )}
                {primary}
            </div>
        </>
    );
}

/** Tints laid over the shared Alert, which only ships a neutral and a destructive one. */
const NOTICE_TONES = {
    muted: 'border-transparent bg-muted text-foreground/85',
    success:
        'border-transparent bg-emerald-50 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100',
    warning:
        'border-transparent bg-amber-50 text-amber-900 dark:bg-amber-950/50 dark:text-amber-100',
    danger: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-100',
} as const;

const NOTICE_ICONS: Record<keyof typeof NOTICE_TONES, LucideIcon> = {
    muted: Info,
    success: Check,
    warning: TriangleAlert,
    danger: TriangleAlert,
};

/**
 * A tinted aside: a caveat, a confirmation, a warning. Only a danger one is
 * announced the moment it appears; the rest are notes the reader reaches in
 * their own time.
 */
export function Notice({
    tone = 'muted',
    icon,
    className,
    children,
}: PropsWithChildren<{
    tone?: keyof typeof NOTICE_TONES;
    icon?: LucideIcon;
    className?: string;
}>) {
    const Icon = icon ?? NOTICE_ICONS[tone];

    return (
        <Alert
            role={tone === 'danger' ? 'alert' : 'note'}
            className={cn(
                'rounded-xl py-3.5 leading-normal',
                NOTICE_TONES[tone],
                className,
            )}
        >
            <Icon aria-hidden="true" />
            <AlertDescription className="gap-2 text-inherit">
                {children}
            </AlertDescription>
        </Alert>
    );
}

/** A bordered list of rows with an optional label on top. */
export function SectionCard({
    label,
    className,
    children,
}: PropsWithChildren<{ label?: ReactNode; className?: string }>) {
    return (
        <div
            className={cn(
                'flex flex-col divide-y rounded-xl border bg-card',
                className,
            )}
        >
            {label && (
                <div className="flex flex-wrap items-center gap-2 px-4 py-3 text-[13px] font-medium text-muted-foreground sm:px-5">
                    {label}
                </div>
            )}
            {children}
        </div>
    );
}

/** One row of a {@link SectionCard}: what it is on the left, what it says on the right. */
export function SectionRow({
    title,
    meta,
    muted = false,
    children,
}: PropsWithChildren<{
    title: ReactNode;
    meta?: ReactNode;
    muted?: boolean;
}>) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 px-4 py-3 sm:px-5',
                muted && 'text-muted-foreground',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                <span className="flex flex-wrap items-center gap-2 font-medium">
                    {title}
                </span>
                {meta && (
                    <span className="text-[13px] text-muted-foreground">
                        {meta}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

/** Tints laid over the shared Badge, whose own variants are all full-strength. */
const PILL_TONES = {
    neutral: 'bg-muted text-foreground/80',
    info: 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-200',
    warning:
        'bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200',
    danger: 'bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-200',
    success:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-200',
} as const;

/** A small rounded label: an account's fate, a match's confidence, a count. */
export function Pill({
    tone = 'neutral',
    children,
}: PropsWithChildren<{ tone?: keyof typeof PILL_TONES }>) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-full border-transparent px-2.5',
                PILL_TONES[tone],
            )}
        >
            {children}
        </Badge>
    );
}

/** A big number with what it counts and a line of context. */
export function StatCard({
    value,
    label,
    note,
}: {
    value: ReactNode;
    label: string;
    note?: ReactNode;
}) {
    return (
        <div className="flex min-w-36 flex-1 flex-col gap-0.5 rounded-xl border bg-card p-4">
            <span className="text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </span>
            <span className="font-medium">{label}</span>
            {note && (
                <span className="text-[13px] text-muted-foreground">
                    {note}
                </span>
            )}
        </div>
    );
}

export interface Choice<T extends string> {
    value: T;
    title: ReactNode;
    badge?: ReactNode;
    description: ReactNode;
}

/** Radio options drawn as cards, the selected one outlined. */
export function ChoiceCards<T extends string>({
    legend,
    value,
    options,
    onChange,
    className,
}: {
    legend: string;
    value: T;
    options: Choice<T>[];
    onChange: (value: T) => void;
    className?: string;
}) {
    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-3 font-medium">{legend}</legend>
            <RadioGroup
                value={value}
                onValueChange={(next) => onChange(next as T)}
                className={cn('flex flex-col gap-3', className)}
            >
                {options.map((option) => (
                    <label
                        key={option.value}
                        className={cn(
                            'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors',
                            value === option.value
                                ? 'border-primary ring-1 ring-primary'
                                : 'hover:border-foreground/30',
                        )}
                    >
                        <RadioGroupItem value={option.value} className="mt-1" />
                        <span className="flex flex-col gap-1">
                            <span className="flex flex-wrap items-center gap-2 font-semibold">
                                {option.title}
                                {option.badge}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {option.description}
                            </span>
                        </span>
                    </label>
                ))}
            </RadioGroup>
        </fieldset>
    );
}
