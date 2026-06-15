import { BankLogo } from '@/components/bank-logo';
import InputError from '@/components/input-error';
import AuthenticatedRedirectDialog from '@/components/landing/authenticated-redirect-dialog';
import InstallAppButton from '@/components/landing/install-app-button';
import Header from '@/components/partials/header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { tailwindColorClasses } from '@/components/user-info';
import { usePwaInstall } from '@/hooks/use-pwa-install';
import { cn } from '@/lib/utils';
import { store as storeUserLead } from '@/routes/user-leads';
import { type SharedData } from '@/types';
import { type CategoryColor, getCategoryColorClasses } from '@/types/category';
import { LANGUAGE_OPTIONS } from '@/types/language';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Facehash } from 'facehash';
import {
    ArrowDownLeftIcon,
    ArrowLeftRightIcon,
    ArrowUpRightIcon,
    BoltIcon,
    BriefcaseIcon,
    BusIcon,
    CheckIcon,
    ChevronDownIcon,
    ClapperboardIcon,
    CoinsIcon,
    FileSpreadsheetIcon,
    HeartPulseIcon,
    LockIcon,
    type LucideIcon,
    ShoppingBasketIcon,
    WineIcon,
    WrenchIcon,
    XIcon,
} from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

const LANDING_IMAGES = [
    {
        key: 'bank-accounts',
        light: '/images/landing/whisper.money_light_3.png',
        dark: '/images/landing/whisper.money_dark_3.png',
        alt: 'Your transactions at a glance',
        className: 'left-[-24%] group-hover:left-[-32%]',
    },
    {
        key: 'unlock-key',
        light: '/images/landing/whisper.money_light_2.png',
        dark: '/images/landing/whisper.money_dark_2.png',
        alt: 'Manage all your accounts in a single place',
        className: '',
    },
    {
        key: 'transactions',
        light: '/images/landing/whisper.money_light_1.png',
        dark: '/images/landing/whisper.money_dark_1.png',
        alt: 'Analyze your money, how it evolves, and how do you spent it',
        className: 'left-[32%] group-hover:left-[48%]',
    },
] as const;

type PopularBank = {
    name: string;
    logo: string | null;
};

type TransactionPreviewRow = {
    id: string;
    date: string;
    description: string;
    category: {
        name: string;
        color: CategoryColor;
        icon: LucideIcon;
    };
    amountInCents: number;
};

type AccountPreviewRow = {
    id: string;
    institution: string;
    name: string;
    type: string;
    color: CategoryColor;
    balanceInCents: number;
};

const TRANSACTION_PREVIEW_ROWS: ReadonlyArray<TransactionPreviewRow> = [
    {
        id: 'txn-1',
        date: 'Mar 5',
        description: 'Payroll Deposit',
        category: {
            name: 'Salary',
            color: 'green',
            icon: CoinsIcon,
        },
        amountInCents: 248500,
    },
    {
        id: 'txn-2',
        date: 'Mar 4',
        description: 'Whole Foods Market',
        category: {
            name: 'Groceries',
            color: 'red',
            icon: ShoppingBasketIcon,
        },
        amountInCents: -8742,
    },
    {
        id: 'txn-3',
        date: 'Mar 4',
        description: 'City Electric Co.',
        category: {
            name: 'Electricity',
            color: 'orange',
            icon: BoltIcon,
        },
        amountInCents: -12840,
    },
    {
        id: 'txn-4',
        date: 'Mar 3',
        description: 'Coffee Lab',
        category: {
            name: 'Cafes, restaurants, bars',
            color: 'red',
            icon: WineIcon,
        },
        amountInCents: -1295,
    },
    {
        id: 'txn-5',
        date: 'Mar 3',
        description: 'Rent Payment',
        category: {
            name: 'Rent and maintanence',
            color: 'orange',
            icon: WrenchIcon,
        },
        amountInCents: -145000,
    },
    {
        id: 'txn-6',
        date: 'Mar 2',
        description: 'Metro Transit',
        category: {
            name: 'Transportation',
            color: 'amber',
            icon: BusIcon,
        },
        amountInCents: -520,
    },
    {
        id: 'txn-7',
        date: 'Mar 1',
        description: 'Freelance Invoice',
        category: {
            name: 'Self-Employment Income',
            color: 'green',
            icon: BriefcaseIcon,
        },
        amountInCents: 62000,
    },
    {
        id: 'txn-8',
        date: 'Feb 28',
        description: 'Movie Night',
        category: {
            name: 'Theatre, music, cinema',
            color: 'violet',
            icon: ClapperboardIcon,
        },
        amountInCents: -2499,
    },
    {
        id: 'txn-9',
        date: 'Feb 28',
        description: 'Healthy Pharmacy',
        category: {
            name: 'Health and pharmaceuticals',
            color: 'rose',
            icon: HeartPulseIcon,
        },
        amountInCents: -3599,
    },
    {
        id: 'txn-10',
        date: 'Feb 27',
        description: 'Savings Transfer',
        category: {
            name: 'Personal transfers',
            color: 'cyan',
            icon: ArrowLeftRightIcon,
        },
        amountInCents: -50000,
    },
] as const;

const ACCOUNT_PREVIEW_ROWS: ReadonlyArray<AccountPreviewRow> = [
    {
        id: 'acc-1',
        institution: 'Chase',
        name: 'Main Checking',
        type: 'Checking',
        color: 'blue',
        balanceInCents: 324580,
    },
    {
        id: 'acc-2',
        institution: 'Marcus',
        name: 'High-Yield Savings',
        type: 'Savings',
        color: 'green',
        balanceInCents: 1245000,
    },
    {
        id: 'acc-3',
        institution: 'American Express',
        name: 'Gold Card',
        type: 'Credit',
        color: 'red',
        balanceInCents: -189240,
    },
    {
        id: 'acc-4',
        institution: 'Vanguard',
        name: 'Investment Portfolio',
        type: 'Investment',
        color: 'violet',
        balanceInCents: 2875000,
    },
    {
        id: 'acc-5',
        institution: 'Ally',
        name: 'Emergency Fund',
        type: 'Savings',
        color: 'cyan',
        balanceInCents: 850000,
    },
] as const;

function getBillingLabel(billingPeriod: string | null): string {
    if (!billingPeriod) {
        return 'one-time';
    }
    return '/month';
}

function FeatureCard({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]',
                className,
            )}
        >
            {children}
        </div>
    );
}

function BankConnectionsPreview({
    banks,
    prependFromBottomCount = 15,
    className,
}: {
    banks: ReadonlyArray<PopularBank>;
    prependFromBottomCount?: number;
    className?: string;
}) {
    const [translateY, setTranslateY] = useState(0);
    const [maxTranslate, setMaxTranslate] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const listRef = useRef<HTMLUListElement | null>(null);
    const scrollSpeed = 0.15;
    const prependedBanksCount = Math.min(prependFromBottomCount, banks.length);
    const previewBanks = useMemo(
        () => [...banks.slice(-prependedBanksCount), ...banks],
        [banks, prependedBanksCount],
    );

    useEffect(() => {
        const updateMaxTranslate = () => {
            const container = containerRef.current;
            const list = listRef.current;
            if (!container || !list) {
                return;
            }

            const travelDistance = Math.max(
                0,
                list.scrollHeight - container.clientHeight + 24,
            );
            setMaxTranslate(travelDistance);
        };

        updateMaxTranslate();
        window.addEventListener('resize', updateMaxTranslate);

        return () => {
            window.removeEventListener('resize', updateMaxTranslate);
        };
    }, []);

    useEffect(() => {
        const updateTranslate = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            const clampedProgress = Math.min(1, Math.max(0, progress));

            setTranslateY(clampedProgress * maxTranslate * scrollSpeed);
        };

        updateTranslate();
        window.addEventListener('scroll', updateTranslate, { passive: true });
        window.addEventListener('resize', updateTranslate);

        return () => {
            window.removeEventListener('scroll', updateTranslate);
            window.removeEventListener('resize', updateTranslate);
        };
    }, [maxTranslate, scrollSpeed]);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('Popular banks available to connect')}
            className={cn(
                'relative min-h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950',
                className,
            )}
        >
            <ul
                ref={listRef}
                className="space-y-2 p-3 transition-transform duration-75 will-change-transform sm:p-4"
                style={{ transform: `translate3d(0, -${translateY}px, 0)` }}
            >
                {previewBanks.map((bank, index) => {
                    const originalIndex =
                        (index - prependedBanksCount + banks.length) %
                        banks.length;

                    return (
                        <li
                            key={`${bank.name}-${index}`}
                            className="flex items-center gap-3 rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2.5 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                        >
                            <span className="w-6 text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]">
                                {(originalIndex + 1)
                                    .toString()
                                    .padStart(2, '0')}
                            </span>
                            <BankLogo
                                src={bank.logo}
                                name={bank.name}
                                fallback="letter"
                                className="size-7 border border-[#e3e3e0] bg-white p-0.5 dark:border-[#3E3E3A] dark:bg-[#141413]"
                            />
                            <span className="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                {bank.name}
                            </span>
                        </li>
                    );
                })}
            </ul>

            <div className="pointer-events-none absolute inset-x-0 top-0 h-14 bg-gradient-to-b from-zinc-100 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

