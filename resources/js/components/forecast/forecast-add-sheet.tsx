import { store } from '@/actions/App/Http/Controllers/Forecast/ForecastItemController';
import { type ForecastSheetProps } from '@/components/forecast/forecast-editor';
import {
    BeforeAfter,
    type BeforeAfterRow,
    Field,
    ForecastSheet,
    FrequencySelect,
    Note,
} from '@/components/forecast/forecast-sheet-parts';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { horizonEnd, monthKey } from '@/lib/forecast/calendar';
import { alertThreshold, withLine } from '@/lib/forecast/edits';
import { monthLongName } from '@/lib/forecast/format';
import { projectForecast } from '@/lib/forecast/project';
import {
    type ExpandableForecastItem,
    type ForecastFrequency,
    type Projection,
    type ProjectionInput,
} from '@/lib/forecast/types';
import { type Category } from '@/types/category';
import { type ForecastAccountTab } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { ArrowDownIcon } from 'lucide-react';
import { useState } from 'react';

export type AddType = 'expense' | 'income' | 'transfer';

/** What a sheet opened from somewhere comes filled with. */
export interface AddPrefill {
    type?: AddType;
    categoryId?: string | null;
    accountId?: string;
    destinationAccountId?: string | null;
    amount?: number;
    date?: string;
    frequency?: ForecastFrequency;
}

const OUTSIDE = 'outside';

/**
 * What repeats every month or so is already in its category's estimate:
 * a charge or an income added by hand happens once or once a year. Only a
 * transfer takes every repetition.
 */
const CATEGORY_FREQUENCIES: ForecastFrequency[] = ['once', 'yearly'];

/** The repetitions a type of line takes, or undefined for all of them. */
function frequenciesFor(type: AddType): ForecastFrequency[] | undefined {
    return type === 'transfer' ? undefined : CATEGORY_FREQUENCIES;
}

/** The repetition kept when the type changes: the same one when the new type takes it, else once. */
function keptFrequency(
    type: AddType,
    frequency: ForecastFrequency,
): ForecastFrequency {
    const allowed = frequenciesFor(type);

    return allowed === undefined || allowed.includes(frequency)
        ? frequency
        : 'once';
}

/** The id a line not stored yet goes by in the client engine. */
const NEW_LINE = 'new-line';

interface Draft {
    type: AddType;
    name: string;
    accountId: string;
    destination: string;
    categoryId: string | null;
    amount: number;
    date: string;
    frequency: ForecastFrequency;
    endsOn: string;
}

/** A transfer goes to another account in the same currency, or out of the app. */
function canReceive(
    accounts: ForecastAccountTab[],
    fromId: string,
    toId: string,
): boolean {
    const currency = (id: string) =>
        accounts.find((account) => account.id === id)?.currencyCode;

    return (
        toId === OUTSIDE ||
        (toId !== fromId && currency(toId) === currency(fromId))
    );
}

function initialDraft(
    prefill: AddPrefill | undefined,
    accountId: string,
    accounts: ForecastAccountTab[],
    today: string,
): Draft {
    const type = prefill?.type ?? 'expense';
    const source = prefill?.accountId ?? accountId;

    return {
        type,
        name: '',
        accountId: source,
        destination:
            prefill?.destinationAccountId ??
            accounts.find((account) => canReceive(accounts, source, account.id))
                ?.id ??
            OUTSIDE,
        categoryId: prefill?.categoryId ?? null,
        amount: prefill?.amount ?? 0,
        date: prefill?.date ?? today,
        frequency: keptFrequency(type, prefill?.frequency ?? 'once'),
        endsOn: '',
    };
}

/** The line the draft becomes, as the client engine expands it. */
function draftDefinition(draft: Draft): ExpandableForecastItem {
    const isTransfer = draft.type === 'transfer';

    return {
        id: NEW_LINE,
        accountId: draft.accountId,
        destinationAccountId:
            isTransfer && draft.destination !== OUTSIDE
                ? draft.destination
                : null,
        frequency: draft.frequency,
        anchorDate: draft.date,
        endsOn: draft.frequency === 'once' ? null : draft.endsOn || null,
        versions: [
            {
                effectiveFrom: draft.date,
                amount: draft.type === 'income' ? draft.amount : -draft.amount,
            },
        ],
    };
}

