import ConvertedAmountsController from '@/actions/App/Http/Controllers/Api/ConvertedAmountsController';
import {
    type AmountInUserCurrency,
    type DatedAmount,
} from '@/lib/transaction-day-groups';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Mirrors `ConvertedAmountsRequest::MAX_ITEMS`. */
const MAX_AMOUNTS_PER_REQUEST = 500;

interface ConversionItem {
    from: string;
    amount: number;
    date: string;
}

function conversionKey({ from, amount, date }: ConversionItem): string {
    return `${from.toUpperCase()}|${date}|${amount}`;
}

function toConversionItem(transaction: DatedAmount): ConversionItem {
    return {
        from: transaction.currency_code,
        amount: transaction.amount,
        date: transaction.transaction_date,
    };
}

/**
 * Every listed transaction's amount in the user's currency, each converted at
 * its own date as every other total in the app is.
 *
 * Rows already in the user's currency never leave the browser. The others are
 * asked for together, one request for all the rows not seen yet, and kept: an
 * edited row asks again only because its amount, currency or date changed.
 */
export function useAmountsInUserCurrency(
    transactions: DatedAmount[],
): AmountInUserCurrency {
    const userCurrency = usePage<SharedData>().props.auth.user.currency_code;
    const [converted, setConverted] = useState<Map<string, number | null>>(
        () => new Map(),
    );
    const requested = useRef(new Set<string>());

    const isInUserCurrency = useCallback(
        (currencyCode: string) =>
            currencyCode.toUpperCase() === userCurrency.toUpperCase(),
        [userCurrency],
    );

    useEffect(() => {
        const pending = new Map<string, ConversionItem>();

        for (const transaction of transactions) {
            const item = toConversionItem(transaction);
            const key = conversionKey(item);

            if (!isInUserCurrency(item.from) && !requested.current.has(key)) {
                pending.set(key, item);
            }
        }

        const entries = [...pending.entries()];

        for (
            let start = 0;
            start < entries.length;
            start += MAX_AMOUNTS_PER_REQUEST
        ) {
            const chunk = entries.slice(start, start + MAX_AMOUNTS_PER_REQUEST);
            chunk.forEach(([key]) => requested.current.add(key));

            axios
                .post<{ amounts: (number | null)[] }>(
                    ConvertedAmountsController.url(),
                    { items: chunk.map(([, item]) => item) },
                )
                .then(({ data }) => data.amounts)
                .catch(() => {
                    // Offline or a server error: a total is better missing than
                    // wrong for now, and these rows are asked for again the
                    // next time the list changes.
                    chunk.forEach(([key]) => requested.current.delete(key));

                    return chunk.map(() => null);
                })
                .then((amounts) =>
                    setConverted((previous) => {
                        const next = new Map(previous);
                        chunk.forEach(([key], index) =>
                            next.set(key, amounts[index] ?? null),
                        );

                        return next;
                    }),
                );
        }
    }, [transactions, isInUserCurrency]);

    return useCallback(
        (transaction: DatedAmount) => {
            if (isInUserCurrency(transaction.currency_code)) {
                return transaction.amount;
            }

            return converted.get(conversionKey(toConversionItem(transaction)));
        },
        [converted, isInUserCurrency],
    );
}
