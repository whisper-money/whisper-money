import { WizardScreen } from '@/components/full-import/full-import-layout';
import {
    ColumnSelect,
    DateFormatSelect,
} from '@/components/transactions/import-step-mapping';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCount } from '@/lib/full-import-format';
import { splitCategoryPath, unmappedHeaders } from '@/lib/full-import-profiles';
import {
    SINGLE_ACCOUNT_COLUMN,
    type FullImportMapping,
    type FullImportSource,
    type MappedColumn,
    type NormalizedFile,
} from '@/types/full-import';
import { type ColumnOption } from '@/types/import';
import { formatCurrency, toMinorUnits } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { type ReactNode } from 'react';

interface FieldSpec {
    field: MappedColumn;
    label: string;
    hint?: string;
}

const REQUIRED_FIELDS: FieldSpec[] = [
    { field: 'date', label: 'Date' },
    {
        field: 'amount',
        label: 'Amount',
        hint: 'Signed as in the file: a negative amount is an expense.',
    },
    { field: 'description', label: 'Description' },
];

const OPTIONAL_FIELDS: FieldSpec[] = [
    { field: 'notes', label: 'Notes' },
    { field: 'currency', label: 'Currency' },
    {
        field: 'iban',
        label: 'IBAN',
        hint: 'Saved on the account.',
    },
    {
        field: 'externalId',
        label: 'Transaction ID',
        hint: 'Upload the same file again and nothing is duplicated.',
    },
    {
        field: 'ignored',
        label: 'Ignored',
        hint: 'Rows marked TRUE are imported with a transfer category.',
    },
];

const PREVIEW_ROWS = 5;

interface StepColumnsProps {
    eyebrow: string;
    source: FullImportSource;
    onSourceChange: (source: FullImportSource) => void;
    headers: string[];
    columnOptions: ColumnOption[];
    mapping: FullImportMapping;
    onMappingChange: (mapping: FullImportMapping) => void;
    normalized: NormalizedFile;
    fallbackCurrency: string;
    locale: string;
    footer: ReactNode;
}

function sampleOf(columnOptions: ColumnOption[], column: string | null) {
    return (
        columnOptions.find((option) => option.value === column)?.examples[0] ??
        null
    );
}

/** One mapping row: what it is, which column feeds it, and what that looks like. */
function FieldRow({
    label,
    sample,
    hint,
    control,
    children,
}: {
    label: string;
    sample: string | number | null;
    hint?: ReactNode;
    control: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="grid grid-cols-1 gap-2 px-4 py-3.5 sm:px-5 md:grid-cols-[9rem_minmax(0,1fr)_minmax(0,1fr)] md:items-start md:gap-4">
            <div className="font-medium md:pt-2">{label}</div>
            <div className="flex flex-col gap-3">
                {control}
                {children}
            </div>
            <div className="flex flex-col gap-1 text-[13px] text-muted-foreground md:pt-2">
                {sample !== null && sample !== '' && (
                    <code className="w-fit max-w-full truncate rounded bg-muted px-1.5 py-0.5 text-foreground">
                        {String(sample)}
                    </code>
                )}
                {hint && <span>{hint}</span>}
            </div>
        </div>
    );
}

function GroupLabel({ children }: { children: ReactNode }) {
    return (
        <div className="bg-muted/50 px-4 py-2 text-xs font-medium tracking-wider text-muted-foreground uppercase sm:px-5">
            {children}
        </div>
    );
}