function monthOf(projection: Projection, accountId: string, month: string) {
    return projection.accounts
        .find((account) => account.accountId === accountId)
        ?.months.find((candidate) => candidate.month === month);
}

function totalOf(projection: Projection, month: string) {
    return projection.total.find((candidate) => candidate.month === month);
}

/** "Así queda abril": the balances the new line moves, before and after. */
function beforeAfterRows(
    draft: Draft,
    accounts: ForecastAccountTab[],
    before: Projection,
    after: Projection,
    month: string,
    currencyCode: string,
    input: ProjectionInput,
): BeforeAfterRow[] {
    const rows: BeforeAfterRow[] = [];
    const byId = new Map(accounts.map((account) => [account.id, account]));
    const threshold = (id: string) =>
        alertThreshold(
            byId.get(id)?.type ?? '',
            input.accounts.find((account) => account.id === id)
                ?.minimumBalance ?? null,
        );
    const minRow = (id: string) => {
        const old = monthOf(before, id, month);
        const next = monthOf(after, id, month);

        if (old && next) {
            rows.push({
                label: __(':account · min.', {
                    account: byId.get(id)?.name ?? '',
                }),
                before: old.min,
                after: next.min,
                currencyCode: byId.get(id)?.currencyCode ?? currencyCode,
                threshold: threshold(id),
            });
        }
    };

    if (draft.type !== 'transfer') {
        minRow(draft.accountId);
    } else {
        if (draft.destination !== OUTSIDE) {
            minRow(draft.destination);
        }

        const old = monthOf(before, draft.accountId, month);
        const next = monthOf(after, draft.accountId, month);

        if (old && next) {
            rows.push({
                label: __(':account · close', {
                    account: byId.get(draft.accountId)?.name ?? '',
                }),
                before: old.close,
                after: next.close,
                currencyCode:
                    byId.get(draft.accountId)?.currencyCode ?? currencyCode,
            });
        }
    }

    const oldTotal = totalOf(before, month);
    const newTotal = totalOf(after, month);

    if (oldTotal && newTotal) {
        rows.push({
            label: __('Total of your accounts'),
            before: oldTotal.close,
            after: newTotal.close,
            currencyCode,
        });
    }

    return rows;
}

function AccountSelect({
    id,
    value,
    onChange,
    accounts,
    label,
    withOutside = false,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    accounts: ForecastAccountTab[];
    label: (account: ForecastAccountTab) => string;
    withOutside?: boolean;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id} className="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {accounts.map((account) => (
                    <SelectItem key={account.id} value={account.id}>
                        {label(account)}
                    </SelectItem>
                ))}
                {withOutside && (
                    <SelectItem value={OUTSIDE}>
                        {__('Outside Whisper Money')}
                    </SelectItem>
                )}
            </SelectContent>
        </Select>
    );
}

/**
 * "Añadir previsión": a one-off or yearly charge or income the user knows
 * is coming (it adds on top of its category's estimate), or a transfer. A transfer goes from one account to another (or out of
 * the app), and the sheet shows what it does to both before it is added:
 * the receiving account's lowest point, the sending one's close, and the
 * total, which a transfer between two accounts leaves as it was.
 */
