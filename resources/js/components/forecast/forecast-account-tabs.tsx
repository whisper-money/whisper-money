import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { trendLabel } from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type AccountType, formatAccountType } from '@/types/account';
import {
    type ForecastAccountTab,
    type ForecastHorizon,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import {
    CheckIcon,
    ChevronDownIcon,
    CreditCardIcon,
    SlidersHorizontalIcon,
} from 'lucide-react';

const GROUP_LABEL =
    'px-2.5 pt-2.5 pb-1 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase';

interface ForecastAccountTabsProps {
    accounts: ForecastAccountTab[];
    /** The account shown, or null for "All accounts". */
    selectedId: string | null;
    horizon: ForecastHorizon;
}

/**
 * The desktop account filter: a menu with "All accounts", then the accounts
 * the forecast covers (the ones it warns about, then the projection-only
 * ones), each with its type, trend and warning, and the way to choose the
 * accounts. Picking one shows it, as the URL says.
 */
export function ForecastAccountTabs({
    accounts,
    selectedId,
    horizon,
}: ForecastAccountTabsProps) {
    const editor = useForecastEditor();
    const { formatAmount } = useFormatAmount();
    const selected = accounts.find((account) => account.id === selectedId);
    const alertCount = accounts.filter((account) => account.hasAlert).length;
    const spending = accounts.filter((account) => !account.projectionOnly);
    const projectionOnly = accounts.filter((account) => account.projectionOnly);
    const show = (account: string) =>
        router.visit(forecastIndex({ query: { account, horizon } }).url, {
            preserveScroll: true,
        });
    const hint = (account: ForecastAccountTab): string =>
        [
            formatAccountType(account.type as AccountType),
            trendLabel(
                account.trend,
                (amount) =>
                    formatAmount(amount, account.currencyCode, {
                        fractionDigits: 0,
                    }),
                true,
            ),
            account.projectionOnly ? __('projection only') : null,
        ]
            .filter((part) => part !== null)
            .join(' · ');
    const option = (account: ForecastAccountTab) => (
        <AccountOption
            key={account.id}
            name={account.name}
            hint={hint(account)}
            selected={account.id === selectedId}
            hasAlert={account.hasAlert}
            onSelect={() => show(account.id)}
            dataTest={`forecast-tab-${account.id}`}
        />
    );

    return (
        <nav aria-label={__('Account')} className="flex">
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        className="h-10 min-w-[260px] justify-start gap-2.5 rounded-lg px-3"
                        data-test="forecast-account-filter"
                    >
                        <CreditCardIcon className="text-muted-foreground" />
                        <span className="flex-1 text-left font-medium">
                            {selected?.name ?? __('All accounts')}
                        </span>
                        {selected?.hasAlert && <AlertDot />}
                        {!selected && alertCount > 0 && (
                            <span
                                className="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive-foreground dark:bg-destructive/20"
                                data-test="forecast-account-filter-alerts"
                            >
                                {alertCount === 1
                                    ? __('1 warning')
                                    : __(':count warnings', {
                                          count: alertCount,
                                      })}
                            </span>
                        )}
                        <ChevronDownIcon className="text-muted-foreground" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    sideOffset={6}
                    className="flex w-[340px] flex-col rounded-[10px] p-1 shadow-[0_12px_32px_rgba(0,0,0,0.12)]"
                    data-test="forecast-account-menu"
                >
                    <AccountOption
                        name={__('All accounts')}
                        hint={allAccountsHint(accounts.length, alertCount)}
                        selected={selectedId === null}
                        hasAlert={false}
                        onSelect={() => show('all')}
                        dataTest="forecast-tab-all"
                    />
                    {spending.length > 0 && (
                        <DropdownMenuGroup>
                            <DropdownMenuLabel className={GROUP_LABEL}>
                                {__('Your accounts')}
                            </DropdownMenuLabel>
                            {spending.map(option)}
                        </DropdownMenuGroup>
                    )}
                    {projectionOnly.length > 0 && (
                        <DropdownMenuGroup>
                            <DropdownMenuLabel className={GROUP_LABEL}>
                                {__('Projection only')}
                            </DropdownMenuLabel>
                            {projectionOnly.map(option)}
                        </DropdownMenuGroup>
                    )}
                    {editor && (
                        <>
                            <DropdownMenuSeparator className="mx-0" />
                            <DropdownMenuItem
                                className="min-h-10 gap-2.5 rounded-md px-2.5 py-1.5 text-muted-foreground focus:text-muted-foreground"
                                onSelect={() =>
                                    editor.open({ kind: 'accounts' })
                                }
                                data-test="forecast-choose-accounts"
                            >
                                <SlidersHorizontalIcon />
                                {__(
                                    'Choose which accounts go into the forecast',
                                )}
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </nav>
    );
}

/** "5 accounts · 1 with a warning", or just the count when none warns. */
function allAccountsHint(accountCount: number, alertCount: number): string {
    const accountsPart =
        accountCount === 1
            ? __('1 account')
            : __(':count accounts', { count: accountCount });

    if (alertCount === 0) {
        return accountsPart;
    }

    const alertsPart =
        alertCount === 1
            ? __('1 with a warning')
            : __(':count with a warning', { count: alertCount });

    return `${accountsPart} · ${alertsPart}`;
}

function AlertDot() {
    return (
        <span
            role="img"
            aria-label={__('has a warning')}
            className="size-2 shrink-0 rounded-full bg-destructive"
        />
    );
}

function AccountOption({
    name,
    hint,
    selected,
    hasAlert,
    onSelect,
    dataTest,
}: {
    name: string;
    hint: string;
    selected: boolean;
    hasAlert: boolean;
    onSelect: () => void;
    dataTest: string;
}) {
    return (
        <DropdownMenuItem
            className={cn(
                'min-h-10 gap-2.5 rounded-md px-2.5 py-1.5 text-foreground focus:bg-muted',
                selected && 'bg-muted',
            )}
            aria-current={selected ? 'page' : undefined}
            onSelect={onSelect}
            data-test={dataTest}
        >
            <span className="flex size-4 shrink-0">
                {selected && (
                    <CheckIcon
                        className="text-foreground"
                        strokeWidth={2.25}
                        aria-hidden
                    />
                )}
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-px">
                <span className={cn('truncate', selected && 'font-semibold')}>
                    {name}
                </span>
                <span className="text-xs text-muted-foreground">{hint}</span>
            </span>
            {hasAlert && (
                <span className="flex items-center gap-1.5 text-xs text-destructive-foreground">
                    <AlertDot />
                    {__('Alert')}
                </span>
            )}
        </DropdownMenuItem>
    );
}