function TransactionRowsPreview({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    const [translateY, setTranslateY] = useState(0);
    const [maxTranslate, setMaxTranslate] = useState(0);
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const listRef = useRef<HTMLUListElement | null>(null);
    const prependedRowsCount = Math.min(10, TRANSACTION_PREVIEW_ROWS.length);
    const scrollSpeed = 0.28;
    const previewRows = useMemo(
        () => [
            ...TRANSACTION_PREVIEW_ROWS.slice(-prependedRowsCount),
            ...TRANSACTION_PREVIEW_ROWS,
        ],
        [prependedRowsCount],
    );

    useEffect(() => {
        const updateMaxTranslate = () => {
            const container = containerRef.current;
            const list = listRef.current;
            if (!container || !list) {
                return;
            }

            const travelDistance = Math.max(
                0,
                list.scrollHeight - container.clientHeight + 56,
            );
            setMaxTranslate(travelDistance);
        };

        updateMaxTranslate();
        window.addEventListener('resize', updateMaxTranslate);

        return () => {
            window.removeEventListener('resize', updateMaxTranslate);
        };
    }, []);

    useEffect(() => {
        const updateTranslate = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            const clampedProgress = Math.min(1, Math.max(0, progress));

            setScrollProgress(clampedProgress);
            setTranslateY(clampedProgress * maxTranslate * scrollSpeed);
        };

        updateTranslate();
        window.addEventListener('scroll', updateTranslate, { passive: true });
        window.addEventListener('resize', updateTranslate);

        return () => {
            window.removeEventListener('scroll', updateTranslate);
            window.removeEventListener('resize', updateTranslate);
        };
    }, [maxTranslate, scrollSpeed]);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__(
                'Sample transactions with a curved scrolling effect',
            )}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            <div className="absolute inset-x-0 top-0 z-10 grid grid-cols-[60px_minmax(0,1fr)_auto] items-center rounded-t-xl border-b border-[#e3e3e0]/80 bg-[#FDFDFC]/85 px-3 py-2 text-[11px] font-semibold tracking-[0.08em] text-[#706f6c] uppercase backdrop-blur sm:px-4 dark:border-[#3E3E3A]/80 dark:bg-[#161615]/85 dark:text-[#A1A09A]">
                <span>{__('Date')}</span>
                <span>{__('Description')}</span>
                <span className="text-right">{__('Amount')}</span>
            </div>

            <ul
                ref={listRef}
                className="flex flex-col gap-2 p-3 pt-12 transition-transform duration-75 will-change-transform sm:p-4 sm:pt-12"
                style={{ transform: `translate3d(0, -${translateY}px, 0)` }}
            >
                {previewRows.map((transaction, index) => {
                    const originalIndex =
                        (index -
                            prependedRowsCount +
                            TRANSACTION_PREVIEW_ROWS.length) %
                        TRANSACTION_PREVIEW_ROWS.length;
                    const CategoryIcon = transaction.category.icon;
                    const categoryColorClasses = getCategoryColorClasses(
                        transaction.category.color,
                    );
                    const curveSeed =
                        originalIndex * 0.7 + scrollProgress * Math.PI * 2.9;
                    const curveX = Math.sin(curveSeed) * 11;
                    const curveRotate = Math.sin(curveSeed + Math.PI / 3) * 1.6;
                    const curveScale =
                        1 - Math.abs(Math.sin(curveSeed)) * 0.015;
                    const isIncome = transaction.amountInCents > 0;
                    const amountLabel = formatCurrency(
                        Math.abs(transaction.amountInCents),
                        currency,
                        locale,
                    );

                    return (
                        <li
                            key={`${transaction.id}-${index}`}
                            className="flex items-center gap-3 rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2.5 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                            style={{
                                transform: `translate3d(${curveX}px, 0, 0) rotate(${curveRotate}deg) scale(${curveScale})`,
                            }}
                        >
                            <span className="w-[60px] shrink-0 text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]">
                                {transaction.date}
                            </span>

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                    {__(transaction.description)}
                                </p>
                                <span
                                    className={cn(
                                        'mt-1 inline-flex max-w-full items-center gap-1 truncate rounded-full border border-black/10 px-2 py-0.5 text-[11px] font-medium dark:border-white/15',
                                        categoryColorClasses.bg,
                                        categoryColorClasses.text,
                                    )}
                                >
                                    <CategoryIcon className="size-3 shrink-0" />
                                    {__(transaction.category.name)}
                                </span>
                            </div>

                            <span
                                className={cn(
                                    'ml-2 flex shrink-0 items-center gap-1 text-sm font-semibold tabular-nums',
                                    isIncome
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-[#1b1b18] dark:text-[#EDEDEC]',
                                )}
                            >
                                {isIncome ? (
                                    <ArrowUpRightIcon className="size-3.5" />
                                ) : (
                                    <ArrowDownLeftIcon className="size-3.5" />
                                )}
                                <span>
                                    {isIncome ? '+' : '-'}
                                    {amountLabel}
                                </span>
                            </span>
                        </li>
                    );
                })}
            </ul>

            <div className="pointer-events-none absolute inset-x-0 top-8 h-10 bg-gradient-to-b from-zinc-100/95 to-transparent dark:from-zinc-900/95" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

