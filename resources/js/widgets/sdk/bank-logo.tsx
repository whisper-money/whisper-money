import { BankLogo as AppBankLogo } from '@/components/bank-logo';
import { AccountTypeIcon } from '@/components/dashboard/account-type-icon';
import { cn } from '@/lib/utils';
import { Building2 } from 'lucide-react';
import { useWidgetSdk } from './context';
import { classNameOf, finiteNumber } from './props';

const DEFAULT_SIZE = 20;
const MIN_SIZE = 8;
const MAX_SIZE = 96;

/** Only logos we serve or a bank's https URL, never `data:` or `javascript:`. */
export function safeLogoUrl(url: string | null): string | null {
    return url !== null && /^(https:\/\/|\/(?![/\\]))/.test(url) ? url : null;
}

/**
 * The logo of one of the user's banks, by account id: widgets cannot write
 * `<img src>`. An account without a logo (or whose logo fails to load) shows
 * the icon of its type, and an id that is not one of the user's accounts a
 * generic bank.
 */
export function BankLogo({
    accountId,
    size,
    className,
}: {
    accountId: unknown;
    size?: unknown;
    className?: unknown;
}) {
    const { accounts } = useWidgetSdk();
    const account =
        typeof accountId === 'string' && Object.hasOwn(accounts, accountId)
            ? accounts[accountId]
            : null;
    const pixels = Math.min(
        Math.max(finiteNumber(size, DEFAULT_SIZE), MIN_SIZE),
        MAX_SIZE,
    );
    const box = { width: pixels, height: pixels };
    const classes = cn('inline-flex shrink-0', classNameOf(className));
    const fallback = account ? (
        <AccountTypeIcon type={account.type} className="size-full" />
    ) : (
        <Building2 className="size-full text-muted-foreground" />
    );

    return (
        <span
            data-slot="widget-bank-logo"
            className={classes}
            style={box}
            title={account?.bankName ?? undefined}
        >
            <AppBankLogo
                src={safeLogoUrl(account?.bankLogo ?? null)}
                name={account?.bankName ?? ''}
                className="size-full"
                fallback={fallback}
            />
        </span>
    );
}