export function StepColumns({
    eyebrow,
    source,
    onSourceChange,
    headers,
    columnOptions,
    mapping,
    onMappingChange,
    normalized,
    fallbackCurrency,
    locale,
    footer,
}: StepColumnsProps) {
    const set = (patch: Partial<FullImportMapping>) =>
        onMappingChange({ ...mapping, ...patch });

    const select = (field: MappedColumn, optional: boolean) => (
        <ColumnSelect
            id={`full-import-${field}`}
            value={mapping[field]}
            placeholder={__('Select column')}
            optional={optional}
            columnOptions={columnOptions}
            onChange={(column) => set({ [field]: column || null })}
        />
    );

    const accounts = new Set(normalized.rows.map((row) => row.accountKey)).size;
    const balanceRows = normalized.rows.filter(
        (row) => row.balance !== null,
    ).length;
    const categorySample = String(
        sampleOf(columnOptions, mapping.category) ?? '',
    );
    const categoryPreview = splitCategoryPath(
        categorySample,
        mapping.categorySeparator,
    ).join(' › ');
    const notImported = unmappedHeaders(mapping, headers);

    return (
        <WizardScreen
            eyebrow={eyebrow}
            title={__('Check the columns')}
            description={
                source === 'banktrack'
                    ? __(
                          'Every piece of Whisper Money comes from a column of your file. It is already filled in for Banktrack: change only what does not fit.',
                      )
                    : __(
                          'Every piece of Whisper Money comes from a column of your file. Tell us which one is which; we remember it next time.',
                      )
            }
            footer={footer}
        >
            <div className="flex flex-wrap items-center gap-3">
                <Label htmlFor="full-import-format" className="font-medium">
                    {__('Format')}
                </Label>
                <Select
                    value={source}
                    onValueChange={(value) =>
                        onSourceChange(value as FullImportSource)
                    }
                >
                    <SelectTrigger id="full-import-format" className="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="banktrack">Banktrack</SelectItem>
                        <SelectItem value="generic">
                            {__('Another app or spreadsheet')}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <span className="text-[13px] text-muted-foreground">
                    {__('If you change something, we remember it next time.')}
                </span>
            </div>

            <div className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card">
                <GroupLabel>{__('Required')}</GroupLabel>
                {REQUIRED_FIELDS.map((spec) => (
                    <FieldRow
                        key={spec.field}
                        label={__(spec.label)}
                        sample={sampleOf(columnOptions, mapping[spec.field])}
                        hint={spec.hint && __(spec.hint)}
                        control={select(spec.field, false)}
                    >
                        {spec.field === 'date' && (
                            <DateFormatSelect
                                ariaLabel={__('Date format')}
                                value={mapping.dateFormat}
                                onChange={(dateFormat) => set({ dateFormat })}
                            />
                        )}
                    </FieldRow>
                ))}

                <FieldRow
                    label={__('Account')}
                    sample={sampleOf(columnOptions, mapping.account)}
                    hint={
                        mapping.account
                            ? __(
                                  'Every different value is an account: there are :count.',
                                  {
                                      count: accounts,
                                  },
                              )
                            : undefined
                    }
                    control={
                        <ColumnSelect
                            id="full-import-account"
                            value={mapping.account}
                            placeholder={__('Select column')}
                            columnOptions={columnOptions}
                            extraOptions={[
                                {
                                    value: SINGLE_ACCOUNT_COLUMN,
                                    label: __('All rows are one account'),
                                },
                            ]}
                            onChange={(column) =>
                                set({ account: column || null })
                            }
                        />
                    }
                >
                    {mapping.account &&
                        mapping.account !== SINGLE_ACCOUNT_COLUMN && (
                            <label className="flex items-start gap-2.5 text-sm">
                                <Checkbox
                                    checked={mapping.splitByAccountDetail}
                                    onCheckedChange={(checked) =>
                                        set({
                                            splitByAccountDetail:
                                                checked === true,
                                        })
                                    }
                                    className="mt-0.5"
                                />
                                <span className="flex flex-col gap-0.5">
                                    <span className="font-medium">
                                        {mapping.accountDetail
                                            ? __('Also split by «:column»', {
                                                  column: mapping.accountDetail,
                                              })
                                            : __(
                                                  'Also split by another column',
                                              )}
                                    </span>
                                    <span className="text-[13px] text-muted-foreground">
                                        {__(
                                            "Banktrack doesn't always fill it in, so one account could show up twice. Turn it on only if you have several accounts at the same bank.",
                                        )}
                                    </span>
                                </span>
                            </label>
                        )}
                    {mapping.splitByAccountDetail &&
                        source === 'generic' &&
                        select('accountDetail', true)}
                </FieldRow>

                <GroupLabel>{__('Optional')}</GroupLabel>

                <FieldRow
                    label={__('Category')}
                    sample={categorySample || null}
                    control={select('category', true)}
                >
                    {mapping.category && (
                        <div className="flex flex-wrap items-center gap-2.5">
                            <Label
                                htmlFor="full-import-separator"
                                className="text-[13px] text-muted-foreground"
                            >
                                {__('Subcategory separator')}
                            </Label>
                            <Input
                                id="full-import-separator"
                                value={mapping.categorySeparator}
                                maxLength={5}
                                className="h-8 w-16 text-center"
                                onChange={(event) =>
                                    set({
                                        categorySeparator: event.target.value,
                                    })
                                }
                            />
                            {categoryPreview && (
                                <span className="text-[13px] font-medium">
                                    {categoryPreview}
                                </span>
                            )}
                        </div>
                    )}
                </FieldRow>

                <FieldRow
                    label={__('Balance')}
                    sample={sampleOf(columnOptions, mapping.balance)}
                    hint={
                        mapping.balance
                            ? __(
                                  'Only in :count rows: we import the balance of the accounts that have it.',
                                  { count: formatCount(balanceRows, locale) },
                              )
                            : __('No column, no balances imported.')
                    }
                    control={select('balance', true)}
                />

                {OPTIONAL_FIELDS.map((spec) => (
                    <FieldRow
                        key={spec.field}
                        label={__(spec.label)}
                        sample={sampleOf(columnOptions, mapping[spec.field])}
                        hint={spec.hint && __(spec.hint)}
                        control={select(spec.field, true)}
                    />
                ))}

                {notImported.length > 0 && (
                    <div className="px-4 py-3.5 text-[13px] text-muted-foreground sm:px-5">
                        {__('Not imported: :columns.', {
                            columns: notImported.join(', '),
                        })}
                    </div>
                )}
            </div>

            <ColumnsPreview
                normalized={normalized}
                fallbackCurrency={fallbackCurrency}
                locale={locale}
            />
        </WizardScreen>
    );
}