function AccountsBalancePreview({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const cardCount = ACCOUNT_PREVIEW_ROWS.length;
    const staggerDelay = 0.12;
    const staggerRange = 1 - (cardCount - 1) * staggerDelay;

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('All your bank accounts at a glance')}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            {ACCOUNT_PREVIEW_ROWS.map((account, index) => {
                const cardProgress = Math.min(
                    1,
                    Math.max(
                        0,
                        (scrollProgress - index * staggerDelay) / staggerRange,
                    ),
                );

                // Stack: cards centered in container with small per-card offset
                const stackY = 130 + index * 5;
                // Spread: cards spaced evenly from top padding
                const spreadY = 16 + index * 66;
                const y = stackY + (spreadY - stackY) * cardProgress;

                // Rotation: cards tilt in the stack, straighten as they spread
                const stackRotate = (index - Math.floor(cardCount / 2)) * 2;
                const rotate = stackRotate * (1 - cardProgress);

                // Scale: back cards slightly smaller in stack, full size when spread
                const stackScale = 1 - index * 0.025;
                const scale = stackScale + (1 - stackScale) * cardProgress;

                // Opacity: back cards dimmer in stack, fully opaque when spread
                const stackOpacity = Math.max(0.45, 1 - index * 0.12);
                const opacity =
                    stackOpacity + (1 - stackOpacity) * cardProgress;

                const zIndex = (cardCount - index) * 10;
                const colorClasses = getCategoryColorClasses(account.color);
                const isDebt = account.balanceInCents < 0;
                const balanceLabel = formatCurrency(
                    Math.abs(account.balanceInCents),
                    currency,
                    locale,
                );

                return (
                    <div
                        key={account.id}
                        className="absolute inset-x-3 flex items-center gap-3 rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/90 px-3 py-2.5 will-change-transform sm:inset-x-4 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/90"
                        style={{
                            top: 0,
                            transform: `translateY(${y}px) rotate(${rotate}deg) scale(${scale})`,
                            opacity,
                            zIndex,
                        }}
                    >
                        <BankLogo
                            src={null}
                            name={account.institution}
                            fallback="letter"
                            className="size-7 shrink-0 border border-[#e3e3e0] bg-white p-0.5 dark:border-[#3E3E3A] dark:bg-[#141413]"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                {__(account.name)}
                            </p>
                            <p className="truncate text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                {account.institution}
                            </p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1">
                            <span
                                className={cn(
                                    'text-sm font-semibold tabular-nums',
                                    isDebt
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-[#1b1b18] dark:text-[#EDEDEC]',
                                )}
                            >
                                {isDebt ? '-' : ''}
                                {balanceLabel}
                            </span>
                            <span
                                className={cn(
                                    'inline-flex items-center rounded-full border border-black/10 px-2 py-0.5 text-[11px] font-medium dark:border-white/15',
                                    colorClasses.bg,
                                    colorClasses.text,
                                )}
                            >
                                {__(account.type)}
                            </span>
                        </div>
                    </div>
                );
            })}

            <div className="pointer-events-none absolute inset-x-0 top-0 h-14 bg-gradient-to-b from-zinc-100 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

function ImportPreview({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);

    // How many transaction rows to show below the file card
    const previewRows = TRANSACTION_PREVIEW_ROWS.slice(0, 5);

    // Scroll range for the file card drop: 0 → FILE_DROP_END
    const FILE_DROP_END = 0.4;
    // Each row slides in over this span of scroll progress
    const ROW_SPAN = 0.12;
    // Row i starts appearing at this scroll progress
    const rowStart = (i: number) => 0.3 + i * 0.1;

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    // File card animation: drops from above, scales and fades in
    const fileProgress = Math.min(1, scrollProgress / FILE_DROP_END);
    const fileY = -80 + 92 * fileProgress;
    const fileScale = 0.9 + 0.1 * fileProgress;
    const fileOpacity = fileProgress;

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('Import transactions from a CSV file')}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            {/* File card — drops from above */}
            <div
                className="absolute inset-x-3 flex items-center gap-3 rounded-lg border border-emerald-200 bg-[#FDFDFC] px-3 py-3 will-change-transform sm:inset-x-4 dark:border-emerald-800/60 dark:bg-[#1C1C1A]"
                style={{
                    top: 0,
                    transform: `translateY(${fileY}px) scale(${fileScale})`,
                    opacity: fileOpacity,
                }}
            >
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 dark:border-emerald-800/60 dark:bg-emerald-950/40">
                    <FileSpreadsheetIcon className="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                        transactions.csv
                    </p>
                    <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        847 KB &middot; 1,247 rows
                    </p>
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-400">
                    <CheckIcon className="size-3" />
                    {__('Imported')}
                </span>
            </div>

            {/* Transaction rows — cascade in from the right after file card lands */}
            {previewRows.map((transaction, index) => {
                const start = rowStart(index);
                const rowProgress = Math.min(
                    1,
                    Math.max(0, (scrollProgress - start) / ROW_SPAN),
                );
                const rowX = 24 * (1 - rowProgress);
                const rowOpacity = rowProgress;
                const CategoryIcon = transaction.category.icon;
                const categoryColorClasses = getCategoryColorClasses(
                    transaction.category.color,
                );
                const isIncome = transaction.amountInCents > 0;
                const amountLabel = formatCurrency(
                    Math.abs(transaction.amountInCents),
                    currency,
                    locale,
                );

                return (
                    <div
                        key={transaction.id}
                        className="absolute inset-x-3 flex items-center gap-3 rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/90 px-3 py-2.5 will-change-transform sm:inset-x-4 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/90"
                        style={{
                            top: 0,
                            transform: `translateY(${124 + index * 38}px) translateX(${rowX}px)`,
                            opacity: rowOpacity,
                        }}
                    >
                        <span className="w-[52px] shrink-0 text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]">
                            {transaction.date}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                {__(transaction.description)}
                            </p>
                        </div>
                        <span
                            className={cn(
                                'hidden shrink-0 items-center gap-1 rounded-full border border-black/10 px-2 py-0.5 text-[11px] font-medium sm:inline-flex dark:border-white/15',
                                categoryColorClasses.bg,
                                categoryColorClasses.text,
                            )}
                        >
                            <CategoryIcon className="size-3 shrink-0" />
                            {__(transaction.category.name)}
                        </span>
                        <span
                            className={cn(
                                'ml-1 shrink-0 text-sm font-semibold tabular-nums',
                                isIncome
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-[#1b1b18] dark:text-[#EDEDEC]',
                            )}
                        >
                            {isIncome ? '+' : '-'}
                            {amountLabel}
                        </span>
                    </div>
                );
            })}

            <div className="pointer-events-none absolute inset-x-0 top-0 h-10 bg-gradient-to-b from-zinc-100 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

function PrivacyRedactedPreview() {
    const PRIVACY_ROWS = [
        { label: 'Account Holder', value: 'Jonathan Mitchell' },
        { label: 'Account No.', value: 'GB29 NWBK 6016 1331 9268 19' },
        { label: 'Balance', value: '$12,450.00' },
        { label: 'Last Login', value: __('Today') + ', 9:42 AM' },
        { label: 'Statement', value: 'Q4 2024 · ' + __('Quartely') },
    ] as const;

    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);

    // Each bar slides in over this span of scroll progress
    const BAR_SPAN = 0.15;
    // Row i's bar starts sliding at this scroll progress
    const barStart = (i: number) => 0.1 + i * 0.12;

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__(
                'Your financial data stays private and is never shared',
            )}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            <ul className="flex flex-col gap-2 p-3 sm:p-4">
                {PRIVACY_ROWS.map((row, index) => {
                    const start = barStart(index);
                    const barProgress = Math.min(
                        1,
                        Math.max(0, (scrollProgress - start) / BAR_SPAN),
                    );
                    const barX = 100 * (1 - barProgress);

                    return (
                        <li
                            key={row.label}
                            className="flex items-center gap-3 rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2.5 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                        >
                            <span className="w-[110px] shrink-0 text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]">
                                {__(row.label)}
                            </span>
                            <div className="relative min-w-0 flex-1 overflow-hidden rounded">
                                <span className="block truncate text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                    {row.value}
                                </span>
                                <div
                                    className="absolute inset-0 rounded bg-zinc-900 will-change-transform dark:bg-zinc-100"
                                    style={{
                                        transform: `translateX(${barX}%)`,
                                    }}
                                />
                            </div>
                        </li>
                    );
                })}

                {/* Safe row — never redacted */}
                <li className="flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2.5 dark:border-emerald-800/50 dark:bg-emerald-950/30">
                    <LockIcon className="mr-2 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span className="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]">
                        {__('Shared')}
                    </span>
                    <span className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                        {__('only with you')}
                    </span>
                </li>
            </ul>

            <div className="pointer-events-none absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-zinc-100 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-14 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

const CASHFLOW_PREVIEW_DATA = [
    { month: 'Jan', incomeInCents: 420000, expensesInCents: 310000 },
    { month: 'Feb', incomeInCents: 385000, expensesInCents: 290000 },
    { month: 'Mar', incomeInCents: 510000, expensesInCents: 355000 },
    { month: 'Apr', incomeInCents: 448000, expensesInCents: 380000 },
    { month: 'May', incomeInCents: 495000, expensesInCents: 320000 },
    { month: 'Jun', incomeInCents: 530000, expensesInCents: 410000 },
] as const;

function CashflowChartPreview() {
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    const maxValue = Math.max(
        ...CASHFLOW_PREVIEW_DATA.map((d) =>
            Math.max(d.incomeInCents, d.expensesInCents),
        ),
    );
    const chartHeight = 200;
    const COL_SPAN = 0.28;
    const COL_START_STEP = 0.07;

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('Cashflow income vs expenses bar chart')}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            <div className="flex h-full flex-col px-4 pt-5 pb-6">
                {/* Legend */}
                <div className="mb-3 flex items-center justify-end gap-4">
                    <div className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-emerald-500" />
                        <span className="text-[10px] font-medium text-[#706f6c] dark:text-[#A1A09A]">
                            {__('Income')}
                        </span>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-rose-400" />
                        <span className="text-[10px] font-medium text-[#706f6c] dark:text-[#A1A09A]">
                            {__('Expenses')}
                        </span>
                    </div>
                </div>

                {/* Chart area */}
                <div
                    className="relative flex-1"
                    style={{ height: chartHeight }}
                >
                    {/* Dashed gridlines at 25 / 50 / 75 % */}
                    {[0.25, 0.5, 0.75].map((frac) => (
                        <div
                            key={frac}
                            className="absolute inset-x-0 border-t border-dashed border-[#e3e3e0]/80 dark:border-[#3E3E3A]/60"
                            style={{ bottom: `${frac * 100}%` }}
                        />
                    ))}

                    {/* Baseline */}
                    <div className="absolute inset-x-0 bottom-0 border-t border-[#e3e3e0] dark:border-[#3E3E3A]" />

                    {/* Columns */}
                    <div className="absolute inset-0 flex items-end justify-around px-1">
                        {CASHFLOW_PREVIEW_DATA.map((col, i) => {
                            const colProgress = Math.min(
                                1,
                                Math.max(
                                    0,
                                    (scrollProgress - i * COL_START_STEP) /
                                        COL_SPAN,
                                ),
                            );

                            const incomeH =
                                (col.incomeInCents / maxValue) *
                                chartHeight *
                                colProgress;
                            const expenseH =
                                (col.expensesInCents / maxValue) *
                                chartHeight *
                                colProgress;

                            return (
                                <div
                                    key={col.month}
                                    className="flex flex-col items-center gap-1"
                                >
                                    <div className="flex items-end gap-0.5">
                                        {/* Income bar */}
                                        <div
                                            className="w-5 rounded-t-sm bg-emerald-500 will-change-transform dark:bg-emerald-400"
                                            style={{ height: incomeH }}
                                        />
                                        {/* Expense bar */}
                                        <div
                                            className="w-5 rounded-t-sm bg-rose-400 will-change-transform dark:bg-rose-500"
                                            style={{ height: expenseH }}
                                        />
                                    </div>
                                    <span className="text-[9px] font-medium text-[#706f6c] dark:text-[#A1A09A]">
                                        {col.month}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="pointer-events-none absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-zinc-50 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

const BUDGETS_PREVIEW_ROWS = [
    {
        name: 'Groceries',
        color: 'red' as const,
        icon: ShoppingBasketIcon,
        budgetInCents: 35000,
        spentInCents: 23800,
    },
    {
        name: 'Transportation',
        color: 'amber' as const,
        icon: BusIcon,
        budgetInCents: 12000,
        spentInCents: 5400,
    },
    {
        name: 'Entertainment',
        color: 'violet' as const,
        icon: ClapperboardIcon,
        budgetInCents: 8000,
        spentInCents: 7200,
    },
    {
        name: 'Dining Out',
        color: 'orange' as const,
        icon: WineIcon,
        budgetInCents: 20000,
        spentInCents: 11000,
    },
] as const;

function BudgetsListPreview({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);

    const ROW_SLIDE_SPAN = 0.15;
    const BAR_FILL_SPAN = 0.2;
    const rowSlideStart = (i: number) => 0.05 + i * 0.1;
    const barFillStart = (i: number) => rowSlideStart(i) + 0.12;

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('Budget goals by category')}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            <ul className="flex flex-col gap-2 p-3 sm:p-4">
                {BUDGETS_PREVIEW_ROWS.map((row, index) => {
                    const slideProgress = Math.min(
                        1,
                        Math.max(
                            0,
                            (scrollProgress - rowSlideStart(index)) /
                                ROW_SLIDE_SPAN,
                        ),
                    );
                    const fillProgress = Math.min(
                        1,
                        Math.max(
                            0,
                            (scrollProgress - barFillStart(index)) /
                                BAR_FILL_SPAN,
                        ),
                    );

                    const spentPct = row.spentInCents / row.budgetInCents;
                    const filledPct = spentPct * fillProgress * 100;
                    const colorClasses = getCategoryColorClasses(row.color);
                    const Icon = row.icon;

                    return (
                        <li
                            key={row.name}
                            className="rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2.5 will-change-transform dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                            style={{
                                transform: `translateX(${32 * (1 - slideProgress)}px)`,
                                opacity: slideProgress,
                            }}
                        >
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'flex size-5 shrink-0 items-center justify-center rounded-full',
                                            colorClasses.bg,
                                        )}
                                    >
                                        <Icon
                                            className={cn(
                                                'size-2.5',
                                                colorClasses.text,
                                            )}
                                        />
                                    </span>
                                    <span className="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                        {__(row.name)}
                                    </span>
                                </div>
                                <span className="text-[10px] text-[#706f6c] dark:text-[#A1A09A]">
                                    {formatCurrency(
                                        row.spentInCents,
                                        currency,
                                        locale,
                                    )}{' '}
                                    /{' '}
                                    {formatCurrency(
                                        row.budgetInCents,
                                        currency,
                                        locale,
                                    )}
                                </span>
                            </div>

                            {/* Progress bar */}
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#e3e3e0] dark:bg-[#3E3E3A]">
                                <div
                                    className={cn(
                                        'h-full rounded-full transition-none will-change-transform',
                                        spentPct >= 0.85
                                            ? 'bg-rose-400 dark:bg-rose-500'
                                            : colorClasses.bg.split(' ')[0] +
                                                  ' ' +
                                                  colorClasses.bg
                                                      .split(' ')
                                                      .slice(1)
                                                      .join(' '),
                                    )}
                                    style={{ width: `${filledPct}%` }}
                                />
                            </div>
                        </li>
                    );
                })}
            </ul>

            <div className="pointer-events-none absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-zinc-100 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

function BudgetDetailPreview({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);

    // Grocery budget detail — 68% spent
    const budget = BUDGETS_PREVIEW_ROWS[0];
    const spentPct = budget.spentInCents / budget.budgetInCents; // 0.68
    const remainingInCents = budget.budgetInCents - budget.spentInCents;

    const BAR_SPAN = 0.35;
    const STATS_START = 0.3;
    const STATS_SPAN = 0.2;
    const ROWS_START = 0.25;
    const ROW_STEP = 0.1;
    const ROW_SPAN = 0.18;

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    const barFill =
        Math.min(1, Math.max(0, scrollProgress / BAR_SPAN)) * spentPct * 100;
    const statsProgress = Math.min(
        1,
        Math.max(0, (scrollProgress - STATS_START) / STATS_SPAN),
    );

    // Pick the grocery/food transactions
    const detailRows = TRANSACTION_PREVIEW_ROWS.slice(1, 4);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('Budget progress for Groceries')}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            <div className="flex flex-col gap-3 p-4">
                {/* Header */}
                <div className="flex items-center gap-2">
                    <span
                        className={cn(
                            'flex size-7 shrink-0 items-center justify-center rounded-full',
                            getCategoryColorClasses(budget.color).bg,
                        )}
                    >
                        <ShoppingBasketIcon
                            className={cn(
                                'size-3.5',
                                getCategoryColorClasses(budget.color).text,
                            )}
                        />
                    </span>
                    <span className="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
                        {__(budget.name)}
                    </span>
                    <span className="ml-auto text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        {Math.round(spentPct * 100)}% {__('used')}
                    </span>
                </div>

                {/* Big progress bar */}
                <div className="h-3 w-full overflow-hidden rounded-full bg-[#e3e3e0] dark:bg-[#3E3E3A]">
                    <div
                        className="h-full rounded-full bg-red-400 transition-none will-change-transform dark:bg-red-500"
                        style={{ width: `${barFill}%` }}
                    />
                </div>

                {/* Stat chips */}
                <div
                    className="grid grid-cols-2 gap-2"
                    style={{ opacity: statsProgress }}
                >
                    <div className="rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75">
                        <p className="text-[10px] text-[#706f6c] dark:text-[#A1A09A]">
                            {__('Spent')}
                        </p>
                        <p className="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
                            {formatCurrency(
                                budget.spentInCents,
                                currency,
                                locale,
                            )}
                        </p>
                    </div>
                    <div className="rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2 dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75">
                        <p className="text-[10px] text-[#706f6c] dark:text-[#A1A09A]">
                            {__('Remaining')}
                        </p>
                        <p className="text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                            {formatCurrency(remainingInCents, currency, locale)}
                        </p>
                    </div>
                </div>

                {/* Recent transactions */}
                <ul className="flex flex-col gap-1.5">
                    {detailRows.map((row, i) => {
                        const rowProgress = Math.min(
                            1,
                            Math.max(
                                0,
                                (scrollProgress - (ROWS_START + i * ROW_STEP)) /
                                    ROW_SPAN,
                            ),
                        );
                        const Icon = row.category.icon;
                        return (
                            <li
                                key={row.id}
                                className="flex items-center gap-2.5 rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2 will-change-transform dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                                style={{
                                    transform: `translateY(${8 * (1 - rowProgress)}px)`,
                                    opacity: rowProgress,
                                }}
                            >
                                <span
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full',
                                        getCategoryColorClasses(
                                            row.category.color,
                                        ).bg,
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            'size-2.5',
                                            getCategoryColorClasses(
                                                row.category.color,
                                            ).text,
                                        )}
                                    />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-xs text-[#1b1b18] dark:text-[#EDEDEC]">
                                    {row.description}
                                </span>
                                <span className="shrink-0 text-xs font-medium text-rose-500 dark:text-rose-400">
                                    {formatCurrency(
                                        Math.abs(row.amountInCents),
                                        currency,
                                        locale,
                                    )}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className="pointer-events-none absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-zinc-50 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

function BudgetEditPreview() {
    const [scrollProgress, setScrollProgress] = useState(0);
    const containerRef = useRef<HTMLDivElement | null>(null);

    // "Dining Out" budget — 90% spent (high alert)
    const budget = BUDGETS_PREVIEW_ROWS[2]; // Entertainment, 90%
    const diningBudget = BUDGETS_PREVIEW_ROWS[3]; // Dining Out, 55% for the row display
    const spentPct = budget.spentInCents / budget.budgetInCents;

    const ROW_SPAN = 0.2;
    const ALERT_START = 0.18;
    const ALERT_SPAN = 0.22;
    const ACTION_START = 0.35;
    const ACTION_SPAN = 0.18;

    useEffect(() => {
        const updateProgress = () => {
            const container = containerRef.current;
            if (!container) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const progress =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setScrollProgress(Math.min(1, Math.max(0, progress)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    const rowProgress = Math.min(1, Math.max(0, scrollProgress / ROW_SPAN));
    const alertProgress = Math.min(
        1,
        Math.max(0, (scrollProgress - ALERT_START) / ALERT_SPAN),
    );
    const actionProgress = Math.min(
        1,
        Math.max(0, (scrollProgress - ACTION_START) / ACTION_SPAN),
    );

    const Icon = budget.icon;
    const DiningIcon = diningBudget.icon;
    const colorClasses = getCategoryColorClasses(budget.color);
    const diningColorClasses = getCategoryColorClasses(diningBudget.color);
    const filledWidth = spentPct * rowProgress * 100;

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__('Budget alert and limit adjustment')}
            className="relative h-[320px] overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 select-none dark:border-[#3E3E3A]/30 dark:from-zinc-900 dark:to-zinc-950"
        >
            <div className="flex flex-col gap-3 p-4">
                {/* Budget row */}
                <div
                    className="rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2.5 will-change-transform dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                    style={{ opacity: rowProgress }}
                >
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className={cn(
                                    'flex size-5 shrink-0 items-center justify-center rounded-full',
                                    colorClasses.bg,
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'size-2.5',
                                        colorClasses.text,
                                    )}
                                />
                            </span>
                            <span className="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                {__(budget.name)}
                            </span>
                        </div>
                        <span className="text-[10px] font-semibold text-rose-500 dark:text-rose-400">
                            {Math.round(spentPct * 100)}%
                        </span>
                    </div>
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#e3e3e0] dark:bg-[#3E3E3A]">
                        <div
                            className="h-full rounded-full bg-rose-400 transition-none will-change-transform dark:bg-rose-500"
                            style={{ width: `${filledWidth}%` }}
                        />
                    </div>
                </div>

                {/* Alert card */}
                <div
                    className="rounded-lg border border-amber-200 bg-amber-50/80 px-3 py-3 will-change-transform dark:border-amber-800/50 dark:bg-amber-950/30"
                    style={{
                        transform: `translateY(${-14 * (1 - alertProgress)}px)`,
                        opacity: alertProgress,
                    }}
                >
                    <div className="flex items-start gap-2.5">
                        <div className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-800/50">
                            <span className="text-[10px] font-bold text-amber-600 dark:text-amber-400">
                                !
                            </span>
                        </div>
                        <div>
                            <p className="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                {__("You've used 90% of your budget")}
                            </p>
                            <p className="mt-0.5 text-[10px] text-amber-700/80 dark:text-amber-400/80">
                                {__(
                                    'Entertainment · Only $8 remaining this month',
                                )}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Dining out row (second budget, calmer) */}
                <div
                    className="rounded-lg border border-[#e3e3e0]/85 bg-[#FDFDFC]/75 px-3 py-2.5 will-change-transform dark:border-[#3E3E3A]/80 dark:bg-[#1C1C1A]/75"
                    style={{
                        opacity: actionProgress,
                        transform: `translateY(${8 * (1 - actionProgress)}px)`,
                    }}
                >
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className={cn(
                                    'flex size-5 shrink-0 items-center justify-center rounded-full',
                                    diningColorClasses.bg,
                                )}
                            >
                                <DiningIcon
                                    className={cn(
                                        'size-2.5',
                                        diningColorClasses.text,
                                    )}
                                />
                            </span>
                            <span className="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                {__(diningBudget.name)}
                            </span>
                        </div>
                        <span className="text-[10px] text-[#706f6c] dark:text-[#A1A09A]">
                            {Math.round(
                                (diningBudget.spentInCents /
                                    diningBudget.budgetInCents) *
                                    100,
                            )}
                            %
                        </span>
                    </div>
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#e3e3e0] dark:bg-[#3E3E3A]">
                        <div
                            className={cn(
                                'h-full rounded-full transition-none',
                                diningColorClasses.bg.split(' ')[0] +
                                    ' ' +
                                    diningColorClasses.bg
                                        .split(' ')
                                        .slice(1)
                                        .join(' '),
                            )}
                            style={{
                                width: `${(diningBudget.spentInCents / diningBudget.budgetInCents) * actionProgress * 100}%`,
                            }}
                        />
                    </div>
                </div>

                {/* Adjust limit action */}
                <div
                    className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2.5 will-change-transform dark:border-emerald-800/50 dark:bg-emerald-950/30"
                    style={{
                        opacity: actionProgress,
                        transform: `translateY(${10 * (1 - actionProgress)}px)`,
                    }}
                >
                    <div className="flex items-center gap-2">
                        <CheckIcon className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span className="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                            {__('Limit adjusted')}
                        </span>
                    </div>
                    <span className="text-[10px] text-[#706f6c] dark:text-[#A1A09A]">
                        {__('Entertainment → $100')}
                    </span>
                </div>
            </div>

            <div className="pointer-events-none absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-zinc-50 to-transparent dark:from-zinc-900" />
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-zinc-100 to-transparent dark:from-zinc-950" />
        </div>
    );
}

/**
 * Features reserved for paid plans. On the free plan card these are shown
 * dimmed with an X to set expectations before sign-up.
 */
const PRO_ONLY_FEATURES = [
    'Connect bank accounts',
    'AI Suggestions',
    'Priority support',
];

function FreePlanCard({ features }: { features: string[] }) {
    const excluded = new Set(PRO_ONLY_FEATURES);

    return (
        <div className="flex flex-col overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]">
            <div className="flex flex-1 flex-col p-6 pt-2 sm:pt-12">
                <h3 className="text-lg font-semibold">{__('Free')}</h3>

                <div className="mt-3 flex items-baseline gap-2">
                    <span className="text-4xl font-bold tracking-tight">
                        {__('Free')}
                    </span>
                    <span className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        {__('forever')}
                    </span>
                </div>

                <p className="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    {__(
                        'Get started at no cost. No bank connections included.',
                    )}
                </p>

                <div className="my-5 h-px bg-[#e3e3e0] dark:bg-[#3E3E3A]" />

                <p className="mt-1 text-xs text-[#706f6c] opacity-0 dark:text-[#A1A09A]">
                    {__('Create the account now, update at any moment')}
                </p>

                <ul className="flex-1 space-y-2.5">
                    {features.map((feature) => {
                        const isExcluded = excluded.has(feature);

                        return (
                            <li
                                key={feature}
                                className={cn(
                                    'flex items-center gap-2.5',
                                    isExcluded && 'opacity-40',
                                )}
                            >
                                {isExcluded ? (
                                    <XIcon className="size-4 shrink-0 text-[#706f6c] dark:text-[#A1A09A]" />
                                ) : (
                                    <CheckIcon className="size-4 shrink-0 text-[#1b1b18] dark:text-[#EDEDEC]" />
                                )}
                                <span className="text-sm">{__(feature)}</span>
                            </li>
                        );
                    })}
                </ul>

                <Link href="/register" className="mt-8">
                    <Button
                        className="w-full cursor-pointer border-[#e3e3e0] bg-transparent py-5 text-base text-[#1b1b18] shadow-sm transition-all hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1f1f1e]"
                        variant="outline"
                    >
                        {__('Get Started Free')}
                    </Button>
                </Link>
            </div>
        </div>
    );
}

function LandingPlanCard({
    plan,
    isDefault,
    isBestValue,
    currency,
    locale,
}: {
    plan: Plan;
    isDefault: boolean;
    isBestValue: boolean;
    promoEnabled: boolean;
    promoBadge: string;
    currency: string;
    locale: string;
}) {
    const monthlyEquivalent =
        plan.billing_period === 'year' ? plan.price / 12 : plan.price;

    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#161615]',
                isDefault && 'ring-2 ring-[#1b1b18] dark:ring-[#EDEDEC]',
            )}
        >
            {(isDefault || isBestValue) && (
                <div
                    className={cn(
                        'px-6 pt-6 text-xs font-semibold uppercase',
                        isDefault && 'text-[#1b1b18]/75 dark:text-[#aaa]',
                        isBestValue &&
                            !isDefault &&
                            'text-[#706f6c] dark:text-[#A1A09A]',
                    )}
                >
                    {isDefault ? __('Most Popular') : __('Best Value')}
                </div>
            )}

            <div
                className={cn(
                    'flex flex-1 flex-col p-6 pt-2',
                    !isDefault && !isBestValue && 'sm:pt-12',
                )}
            >
                <h3 className="text-lg font-semibold">{__(plan.name)}</h3>

                <div className="mt-3 flex items-baseline gap-2">
                    {plan.original_price && (
                        <span className="text-lg font-medium text-[#706f6c] line-through dark:text-[#A1A09A]">
                            {formatCurrency(
                                (plan.billing_period === 'year'
                                    ? plan.original_price / 12
                                    : plan.original_price) * 100,
                                currency,
                                locale,
                            )}
                        </span>
                    )}
                    <span className="text-4xl font-bold tracking-tight">
                        {formatCurrency(
                            monthlyEquivalent * 100,
                            currency,
                            locale,
                        )}
                    </span>
                    <span className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        {getBillingLabel(plan.billing_period)}
                    </span>
                </div>

                {plan.billing_period === 'year' && (
                    <p className="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        {__('Billed annually at')}{' '}
                        {formatCurrency(plan.price * 100, currency, locale)}
                    </p>
                )}

                <p className="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    {__(
                        'Everything you need to manage your finances securely.',
                    )}
                </p>

                <div className="my-5 h-px bg-[#e3e3e0] dark:bg-[#3E3E3A]" />

                <ul className="flex-1 space-y-2.5">
                    {plan.features.map((feature) => (
                        <li key={feature} className="flex items-center gap-2.5">
                            <CheckIcon className="size-4 shrink-0 text-[#1b1b18] dark:text-[#EDEDEC]" />
                            <span className="text-sm">{__(feature)}</span>
                        </li>
                    ))}
                </ul>

                <Link href="/register" className="mt-8">
                    <Button
                        className={cn(
                            'w-full cursor-pointer py-5 text-base shadow-sm transition-all',
                            isDefault
                                ? 'bg-gradient-to-t from-zinc-700 to-zinc-900 text-white hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] hover:dark:from-zinc-50'
                                : 'border-[#e3e3e0] bg-transparent text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1f1f1e]',
                        )}
                        variant={isDefault ? 'default' : 'outline'}
                    >
                        {__('Get Started')}
                    </Button>
                </Link>
            </div>
        </div>
    );
}

