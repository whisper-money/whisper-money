import { checkExistingTransactions } from '@/lib/full-import-api';
import { type BuiltImport } from '@/lib/full-import-plan';
import { type TransactionPayloadRow } from '@/types/full-import';
import { useEffect, useState } from 'react';

/** How many of the rows going into the user's own accounts are likely there already. */
export interface DuplicateEstimate {
    existing: number;
    /** By plan account key, for the accounts that have any. */
    byAccount: Record<string, number>;
}

/** Long enough for a quick back-and-forth through the steps to ask once. */
const SETTLE_MS = 300;

interface TargetGroup {
    /** The plan account key of each row, so a flag can be counted back. */
    keys: string[];
    rows: TransactionPayloadRow[];
}

/** The rows of the payload grouped by the existing account they go into. */
export function groupByTarget(
    transactions: TransactionPayloadRow[],
    targets: Record<string, string>,
): Map<string, TargetGroup> {
    const groups = new Map<string, TargetGroup>();

    for (const row of transactions) {
        const accountId = targets[row.account_key];

        if (!accountId) {
            continue;
        }

        const group = groups.get(accountId) ?? { keys: [], rows: [] };
        group.keys.push(row.account_key);
        group.rows.push(row);
        groups.set(accountId, group);
    }

    return groups;
}

async function estimate(
    groups: Map<string, TargetGroup>,
): Promise<DuplicateEstimate> {
    const result: DuplicateEstimate = { existing: 0, byAccount: {} };

    for (const [accountId, group] of groups) {
        const flags = await checkExistingTransactions(accountId, group.rows);

        flags.forEach((duplicate, index) => {
            if (duplicate) {
                const key = group.keys[index];
                result.existing++;
                result.byAccount[key] = (result.byAccount[key] ?? 0) + 1;
            }
        });
    }

    return result;
}

/**
 * Asks the server which of the rows going into the user's own accounts it
 * already holds, once per plan, so the review can say how much is really
 * new. An estimate and nothing more: it never blocks the import, and a failed
 * check simply leaves the review as it was.
 *
 * @param targets plan account key => the user's account its rows go into
 */
export function useDuplicateEstimate(
    built: BuiltImport | null,
    targets: Record<string, string>,
): { estimate: DuplicateEstimate | null; checking: boolean } {
    const [result, setResult] = useState<{
        built: BuiltImport;
        estimate: DuplicateEstimate | null;
    } | null>(null);
    const hasTargets = Object.keys(targets).length > 0;

    useEffect(() => {
        if (!built || !hasTargets) {
            return;
        }

        const groups = groupByTarget(built.transactions, targets);
        let active = true;

        const timer = window.setTimeout(() => {
            estimate(groups)
                .then(
                    (found) => active && setResult({ built, estimate: found }),
                )
                .catch(() => active && setResult({ built, estimate: null }));
        }, SETTLE_MS);

        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [built, targets, hasTargets]);

    const current = result?.built === built ? result : null;

    return {
        estimate: current?.estimate ?? null,
        checking: built !== null && hasTargets && current === null,
    };
}