export function ForecastAddSheet({
    forecast,
    projectionInput,
    onClose,
    prefill,
}: ForecastSheetProps & { prefill?: AddPrefill }) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const { save, processing, errors } = useForecastSave();
    const categories = (usePage().props.categories as Category[]) ?? [];
    const accounts = forecast.accounts;
    const lastDay = horizonEnd(
        projectionInput.startDate,
        projectionInput.horizonMonths,
    );
    const [draft, setDraft] = useState(() =>
        initialDraft(prefill, forecast.account.id, accounts, forecast.today),
    );
    const change = (patch: Partial<Draft>) =>
        setDraft((current) => ({ ...current, ...patch }));

    const isTransfer = draft.type === 'transfer';
    const source = accounts.find((account) => account.id === draft.accountId);
    const currencyCode = source?.currencyCode ?? forecast.currencyCode;
    const month = monthKey(draft.date);
    const monthName = monthLongName(month, locale);
    const dateInRange = draft.date >= forecast.today && draft.date <= lastDay;
    const edited =
        draft.amount > 0 && dateInRange
            ? withLine(projectionInput, NEW_LINE, [draftDefinition(draft)])
            : projectionInput;
    const before = projectForecast(projectionInput);
    const after = projectForecast(edited);
    const format = (amount: number, currencyCode: string) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });
    const sourceLabel = (account: ForecastAccountTab) => {
        const projected = monthOf(before, account.id, month);

        return projected
            ? __(':account · :amount planned in :month', {
                  account: account.name,
                  amount: format(projected.close, account.currencyCode),
                  month: monthName,
              })
            : account.name;
    };
    const destinationLabel = (account: ForecastAccountTab) => {
        const projected = monthOf(before, account.id, month);

        return projected
            ? __(':account · :amount min. in :month', {
                  account: account.name,
                  amount: format(projected.min, account.currencyCode),
                  month: monthName,
              })
            : account.name;
    };
    const categoryChoices = categories.filter((category) =>
        draft.type === 'income'
            ? category.type === 'income'
            : category.type !== 'income',
    );

    const submit = () =>
        save(
            store(),
            {
                type: draft.type,
                name: draft.name,
                account_id: draft.accountId,
                destination_account_id:
                    isTransfer && draft.destination !== OUTSIDE
                        ? draft.destination
                        : null,
                destination_external:
                    isTransfer && draft.destination === OUTSIDE,
                category_id: isTransfer ? null : draft.categoryId,
                amount: draft.amount,
                date: draft.date,
                frequency: draft.frequency,
                ends_on:
                    draft.frequency === 'once' ? null : draft.endsOn || null,
            },
            { input: edited },
            onClose,
        );

    return (
        <ForecastSheet
            open
            onOpenChange={(open) => !open && onClose()}
            title={__('Add to the forecast')}
            testId="forecast-add-sheet"
            footer={
                <div className="ml-auto flex gap-2">
                    <Button variant="outline" onClick={onClose}>
                        {__('Cancel')}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={processing || draft.amount <= 0}
                        data-test="forecast-add-submit"
                    >
                        {isTransfer
                            ? __('Add transfer')
                            : __('Add to the forecast')}
                    </Button>
                </div>
            }
        >
            <ToggleGroup
                type="single"
                variant="outline"
                value={draft.type}
                onValueChange={(type) =>
                    type &&
                    change({
                        type: type as AddType,
                        frequency: keptFrequency(
                            type as AddType,
                            draft.frequency,
                        ),
                    })
                }
                aria-label={__('Type')}
                className="grid w-full grid-cols-3"
            >
                <ToggleGroupItem value="expense">
                    {__('Expense')}
                </ToggleGroupItem>
                <ToggleGroupItem value="income">{__('Inflow')}</ToggleGroupItem>
                <ToggleGroupItem value="transfer">
                    {__('Transfer')}
                </ToggleGroupItem>
            </ToggleGroup>

            {isTransfer ? (
                <div className="flex flex-col gap-2">
                    <Field
                        label={__('From')}
                        htmlFor="forecast-add-from"
                        error={errors.account_id}
                    >
                        <AccountSelect
                            id="forecast-add-from"
                            value={draft.accountId}
                            onChange={(accountId) =>
                                change({
                                    accountId,
                                    destination: canReceive(
                                        accounts,
                                        accountId,
                                        draft.destination,
                                    )
                                        ? draft.destination
                                        : OUTSIDE,
                                })
                            }
                            accounts={accounts}
                            label={sourceLabel}
                        />
                    </Field>
                    <ArrowDownIcon
                        aria-hidden="true"
                        className="mx-auto size-5 text-muted-foreground"
                    />
                    <Field
                        label={__('Towards')}
                        htmlFor="forecast-add-to"
                        error={errors.destination_account_id}
                    >
                        <AccountSelect
                            id="forecast-add-to"
                            value={draft.destination}
                            onChange={(destination) => change({ destination })}
                            accounts={accounts.filter((account) =>
                                canReceive(
                                    accounts,
                                    draft.accountId,
                                    account.id,
                                ),
                            )}
                            label={destinationLabel}
                            withOutside
                        />
                    </Field>
                </div>
            ) : (
                <>
                    <Field
                        label={__('Name')}
                        htmlFor="forecast-add-name"
                        hint={__(
                            'We match it with payments whose description contains it.',
                        )}
                        error={errors.name}
                    >
                        <Input
                            id="forecast-add-name"
                            value={draft.name}
                            onChange={(event) =>
                                change({ name: event.target.value })
                            }
                            placeholder={__('e.g. Car insurance')}
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field
                            label={__('Account')}
                            htmlFor="forecast-add-account"
                            error={errors.account_id}
                        >
                            <AccountSelect
                                id="forecast-add-account"
                                value={draft.accountId}
                                onChange={(accountId) => change({ accountId })}
                                accounts={accounts}
                                label={(account) => account.name}
                            />
                        </Field>
                        <Field
                            label={__('Category')}
                            error={errors.category_id}
                        >
                            <CategoryCombobox
                                value={draft.categoryId}
                                onValueChange={(categoryId) =>
                                    change({
                                        categoryId:
                                            categoryId === 'null'
                                                ? null
                                                : categoryId,
                                    })
                                }
                                categories={categoryChoices}
                                placeholder={__('Uncategorized')}
                                triggerClassName="w-full"
                            />
                        </Field>
                    </div>
                </>
            )}

            <div className="grid grid-cols-2 gap-3">
                <Field
                    label={__('Amount')}
                    htmlFor="forecast-add-amount"
                    error={errors.amount}
                >
                    <AmountInput
                        id="forecast-add-amount"
                        value={draft.amount}
                        onChange={(amount) =>
                            change({ amount: Math.abs(amount) })
                        }
                        currencyCode={currencyCode}
                        commitOnChange
                    />
                </Field>
                <Field
                    label={__('Date')}
                    htmlFor="forecast-add-date"
                    error={errors.date}
                >
                    <Input
                        id="forecast-add-date"
                        type="date"
                        min={forecast.today}
                        max={lastDay}
                        value={draft.date}
                        onChange={(event) =>
                            change({ date: event.target.value })
                        }
                    />
                </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <Field
                    label={__('Repetition')}
                    htmlFor="forecast-add-frequency"
                >
                    <FrequencySelect
                        id="forecast-add-frequency"
                        value={draft.frequency}
                        onChange={(frequency) => change({ frequency })}
                        only={frequenciesFor(draft.type)}
                    />
                </Field>
                {draft.frequency !== 'once' && (
                    <Field
                        label={__('Until (optional)')}
                        htmlFor="forecast-add-ends"
                        error={errors.ends_on}
                    >
                        <Input
                            id="forecast-add-ends"
                            type="date"
                            min={draft.date}
                            value={draft.endsOn}
                            onChange={(event) =>
                                change({ endsOn: event.target.value })
                            }
                        />
                    </Field>
                )}
            </div>

            {dateInRange && (
                <BeforeAfter
                    title={__('This is how :month looks', { month: monthName })}
                    rows={beforeAfterRows(
                        draft,
                        accounts,
                        before,
                        after,
                        month,
                        forecast.currencyCode,
                        projectionInput,
                    )}
                    note={
                        isTransfer && draft.destination !== OUTSIDE
                            ? __(
                                  'A transfer moves money between your accounts: it is neither income nor spend, so your total does not change.',
                              )
                            : undefined
                    }
                />
            )}

            {!isTransfer && (
                <Note>
                    {__(
                        "What repeats every month is already in its category's estimate: change that figure in your plan instead.",
                    )}
                </Note>
            )}

            {isTransfer && (
                <Note>
                    {__(
                        'Whisper Money does not move money. When you make the transfer at your bank, we will match it with this forecast alone.',
                    )}
                </Note>
            )}
        </ForecastSheet>
    );
}