function FaqItem({ question, answer }: { question: string; answer: string }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
            <button
                type="button"
                className="flex w-full cursor-pointer items-center justify-between py-5 text-left"
                onClick={() => setOpen(!open)}
            >
                <span className="text-base font-medium">{question}</span>
                <ChevronDownIcon
                    className={cn(
                        'size-5 shrink-0 text-[#706f6c] transition-transform duration-200 dark:text-[#A1A09A]',
                        open && 'rotate-180',
                    )}
                />
            </button>
            <div
                className={cn(
                    'grid transition-all duration-200',
                    open ? 'grid-rows-[1fr] pb-5' : 'grid-rows-[0fr]',
                )}
            >
                <div className="overflow-hidden">
                    <p className="text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                        {answer}
                    </p>
                </div>
            </div>
        </div>
    );
}

function WaitlistForm() {
    const [referrerCode, setReferrerCode] = useState('');
    const { locale } = usePage<SharedData>().props;

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const ref = params.get('ref');
        if (ref) {
            setReferrerCode(ref);
        }
    }, []);

    return (
        <Form
            {...storeUserLead.form()}
            className="flex w-full flex-col gap-3"
            disableWhileProcessing
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="referrer_code"
                        value={referrerCode}
                    />
                    <input type="hidden" name="locale" value={locale} />
                    <div className="flex w-full flex-col gap-1.5">
                        <div className="flex w-full flex-row gap-2">
                            <Input
                                type="email"
                                name="email"
                                required
                                autoComplete="email"
                                placeholder={__('Your email address')}
                                className="h-14 flex-1 text-base"
                            />
                            <Button
                                type="submit"
                                className="text-shadow h-14 shrink-0 cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 px-6 text-base text-white shadow-sm transition-all duration-200 hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:from-zinc-50"
                            >
                                {processing && <Spinner />}
                                {__('Join Waitlist')}
                            </Button>
                        </div>
                        <InputError message={errors.email} />
                    </div>
                </>
            )}
        </Form>
    );
}

