import { parseAmount, parseDate } from '@/lib/file-parser';
import {
    balanceOf,
    cellText,
    headerLookup,
    normalizeText,
    splitCategoryPath,
    unreadableReason,
    type NormalizeOptions,
    type RowReader,
} from '@/lib/full-import-profiles';
import {
    type FullImportMapping,
    type NormalizedRow,
    type UnreadableRow,
} from '@/types/full-import';
import { type ParsedRow } from '@/types/import';
import { getCurrencySymbol } from '@/utils/currency';
import { formatLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** What a Money Pro row is, from its transaction type. */
type MoneyProType = 'expense' | 'income' | 'transfer' | 'balance';

/**
 * Every transaction type Money Pro writes, by its normalised name, in each
 * language its export is known in. Another language is one more line each.
 */
const MONEY_PRO_TYPES: Record<string, MoneyProType> = {
    gasto: 'expense',
    ingreso: 'income',
    'transferencia de dinero': 'transfer',
    'ajuste de saldo': 'balance',
    'saldo de apertura': 'balance',
    expense: 'expense',
    income: 'income',
    'money transfer': 'transfer',
    'balance adjustment': 'balance',
    'opening balance': 'balance',
};

/** The columns the mapping has no field for, by their normalised names. */
const MONEY_PRO_EXTRA_COLUMNS = {
    type: 'tipo de transaccion',
    received: 'monto recibido',
    destination: 'cuenta de destino',
};

/**
 * The currency an amount cell is written in, as the file writes it: what is
 * left once the digits, separators and signs are gone ("8.400,00 €" is "€").
 */
export function currencySymbolOf(cell: string): string {
    return cell.replace(/[\d\s.,'+\-−()]/g, '');
}

function narrowSymbol(code: string): string | null {
    try {
        return (
            new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: code,
                currencyDisplay: 'narrowSymbol',
            })
                .formatToParts(0)
                .find((part) => part.type === 'currency')?.value ?? null
        );
    } catch {
        return null;
    }
}

/**
 * The ISO code a currency symbol most likely stands for. "$" is a dollar or
 * a peso: the user's own currency when it is one of them, the US dollar
 * otherwise. A symbol nothing writes that way stays unknown, and its rows
 * go to the account's currency.
 */
export function defaultSymbolCurrency(
    symbol: string,
    supportedCurrencies: readonly string[],
    userCurrency?: string,
): string | null {
    const code = symbol.toUpperCase();

    if (supportedCurrencies.includes(code)) {
        return code;
    }

    const candidates = supportedCurrencies.filter(
        (one) =>
            getCurrencySymbol(one) === symbol || narrowSymbol(one) === symbol,
    );

    if (userCurrency && candidates.includes(userCurrency)) {
        return userCurrency;
    }

    return (
        candidates.find((one) => getCurrencySymbol(one) === symbol) ??
        candidates[0] ??
        null
    );
}

/** A currency symbol of the file, how many amounts carry it and what it is read as. */
export interface CurrencySymbolUse {
    symbol: string;
    count: number;
    code: string | null;
}

type CurrencyOptions = Pick<
    NormalizeOptions,
    'supportedCurrencies' | 'userCurrency'
>;

/** The ISO code a symbol is read as: the user's pick, else its default. */
function symbolCurrency(
    symbol: string,
    mapping: FullImportMapping,
    options: CurrencyOptions,
): string | null {
    if (symbol === '') {
        return null;
    }

    return (
        mapping.currencySymbols[symbol] ??
        defaultSymbolCurrency(
            symbol,
            options.supportedCurrencies,
            options.userCurrency,
        )
    );
}

/**
 * Every currency symbol the amounts of a Money Pro file are written in, the
 * most used first, with the ISO code each one is read as.
 */
export function collectCurrencySymbols(
    rows: ParsedRow[],
    headers: string[],
    mapping: FullImportMapping,
    options: CurrencyOptions,
): CurrencySymbolUse[] {
    const received =
        headerLookup(headers).get(MONEY_PRO_EXTRA_COLUMNS.received) ?? null;
    const tally = new Map<string, number>();

    for (const row of rows) {
        for (const column of [mapping.amount, received]) {
            const symbol = currencySymbolOf(cellText(row, column));

            if (symbol !== '') {
                tally.set(symbol, (tally.get(symbol) ?? 0) + 1);
            }
        }
    }

    return [...tally.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([symbol, count]) => ({
            symbol,
            count,
            code: symbolCurrency(symbol, mapping, options),
        }));
}

/** A Money Pro account is a name the user gave it, never a bank's. */
function account(
    name: string,
): Pick<NormalizedRow, 'accountKey' | 'accountName' | 'accountBank'> {
    return {
        accountKey: normalizeText(name),
        accountName: name || __('No account'),
        accountBank: '',
    };
}

/**
 * The reader of a Money Pro export. Its amounts are always positive: the
 * transaction type gives the sign. A transfer is one row for two movements,
 * out of `Cuenta` and into `Cuenta de destino`, each in its own currency. An
 * opening balance or an adjustment imports no transaction, only the balance
 * the account was left with, which `Saldo` carries on every row.
 */
export function moneyProReader(headers: string[]): RowReader {
    const byName = headerLookup(headers);
    const column = (name: string) => byName.get(name) ?? null;
    const typeColumn = column(MONEY_PRO_EXTRA_COLUMNS.type);
    const receivedColumn = column(MONEY_PRO_EXTRA_COLUMNS.received);
    const destinationColumn = column(MONEY_PRO_EXTRA_COLUMNS.destination);
    // Worked out once per symbol, and again whenever the user picks another
    // currency for one, which is a new mapping.
    const symbols = new Map<string, string | null>();
    let symbolsMapping: FullImportMapping | null = null;

    return (row, rowNumber, mapping, options) => {
        if (mapping !== symbolsMapping) {
            symbols.clear();
            symbolsMapping = mapping;
        }

        const currencyOf = (cell: string) => {
            const symbol = currencySymbolOf(cell);

            if (!symbols.has(symbol)) {
                symbols.set(symbol, symbolCurrency(symbol, mapping, options));
            }

            return symbols.get(symbol) ?? null;
        };
        const typeText = cellText(row, typeColumn);
        const type = MONEY_PRO_TYPES[normalizeText(typeText)];

        if (!type) {
            return [
                {
                    rowNumber,
                    reason: typeText
                        ? __('Unknown type «:type»', { type: typeText })
                        : __('No type'),
                },
            ];
        }

        const date = mapping.date
            ? parseDate(
                  row[mapping.date] as string | number,
                  mapping.dateFormat,
              )
            : null;
        const amountCell = cellText(row, mapping.amount);
        const amount = parseAmount(amountCell);
        const categoryPath = splitCategoryPath(
            cellText(row, mapping.category),
            mapping.categorySeparator,
        );
        const description =
            cellText(row, mapping.description) ||
            categoryPath.at(-1) ||
            typeText;
        const reason = unreadableReason(
            row,
            mapping,
            date,
            amount,
            description,
        );

        if (reason !== null || !date || amount === null) {
            return [{ rowNumber, reason: reason ?? __('No date') }];
        }

        const notes = cellText(row, mapping.notes);
        const base = {
            rowNumber,
            date: formatLocalDate(date),
            description,
            notes: notes && notes !== description ? notes : null,
            iban: null,
            externalId: null,
            ignored: false,
        };
        const source = {
            ...base,
            ...account(cellText(row, mapping.account)),
            currency: currencyOf(amountCell),
            balance: balanceOf(row, mapping),
        };
        const size = Math.abs(amount);

        if (type === 'balance') {
            return source.balance === null
                ? []
                : [
                      {
                          ...source,
                          kind: 'balance',
                          amount: 0,
                          categoryPath: [],
                      },
                  ];
        }

        if (type !== 'transfer') {
            return [
                {
                    ...source,
                    kind: 'movement',
                    amount: type === 'expense' ? -size : size,
                    categoryPath,
                },
            ];
        }

        return transferLegs(source, size, {
            destination: cellText(row, destinationColumn),
            receivedCell: cellText(row, receivedColumn),
            currencyOf,
        });
    };
}

/** Both movements of a transfer: out of the account, into the destination. */
function transferLegs(
    source: Omit<NormalizedRow, 'kind' | 'amount' | 'categoryPath'>,
    size: number,
    target: {
        destination: string;
        receivedCell: string;
        currencyOf: (cell: string) => string | null;
    },
): (NormalizedRow | UnreadableRow)[] {
    if (target.destination === '') {
        return [
            {
                rowNumber: source.rowNumber,
                reason: __('No destination account'),
            },
        ];
    }

    const received = parseAmount(target.receivedCell);

    return [
        { ...source, kind: 'transfer', amount: -size, categoryPath: [] },
        {
            ...source,
            ...account(target.destination),
            kind: 'transfer',
            amount: received === null ? size : Math.abs(received),
            categoryPath: [],
            currency:
                received === null
                    ? source.currency
                    : target.currencyOf(target.receivedCell),
            balance: null,
        },
    ];
}