function ColumnsPreview({
    normalized,
    fallbackCurrency,
    locale,
}: {
    normalized: NormalizedFile;
    fallbackCurrency: string;
    locale: string;
}) {
    const summary = [
        __(':count transactions', {
            count: formatCount(normalized.rows.length, locale),
        }),
        normalized.blankRows > 0 &&
            __(':count blank rows skipped', { count: normalized.blankRows }),
        normalized.unreadable.length > 0 &&
            __(":count rows can't be read", {
                count: normalized.unreadable.length,
            }),
    ].filter(Boolean);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">
                    {__('How they will look')}
                </h2>
                <span className="text-[13px] text-muted-foreground">
                    {summary.join(' · ')}
                </span>
            </div>
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[36rem] text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="px-3 py-2 font-medium">
                                {__('Date')}
                            </th>
                            <th className="px-3 py-2 font-medium">
                                {__('Account')}
                            </th>
                            <th className="px-3 py-2 font-medium">
                                {__('Description')}
                            </th>
                            <th className="px-3 py-2 font-medium">
                                {__('Category')}
                            </th>
                            <th className="px-3 py-2 text-right font-medium">
                                {__('Amount')}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {normalized.rows.slice(0, PREVIEW_ROWS).map((row) => {
                            const currency = row.currency ?? fallbackCurrency;

                            return (
                                <tr key={row.rowNumber}>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {formatDateMedium(row.date, locale)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.accountName}
                                    </td>
                                    <td className="max-w-56 truncate px-3 py-2">
                                        {row.description}
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {row.categoryPath.join(' › ') || '—'}
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                        {formatCurrency(
                                            toMinorUnits(row.amount, currency),
                                            currency,
                                            locale,
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