export default function Welcome({
    canRegister,
    hideAuthButtons,
    popularBanks,
}: {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
    popularBanks: PopularBank[];
}) {
    const { appUrl, auth, subscriptionsEnabled, pricing, locale } =
        usePage<SharedData>().props;
    const planEntries = Object.entries(pricing.plans);
    const { isMobile } = usePwaInstall();

    const testimonials = [
        {
            name: 'Brian Bansuela',
            gravatar: '9314f776a17ae977871076ac71f2ff60',
            text: __(
                'I just started syncing my accounts and it already feels like a great app. The interface is lovely and it looks like a really solid tool. Great work!',
            ),
        },
        {
            name: 'David Carrión',
            gravatar: '2a0a1e872f2f883da214b65e1c2e2156',
            text: __(
                "I have a lot of faith in this project — it's really well built and your MVP is fantastic. Thanks for everything!",
            ),
        },
        {
            name: 'Jorge Navarrete',
            gravatar: 'd20d4e05a100d5b20b45c84f3c566a25',
            text: __(
                "I'm exploring the web app and the design and UX are excellent. Thanks, team!",
            ),
        },
        {
            name: 'Marcus Oliveira',
            gravatar: '3c4342baddf0beb8b0bd9fe89168e282',
            text: __(
                'Thank you for developing Whisper Money. The focus on privacy and centralizing finances is an excellent proposition.',
            ),
        },
        {
            name: 'Carla Álvarez',
            gravatar: '9901ee5e849cf9a0caea00e897cb8123',
            text: __(
                'I found Whisper Money and instantly knew I needed it. I was stuck doing everything in a spreadsheet — a chore I kept putting off. This makes it effortless.',
            ),
        },
        {
            name: 'Yaritza Rey',
            gravatar: 'a519f143865c358b013f3f6dcdbc387a',
            text: __(
                'Thank you so much for creating such a clean, simple app.',
            ),
        },
        {
            name: 'Will Harris',
            gravatar: 'c6fbc4911d6143fe723a42f46230275e',
            text: __('Great project!'),
        },
        {
            name: 'Haru',
            gravatar: '3e52d6b2cbefb0fa2a572a588b3f7953',
            text: __('I love this project!'),
        },
        {
            name: 'Tom',
            gravatar: 'd721bb1875ac11132d4d33295867cbd9',
            text: __(
                "I'm genuinely happy using an open-source project with a real commitment to privacy — that's exactly what I want from a finance app.",
            ),
        },
        {
            name: 'Elena',
            gravatar: '9867fc6636afc02ae519820e657e4485',
            text: __(
                "I can't wait to discover everything the app can do. Thank you — it must have taken a tremendous effort. Congratulations!",
            ),
        },
        {
            name: 'Albert G.',
            gravatar: 'bb92a036f4feb9d12d0a70dd2d9a5c5f',
            text: __(
                'The app is intuitive, functional, and a real help for managing my finances day to day. What stands out most is how much the free version offers — it really shows your commitment to your users. I’ll keep recommending it!',
            ),
        },
        {
            name: 'Víctor Falcón (co-owner)',
            gravatar: '50901af884c50a8f12804b0cf3aeb98a',
            text: __(
                'I built the app I needed to make better decisions. Understanding how I spend and where my income comes from has brought me real financial peace of mind.',
            ),
        },
    ];
    const half = Math.ceil(testimonials.length / 2);
    const testimonialRows = [
        testimonials.slice(0, half),
        testimonials.slice(half),
    ];

    const hasMonthlyAndYearly =
        planEntries.some(([, p]) => p.billing_period === 'month') &&
        planEntries.some(([, p]) => p.billing_period === 'year');

    const [billingPeriod, setBillingPeriod] = useState<'month' | 'year'>(
        'year',
    );

    const yearlyDiscount = useMemo(() => {
        const monthlyPlan = planEntries.find(
            ([, p]) => p.billing_period === 'month',
        )?.[1];
        const yearlyPlan = planEntries.find(
            ([, p]) => p.billing_period === 'year',
        )?.[1];

        if (!monthlyPlan || !yearlyPlan || monthlyPlan.price === 0) {
            return null;
        }

        const yearlyMonthlyEquivalent = yearlyPlan.price / 12;
        const discount = Math.round(
            (1 - yearlyMonthlyEquivalent / monthlyPlan.price) * 100,
        );

        return discount > 0 ? discount : null;
    }, [planEntries]);

    const displayedPlanEntries = hasMonthlyAndYearly
        ? planEntries.filter(([, p]) => p.billing_period === billingPeriod)
        : planEntries;

    // Handle localStorage for language preference
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        const langParam = urlParams.get('lang');

        const supportedLocales: readonly string[] = LANGUAGE_OPTIONS.map(
            (option) => option.code,
        );

        if (langParam && supportedLocales.includes(langParam)) {
            // Store the preference in localStorage
            localStorage.setItem('whisper_landing_locale', langParam);
        } else {
            // No query param - check if we have a stored preference
            const storedLocale = localStorage.getItem('whisper_landing_locale');

            if (
                storedLocale &&
                storedLocale !== locale &&
                supportedLocales.includes(storedLocale)
            ) {
                // Redirect to stored preference
                window.location.href = `/?lang=${storedLocale}`;
                return;
            } else if (!storedLocale && locale) {
                // First visit - store the detected locale from session/header
                localStorage.setItem('whisper_landing_locale', locale);
            }
        }
    }, [locale]);

    const [isPwa] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            ('standalone' in navigator &&
                (navigator as Navigator & { standalone: boolean }).standalone)
        );
    });

    useEffect(() => {
        if (isPwa) {
            router.visit('/dashboard');
        }
    }, [isPwa]);

    if (isPwa) {
        return (
            <div className="flex h-screen items-center justify-center">
                <Spinner className="size-8" />
            </div>
        );
    }

    return (
        <>
            <Head
                title={__(
                    'Whisper Money - The Most Secure Personal Finance App',
                )}
            >
                <meta
                    name="description"
                    content={__(
                        'The most secure privacy-first personal finance app. Track expenses, create budgets, and manage your money privately.',
                    )}
                />

                <meta
                    name="keywords"
                    content={__(
                        'finance app, budgeting, expense tracking, secure finance, personal finance, money management, privacy, privacy-first finance app',
                    )}
                />

                <link rel="canonical" href={appUrl} />

                <meta property="og:site_name" content="Whisper Money" />
                <meta
                    property="og:title"
                    content={__(
                        'Whisper Money - The Most Secure Personal Finance App',
                    )}
                />

                <meta
                    property="og:description"
                    content={__(
                        'Your financial data stays private. The most secure way to manage your personal finances.',
                    )}
                />

                <meta property="og:type" content="website" />
                <meta property="og:url" content={appUrl} />
                <meta
                    property="og:image"
                    content={`${appUrl}/images/og_whisper_money.png`}
                />

                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta
                    property="og:image:alt"
                    content={__('Whisper Money - Secure Personal Finance App')}
                />

                <meta property="og:locale" content={__('en_US')} />

                <meta name="twitter:card" content={__('summary_large_image')} />
                <meta
                    name="twitter:title"
                    content={__(
                        'Whisper Money - The Most Secure Personal Finance App',
                    )}
                />

                <meta
                    name="twitter:description"
                    content={__(
                        'Your financial data stays private. The most secure way to manage your personal finances.',
                    )}
                />

                <meta
                    name="twitter:image"
                    content={`${appUrl}/images/og_whisper_money.png`}
                />

                <meta
                    name="twitter:image:alt"
                    content={__('Whisper Money - Secure Personal Finance App')}
                />

                <script type="application/ld+json">
                    {JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'WebApplication',
                        name: 'Whisper Money',
                        description:
                            'The most secure privacy-first personal finance app. Track expenses, create budgets, and manage your money privately.',
                        url: appUrl,
                        applicationCategory: 'FinanceApplication',
                        featureList: [
                            'Privacy-first design',
                            'Smart budgeting',
                            'Expense tracking',
                            'Visual insights',
                            'Zero tracking',
                            'Open source',
                        ],
                    })}
                </script>
            </Head>
            <AuthenticatedRedirectDialog open={Boolean(auth.user)} />
            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <Header
                    canRegister={canRegister}
                    hideAuthButtons={hideAuthButtons}
                />

                <main className="flex flex-1 flex-col">
                    <section className="relative w-full overflow-hidden px-6 py-28 sm:py-32 md:py-40">
                        <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-12">
                            <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col items-start gap-6 sm:gap-8">
                                <span className="inline-flex items-center gap-2 rounded-full border border-[#e3e3e0] px-2.5 py-1 text-[0.8rem] font-medium dark:border-[#3E3E3A]">
                                    <LockIcon className="size-3.5 opacity-75" />
                                    <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                        {__('Private & Secure')}
                                    </span>
                                </span>
                                <h1 className="font-heading max-w-[840px] bg-gradient-to-r from-[#1b1b18] to-[#1b1b18] bg-clip-text text-4xl leading-tight font-semibold text-balance text-transparent drop-shadow-2xl sm:text-5xl sm:leading-tight lg:text-6xl lg:leading-tight dark:from-[#EDEDEC] dark:to-[#A1A09A]">
                                    {__(
                                        'All your money in one place. No spreadsheets. Private.',
                                    )}
                                </h1>
                                <p className="mb-4 max-w-[840px] text-lg leading-8 font-medium text-balance text-[#706f6c] lg:text-xl lg:leading-8 dark:text-[#A1A09A]">
                                    {__(
                                        'Understand your finances and make better decisions without the friction. Track expenses, create budgets, and achieve your goals\u2014all in one place.',
                                    )}
                                </p>
                                <div className="flex w-full max-w-lg flex-col gap-4">
                                    {hideAuthButtons ? (
                                        <WaitlistForm />
                                    ) : isMobile ? (
                                        <InstallAppButton />
                                    ) : (
                                        <div className="flex w-full flex-row gap-4">
                                            <Link
                                                href="/register"
                                                className="w-full"
                                            >
                                                <Button className="text-shadow duration h-14 w-full cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:bg-[#eeeeec] dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] dark:hover:bg-white hover:dark:from-zinc-50 dark:hover:shadow-md">
                                                    {__('Get Started')}
                                                </Button>
                                            </Link>
                                            <Link href="/login?demo=1">
                                                <Button
                                                    variant={'secondary'}
                                                    size={'lg'}
                                                    className="h-14"
                                                >
                                                    {__('Check Demo')}
                                                </Button>
                                            </Link>
                                        </div>
                                    )}
                                    <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                        {hideAuthButtons
                                            ? __(
                                                  "Join the waiting list. We'll let you know when you're in.",
                                              )
                                            : __(
                                                  'Your data stays private. Always.',
                                              )}
                                    </p>
                                </div>
                            </div>

                            <div className="group relative sm:px-24">
                                {LANDING_IMAGES.map((image, index) => (
                                    <div
                                        key={image.key}
                                        className={cn(
                                            'relative z-10 transition-all delay-200 duration-700 ease-in-out',
                                            'rotate-[-24deg] skew-y-12 group-hover:rotate-[-12deg] group-hover:skew-y-6',
                                            'max-sm:rotate-[-16deg] max-sm:skew-y-8 max-sm:group-hover:rotate-[-8deg] max-sm:group-hover:skew-y-4',
                                            index < LANDING_IMAGES.length - 1 &&
                                                'h-[24px]',
                                            image.className,
                                        )}
                                    >
                                        <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
                                            <div className="relative z-10 overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                                                <div className="rounded-lg border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]">
                                                    <img
                                                        src={image.light}
                                                        alt={image.alt}
                                                        className="w-full rounded-lg dark:hidden"
                                                    />

                                                    <img
                                                        src={image.dark}
                                                        alt={image.alt}
                                                        className="hidden w-full rounded-lg dark:block"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}

                                <div
                                    data-slot="glow"
                                    className="animate-appear-zoom absolute top-[50%] mt-32 w-full opacity-0 delay-2000 lg:mt-4"
                                >
                                    <div className="absolute left-1/2 h-[256px] w-[60%] -translate-x-1/2 -translate-y-1/2 scale-[2.5] rounded-[50%] bg-radial from-[#1b1b18]/50 from-10% to-[#1b1b18]/0 to-60% opacity-20 sm:h-[512px] dark:from-[#EDEDEC]/50 dark:to-[#EDEDEC]/0 dark:opacity-100"></div>
                                    <div className="absolute left-1/2 h-[128px] w-[40%] -translate-x-1/2 -translate-y-1/2 scale-200 rounded-[50%] bg-radial from-[#1b1b18]/30 from-10% to-[#1b1b18]/0 to-60% opacity-20 sm:h-[256px] dark:from-[#EDEDEC]/30 dark:to-[#EDEDEC]/0 dark:opacity-100"></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-6 px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto grid max-w-7xl gap-3 sm:grid-cols-3">
                            {/* Row 1: Connect Your Banks (2 cols) + Import in Seconds (1 col) */}
                            <FeatureCard className="sm:col-span-2">
                                <div className="grid h-full grid-rows-1 gap-0 sm:grid-cols-2">
                                    <div className="flex flex-col justify-center p-8 sm:p-12">
                                        <h2 className="text-3xl leading-tight font-semibold text-balance sm:text-4xl sm:leading-tight">
                                            {__('Connect Your Banks')}
                                        </h2>
                                        <p className="mt-4 text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'Link your bank accounts directly. Transactions sync automatically, giving you a real-time view of your finances.',
                                            )}
                                        </p>
                                        <ul className="mt-6 space-y-3">
                                            <li className="flex items-center gap-2.5">
                                                <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                                <span className="text-sm">
                                                    {__('Connect in seconds')}
                                                </span>
                                            </li>
                                            <li className="flex items-center gap-2.5">
                                                <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                                <span className="text-sm">
                                                    {__('Automatic sync')}
                                                </span>
                                            </li>
                                            <li className="flex items-center gap-2.5">
                                                <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                                <span className="text-sm">
                                                    {__('Secure & encrypted')}
                                                </span>
                                            </li>
                                        </ul>
                                    </div>
                                    <div className="relative min-h-[320px]">
                                        <BankConnectionsPreview
                                            banks={popularBanks}
                                            className="absolute inset-2"
                                        />
                                    </div>
                                </div>
                            </FeatureCard>

                            <FeatureCard>
                                <div className="p-2">
                                    <ImportPreview
                                        currency={pricing.currency}
                                        locale={locale}
                                    />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('Import in Seconds')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            "Export a CSV or XLS from your bank and drag it in. A year's worth of transactions imported in under 10 seconds.",
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>

                            {/* Row 2: All Your Accounts, Every Transaction, Your Data Your Rules */}
                            <FeatureCard>
                                <div className="p-2">
                                    <AccountsBalancePreview
                                        currency={pricing.currency}
                                        locale={locale}
                                    />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('All Your Accounts')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'See every account in one place. Track balances, monitor changes, and always know where you stand.',
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>

                            <FeatureCard>
                                <div className="p-2">
                                    <TransactionRowsPreview
                                        currency={pricing.currency}
                                        locale={locale}
                                    />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('Every Transaction')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'Search, filter, and categorize with ease. Understand exactly where your money goes.',
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>

                            <FeatureCard>
                                <div className="p-2">
                                    <PrivacyRedactedPreview />
                                </div>
                                <div className="p-6 pt-4">
                                    <h3 className="text-xl font-semibold">
                                        {__('Your Data, Your Rules')}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {__(
                                            'No third-party sharing, no AI snooping. Your financial data belongs to you and only you.',
                                        )}
                                    </p>
                                </div>
                            </FeatureCard>

                            {/* Row 3: Cashflow at a Glance (always full width) */}
                            <FeatureCard className="sm:col-span-3">
                                <div className="grid items-center gap-0 sm:grid-cols-2">
                                    <div className="p-2">
                                        <CashflowChartPreview />
                                    </div>
                                    <div className="p-8 sm:p-12">
                                        <h2 className="text-3xl leading-tight font-semibold sm:text-4xl sm:leading-tight">
                                            {__('Cashflow at a Glance')}
                                        </h2>
                                        <p className="mt-4 text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'Visualize your money flow over time. See income vs. expenses and spot trends before they become problems.',
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </FeatureCard>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto flex max-w-7xl flex-col gap-8 sm:gap-12">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                    {__('Smart Budgets')}
                                </h2>
                                <p className="text-md max-w-[640px] font-medium text-[#706f6c] sm:text-xl dark:text-[#A1A09A]">
                                    {__(
                                        'Create budgets that adapt to your spending habits and help you reach your goals.',
                                    )}
                                </p>
                            </div>

                            <div className="grid gap-6 sm:grid-cols-3">
                                <FeatureCard>
                                    <div className="p-2">
                                        <BudgetsListPreview
                                            currency={pricing.currency}
                                            locale={locale}
                                        />
                                    </div>
                                    <div className="p-6 pt-4">
                                        <h3 className="text-xl font-semibold">
                                            {__('Set Your Goals')}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'Define monthly budgets by category. Know exactly how much you can spend.',
                                            )}
                                        </p>
                                    </div>
                                </FeatureCard>

                                <FeatureCard>
                                    <div className="p-2">
                                        <BudgetDetailPreview
                                            currency={pricing.currency}
                                            locale={locale}
                                        />
                                    </div>
                                    <div className="p-6 pt-4">
                                        <h3 className="text-xl font-semibold">
                                            {__('Track Progress')}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                'See where you stand in real-time. Visual progress bars show spending vs. budget.',
                                            )}
                                        </p>
                                    </div>
                                </FeatureCard>

                                <FeatureCard>
                                    <div className="p-2">
                                        <BudgetEditPreview />
                                    </div>
                                    <div className="p-6 pt-4">
                                        <h3 className="text-xl font-semibold">
                                            {__('Stay on Track')}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            {__(
                                                "Get notified when you're close to your limit. Never overspend again.",
                                            )}
                                        </p>
                                    </div>
                                </FeatureCard>
                            </div>
                        </div>
                    </section>

                    <section className="w-full overflow-hidden px-0 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto flex max-w-7xl flex-col items-center gap-8 px-4 text-center sm:gap-12">
                            <div className="flex flex-col items-center gap-4">
                                <h2 className="max-w-[720px] text-3xl leading-tight font-semibold text-balance sm:text-4xl sm:leading-tight">
                                    {__(
                                        'Trusted by people who value their privacy',
                                    )}
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                    {__(
                                        'Join thousands of users who have taken control of their finances without compromising their privacy.',
                                    )}
                                </p>
                            </div>

                            <div className="relative flex w-full flex-col gap-4">
                                {testimonialRows.map((row, rowIndex) => (
                                    <div
                                        key={rowIndex}
                                        className={cn(
                                            'group flex flex-row [gap:var(--gap)] overflow-hidden p-2 [--gap:1rem]',
                                            rowIndex === 0
                                                ? '[--duration:34s]'
                                                : '[--duration:27s]',
                                        )}
                                    >
                                        {[0, 1].map((copy) => (
                                            <div
                                                key={copy}
                                                aria-hidden={copy === 1}
                                                className={cn(
                                                    'animate-marquee flex shrink-0 flex-row [gap:var(--gap)] group-hover:[animation-play-state:paused]',
                                                    rowIndex === 1 &&
                                                        '[animation-delay:-13s]',
                                                )}
                                            >
                                                {row.map((testimonial) => (
                                                    <div
                                                        key={`${rowIndex}-${copy}-${testimonial.name}`}
                                                        className="flex w-[300px] shrink-0 flex-col rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-6 text-start shadow-sm sm:w-[360px] dark:border-[#3E3E3A] dark:bg-[#161615]"
                                                    >
                                                        <div className="flex items-center gap-3">
                                                            <Avatar className="size-10">
                                                                <AvatarImage
                                                                    src={`https://www.gravatar.com/avatar/${testimonial.gravatar}?s=160&d=404`}
                                                                    alt={
                                                                        testimonial.name
                                                                    }
                                                                    loading="lazy"
                                                                    className="object-cover"
                                                                />
                                                                <AvatarFallback>
                                                                    <Facehash
                                                                        name={
                                                                            testimonial.name
                                                                        }
                                                                        size={
                                                                            40
                                                                        }
                                                                        colorClasses={
                                                                            tailwindColorClasses
                                                                        }
                                                                        intensity3d="dramatic"
                                                                        className="rounded-full"
                                                                        enableBlink
                                                                    />
                                                                </AvatarFallback>
                                                            </Avatar>
                                                            <div className="flex flex-col items-start">
                                                                <h3 className="text-sm leading-none font-semibold">
                                                                    {
                                                                        testimonial.name
                                                                    }
                                                                </h3>
                                                            </div>
                                                        </div>
                                                        <p className="mt-4 text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                                            {testimonial.text}
                                                        </p>
                                                    </div>
                                                ))}
                                            </div>
                                        ))}
                                    </div>
                                ))}

                                <div className="pointer-events-none absolute inset-y-0 left-0 hidden w-1/6 bg-linear-to-r from-[#FDFDFC] sm:block dark:from-[#0a0a0a]"></div>
                                <div className="pointer-events-none absolute inset-y-0 right-0 hidden w-1/6 bg-linear-to-l from-[#FDFDFC] sm:block dark:from-[#0a0a0a]"></div>
                            </div>
                        </div>
                    </section>

                    {subscriptionsEnabled &&
                        !hideAuthButtons &&
                        planEntries.length > 0 && (
                            <section
                                id="pricing"
                                className="px-4 py-12 sm:py-16 md:py-20"
                            >
                                <div className="mx-auto flex max-w-5xl flex-col items-center gap-8 sm:gap-12">
                                    <div className="flex flex-col items-center gap-4 text-center">
                                        <h2 className="text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                            {__('Simple, transparent pricing')}
                                        </h2>
                                        <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                            {__(
                                                'Choose the plan that works for you. No hidden fees.',
                                            )}
                                        </p>

                                        {hasMonthlyAndYearly && (
                                            <div className="mt-2 flex items-center rounded-full border border-[#e3e3e0] p-1 dark:border-[#3E3E3A]">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setBillingPeriod(
                                                            'month',
                                                        )
                                                    }
                                                    className={cn(
                                                        'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                                                        billingPeriod ===
                                                            'month'
                                                            ? 'bg-[#1b1b18] text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                                            : 'text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]',
                                                    )}
                                                >
                                                    {__('Monthly')}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setBillingPeriod('year')
                                                    }
                                                    className={cn(
                                                        'flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                                                        billingPeriod === 'year'
                                                            ? 'bg-[#1b1b18] text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                                            : 'text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]',
                                                    )}
                                                >
                                                    {__('Yearly')}
                                                    {yearlyDiscount !==
                                                        null && (
                                                        <span
                                                            className={cn(
                                                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                                                billingPeriod ===
                                                                    'year'
                                                                    ? 'bg-emerald-500/20 text-emerald-200 dark:bg-emerald-900/60 dark:text-emerald-300'
                                                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400',
                                                            )}
                                                        >
                                                            {__(
                                                                'Save :percent%',
                                                            ).replace(
                                                                ':percent',
                                                                String(
                                                                    yearlyDiscount,
                                                                ),
                                                            )}
                                                        </span>
                                                    )}
                                                </button>
                                            </div>
                                        )}
                                    </div>

                                    <div
                                        className={cn(
                                            'grid w-full gap-6',
                                            displayedPlanEntries.length + 1 ===
                                                1 && 'mx-auto max-w-md',
                                            displayedPlanEntries.length + 1 ===
                                                2 &&
                                                'mx-auto max-w-3xl grid-cols-1 sm:grid-cols-2',
                                            displayedPlanEntries.length + 1 >=
                                                3 &&
                                                'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
                                        )}
                                    >
                                        <FreePlanCard
                                            features={[
                                                ...planEntries[0][1].features,
                                            ]}
                                        />
                                        {displayedPlanEntries.map(
                                            ([key, plan]) => (
                                                <LandingPlanCard
                                                    key={key}
                                                    plan={plan}
                                                    isDefault={
                                                        key ===
                                                        pricing.defaultPlan
                                                    }
                                                    isBestValue={
                                                        key ===
                                                        pricing.bestValuePlan
                                                    }
                                                    promoEnabled={
                                                        pricing.promo.enabled
                                                    }
                                                    promoBadge={
                                                        pricing.promo.badge
                                                    }
                                                    currency={pricing.currency}
                                                    locale={locale}
                                                />
                                            ),
                                        )}
                                    </div>
                                </div>
                            </section>
                        )}

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto max-w-3xl">
                            <div className="mb-8 flex flex-col items-center gap-4 text-center sm:mb-12">
                                <h2 className="text-3xl leading-tight font-semibold sm:text-5xl sm:leading-tight">
                                    {__('Frequently Asked Questions')}
                                </h2>
                                <p className="text-md max-w-[600px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                    {__(
                                        'Everything you need to know before getting started.',
                                    )}
                                </p>
                            </div>

                            <div className="rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] px-6 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <FaqItem
                                    question={__(
                                        'How is my financial data kept private?',
                                    )}
                                    answer={__(
                                        "Your data is stored securely and never shared with third parties. We don't use your financial data for AI training, advertising, or any purpose other than providing you the service.",
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'Can I connect my bank accounts directly?',
                                    )}
                                    answer={__(
                                        'Yes. Bank connections use secure Open Banking and never require your bank credentials, so transactions can sync automatically. This is a Pro feature; free users can import everything, but only through the CSV/Excel importer.',
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'Can I export or delete my data?',
                                    )}
                                    answer={__(
                                        'Absolutely. You own your data. You can export all your financial data at any time, and you can permanently delete your account and all associated data whenever you want.',
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'What file formats are supported for import?',
                                    )}
                                    answer={__(
                                        'We support CSV and XLS files. Most banks allow you to export your transaction history in one of these formats. The import process automatically maps columns and categorizes transactions.',
                                    )}
                                />
                                <FaqItem
                                    question={__(
                                        'Is Whisper Money open source?',
                                    )}
                                    answer={__(
                                        'Yes! Whisper Money is fully open source. You can review the code, suggest improvements, or even self-host it. Transparency is a core part of our privacy commitment.',
                                    )}
                                />
                            </div>
                        </div>
                    </section>

                    <section className="px-4 py-12 sm:py-16 md:py-20">
                        <div className="mx-auto max-w-7xl">
                            <div className="flex flex-col items-center gap-6 px-6 py-12 text-center sm:px-12 sm:py-16 dark:border-[#3E3E3A] dark:bg-[#161615]">
                                <h2 className="max-w-[600px] text-3xl leading-tight font-semibold text-balance sm:text-4xl sm:leading-tight">
                                    {__(
                                        'Ready to take control of your finances?',
                                    )}
                                </h2>
                                <p className="max-w-[480px] text-balance text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                                    {__(
                                        'Start managing your money privately. No credit card required.',
                                    )}
                                </p>
                                <div className="flex flex-col gap-3 sm:flex-row">
                                    {hideAuthButtons ? (
                                        <WaitlistForm />
                                    ) : isMobile ? (
                                        <InstallAppButton />
                                    ) : (
                                        <Link href="/register">
                                            <Button className="h-12 cursor-pointer bg-gradient-to-t from-zinc-700 to-zinc-900 px-8 text-base text-white shadow-sm transition-all hover:from-zinc-800 hover:to-black hover:shadow-md dark:from-zinc-200 dark:to-zinc-300 dark:text-[#1C1C1A] hover:dark:from-zinc-50">
                                                {__('Get Started for Free')}
                                            </Button>
                                        </Link>
                                    )}
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="py-8 lg:mt-12 dark:border-[#3E3E3A]">
                    <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-4 px-6 text-sm text-[#706f6c] sm:flex-row lg:px-8 dark:text-[#A1A09A]">
                        <p>
                            © {new Date().getFullYear()}
                            {__(
                                'Whisper Money. All\n                            rights reserved.',
                            )}
                        </p>
                        <div className="flex gap-6">
                            <Link
                                href="/privacy"
                                className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                {__('Privacy Policy')}
                            </Link>
                            <Link
                                href="/terms"
                                className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            >
                                {__('Terms of Service')}
                            </Link>
                            {LANGUAGE_OPTIONS.filter(
                                (option) => option.code !== locale,
                            ).map((option) => (
                                <a
                                    key={option.code}
                                    href={`/?lang=${option.code}`}
                                    className="cursor-pointer hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                                >
                                    {option.label}
                                </a>
                            ))}
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
